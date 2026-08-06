<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Helpers\BulkActionConfigNormalizer;
use Illuminate\Support\Str;

class BulkActionServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $bulkActions = BulkActionConfigNormalizer::normalizeAll(
            $this->config['features']['backend']['list']['bulk_actions'] ?? []
        );
        if (empty($bulkActions)) {
            return true;
        }

        $allWritten = true;
        foreach ($bulkActions as $action) {
            $allWritten = $this->generateActionService($action) && $allWritten;
        }
        return $allWritten;
    }

    private function generateActionService(array $action): bool
    {
        $actionKey    = $action['key'] ?? '';
        $statusTarget = $action['status_target'] ?? null;
        $actionPascal = Str::studly($actionKey);
        $content      = $this->getTemplateContent('Features/list/bulk_action_service', 'backend');

        $actionBody = $this->buildActionBody($actionKey, $actionPascal, $statusTarget);

        $content = $this->replacePlaceholders($content, [
            '[[ActionPascal]]' => $actionPascal,
            '[[actionKey]]'    => $actionKey,
            '[[actionBody]]'   => $actionBody,
        ]);

        $serviceName = $this->moduleName . $actionPascal . 'Service';
        $filePath    = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    private function buildActionBody(string $actionKey, string $actionPascal, ?string $statusTarget): string
    {
        $module = $this->moduleName;

        if (!empty($statusTarget)) {
            // Verbatim, not Str::snake()'d: ModelGenerator::generateConstants()
            // emits `public const {$name} = {$value};` using the `constants`
            // config's own key exactly as written, with zero case
            // transformation. A prior strtoupper(Str::snake($statusTarget))
            // here silently mangled an already-correct, docs-documented,
            // ALL_CAPS status_target (e.g. 'RECEIVED') into 'R_E_C_E_I_V_E_D'
            // -- Str::snake() inserts an underscore between every adjacent
            // uppercase pair when it finds no lowercase letter to anchor a
            // word boundary on -- generating a reference to a PHP constant
            // that could never exist. Found via BulkActionServiceGeneratorTest
            // (generator-engine, 2026-08-06), which pinned the exact output
            // docs/examples/actions.md already documented as correct.
            $constName = $statusTarget;
            return <<<PHP
\$model = {$module}Model::where('uuid', \$params['uuid'])->first();
        if (!\$model) {
            throw new \Exception('{$module} not found');
        }

        \$model->update(['status_id' => {$module}Model::{$constName}]);

        return Helpers::success(\$model->fresh(), '{$module} {$actionKey} applied successfully');
PHP;
        }

        return <<<PHP
\$uuid = \$params['uuid'] ?? null;
        \$record = {$module}Model::where('uuid', \$uuid)->firstOrFail();
        // TODO: implement {$actionKey} for {$module}
        return Helpers::success(\$record->fresh(), '{$module} {$actionKey} applied successfully');
PHP;
    }
}
