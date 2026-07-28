<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Illuminate\Support\Str;

class ActionComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return false;
    }

    public function generateAction(string $actionKey, array $action): bool
    {
        if (empty($action['hasUI'])) {
            return false;
        }

        $uiType = $action['uiType'] ?? 'modal';

        $actionName = Str::studly($action['name'] ?? $actionKey);
        $actionLabel = $action['label'] ?? $actionName;
        $actionRoute = Str::kebab($action['name'] ?? $actionKey);
        $moduleRoute = Str::kebab($this->moduleName);

        $replacements = [
            '[[ActionName]]' => $actionName,
            '[[ActionLabel]]' => $actionLabel,
            '[[actionRoute]]' => "/{$moduleRoute}/{$actionRoute}",
            '[[actionCancelLink]]' => "/{$moduleRoute}/list",
            '[[actionEndpointExpr]]' => $this->buildEndpointExpression($action, $moduleRoute, $actionRoute),
        ];

        // The Form is ALWAYS generated, whatever uiType says. It owns the
        // fields and the submit; the container is what uiType selects. Emitting
        // a separate Modal.vue and Page.vue meant the hand-written fields lived
        // in whichever file the current uiType happened to produce, so flipping
        // modal <-> page silently orphaned them and started from a blank stub.
        // This mirrors the CRUD convention: one {Module}CreateForm.vue with a
        // `modal` prop, rendered either inside an AppDialog or by a page shell.
        $formWritten = $this->writeFile(
            "{$this->modulePath}/Components/{$this->moduleName}{$actionName}Form.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/form', 'frontend'), $replacements)
        );

        if ($uiType !== 'page') {
            return $formWritten;
        }

        // Page container: a thin shell around the same Form.
        $pageWritten = $this->writeFile(
            "{$this->modulePath}/{$this->moduleName}{$actionName}Page.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/page', 'frontend'), $replacements)
        );

        return $formWritten || $pageWritten;
    }

    /**
     * Build the JS template-literal the component POSTs to.
     *
     * MUST mirror RoutesGenerator::generateActionRoutes(): the component used to
     * hard-code `/{module}/{action}/create`, a path the backend never
     * registers, so the emitted UI could not have worked even once it was
     * wired up. Laravel's `{param}` placeholders become `${props.param}` so the
     * uuid in the URL is the record the modal was opened for.
     */
    protected function buildEndpointExpression(array $action, string $moduleRoute, string $actionRoute): string
    {
        $operations = $action['operations'] ?? [];
        $path = '';

        foreach (['create', 'edit', 'view', 'delete', 'list'] as $op) {
            if (!empty($operations[$op]['enabled'])) {
                $path = $operations[$op]['endpoint']['path'] ?? '';
                break;
            }
        }

        if ($path === '') {
            $urlParams = $action['urlParams'] ?? [];
            $paramsPath = $urlParams === []
                ? ''
                : '/' . implode('/', array_map(static fn ($p): string => '{' . $p . '}', $urlParams));
            $path = "/{$moduleRoute}{$paramsPath}/{$actionRoute}";
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return preg_replace('/\{(\w+)\}/', '\${props.$1}', $path);
    }
}
