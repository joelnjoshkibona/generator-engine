<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Illuminate\Support\Str;

class BulkActionServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $bulkActions = $this->config['features']['backend']['list']['bulk_actions'] ?? [];
        if (empty($bulkActions)) {
            return true;
        }

        $allWritten = true;
        foreach ($bulkActions as $action) {
            $key = $action['key'] ?? '';
            if (empty($key)) {
                continue;
            }
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
            $constName = strtoupper(Str::snake($statusTarget));
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
