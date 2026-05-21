<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class WizardGenerator extends BaseUxGenerator
{
    public function generate(): void
    {
        $wizards = $this->blueprint['wizards'] ?? [];
        foreach ($wizards as $wizardName => $wizard) {
            $this->generateBackendService($wizardName, $wizard);
            $this->generateFrontendPage($wizardName, $wizard);
            $this->generateMobileWizardPage($wizardName, $wizard);
        }
        if (!empty($wizards)) {
            $this->generateRoutesFile($wizards);
            $this->generateMobileRoutesFile($wizards);
        }
    }

    private function getPrimaryModule(array $wizard): ?array
    {
        $trigger = $wizard['triggers_action'] ?? null;
        if ($trigger) {
            $parts = explode('/', $trigger['module']);
            return ['group' => $parts[0], 'module' => $parts[1]];
        }
        $firstStep = $wizard['steps'][0] ?? null;
        if ($firstStep) {
            $parts = explode('/', $firstStep['module']);
            return ['group' => $parts[0], 'module' => $parts[1]];
        }
        return null;
    }

    private function generateBackendService(string $wizardName, array $wizard): void
    {
        $stub = $this->loadStub('wizard-service.stub');
        $steps = $wizard['steps'] ?? [];

        $stepCases = '';
        foreach ($steps as $step) {
            $methodName = 'step' . $step['step'];
            $stepCases .= "                {$step['step']} => \$this->{$methodName}(\$data, \$params),\n";
        }

        $stepMethods = '';
        foreach ($steps as $step) {
            $methodName = 'step' . $step['step'];
            $stepMethods .= "    protected function {$methodName}(array \$data, array \$params = []): array\n    {\n";
            $stepMethods .= "        // Validate step {$step['step']}: {$step['label']} ({$step['module']})\n";
            $stepMethods .= "        return Helpers::success(\$data, 'Step {$step['step']} validated');\n";
            $stepMethods .= "    }\n\n";
        }

        $primary = $this->getPrimaryModule($wizard);
        if ($primary) {
            $backendPath = $this->getModuleBackendPath($primary['module'], $primary['group']);
            $namespace = $this->getModuleNamespace($primary['module'], $primary['group']);
        } else {
            $backendPath = PathManager::getBackendBasePath() . '/app/Project/Modules/Wizards';
            $namespace = 'App\\Project\\Modules\\Wizards';
        }

        $content = str_replace(
            ['[[namespace]]', '[[WizardName]]', '[[stepCases]]', '[[stepMethods]]', '[[Label]]'],
            [$namespace, $wizardName, $stepCases, $stepMethods, $wizard['label']],
            $stub
        );

        $path = "{$backendPath}/Services/{$wizardName}WizardService.php";
        $this->writeFile($path, $content);
    }

    private function generateFrontendPage(string $wizardName, array $wizard): void
    {
        $stub = $this->loadStub('wizard-page.stub');
        $steps = $wizard['steps'] ?? [];

        $stepsJs = array_map(fn($s) => sprintf(
            "    { step: %d, name: '%s', label: '%s', module: '%s', type: '%s' }",
            $s['step'], $s['name'], $s['label'], $s['module'], $s['type']
        ), $steps);
        $stepsConfig = "[\n" . implode(",\n", $stepsJs) . "\n]";

        $formTypes = ['form', 'repeater', 'select_or_create'];
        $imports = [];
        foreach ($steps as $step) {
            if (!in_array($step['type'], $formTypes)) continue;
            if (!isset($step['module'])) continue;
            [$stepGroup, $stepModule] = explode('/', $step['module']) + ['', ''];
            if (!$stepModule) continue;
            $stepGroupLower = strtolower($stepGroup);
            $componentName = "{$stepModule}CreateForm";
            $importPath = "@/pages/modules/{$stepGroupLower}/{$stepModule}/Components/{$componentName}.vue";
            $imports[$componentName] = "import {$componentName} from '{$importPath}'";
        }
        $stepImports = implode("\n", array_values($imports));

        $panels = '';
        foreach ($steps as $step) {
            $panels .= "                <div v-show=\"currentStep === {$step['step']}\">\n";
            if (in_array($step['type'], $formTypes) && isset($step['module'])) {
                [, $stepModule] = explode('/', $step['module']) + ['', ''];
                $componentName = "{$stepModule}CreateForm";
                if ($step['type'] === 'repeater') {
                    $panels .= "                    <div v-for=\"(row, idx) in stepData['step_{$step['step']}']\" :key=\"idx\" class=\"relative border rounded-lg p-4 mb-3\">\n";
                    $panels .= "                        <button type=\"button\" @click=\"stepData['step_{$step['step']}'].splice(idx, 1)\"\n";
                    $panels .= "                            class=\"absolute top-2 right-2 text-xs text-destructive hover:underline\">Remove</button>\n";
                    $panels .= "                        <{$componentName} :inline=\"true\" v-model:data=\"stepData['step_{$step['step']}'][idx]\" />\n";
                    $panels .= "                    </div>\n";
                    $panels .= "                    <button type=\"button\" @click=\"stepData['step_{$step['step']}'].push({})\"\n";
                    $panels .= "                        class=\"text-sm text-primary hover:underline\">+ Add row</button>\n";
                } else {
                    $panels .= "                    <{$componentName} :inline=\"true\" v-model:data=\"stepData['step_{$step['step']}']\" />\n";
                }
            } else {
                $panels .= "                    <!-- {$step['type']} step: {$step['name']} ({$step['module']}) — implement custom UI here -->\n";
            }
            $panels .= "                </div>\n";
        }

        $primary = $this->getPrimaryModule($wizard);
        $primaryModule = $primary ? $primary['module'] : 'Home';
        $kebab = $this->studlyToKebab($wizardName);

        $content = str_replace(
            ['[[stepsConfig]]', '[[stepImports]]', '[[stepPanels]]', '[[wizard-name]]', '[[Label]]', '[[PrimaryModule]]'],
            [$stepsConfig, $stepImports, $panels, $kebab, $wizard['label'], $primaryModule],
            $stub
        );

        $wizardsDir = PathManager::getFrontendBasePath() . '/src/pages/wizards';
        if (!is_dir($wizardsDir)) mkdir($wizardsDir, 0755, true);
        $path = "{$wizardsDir}/{$wizardName}WizardPage.vue";
        $this->writeFile($path, $content);
    }

    private function generateRoutesFile(array $wizards): void
    {
        $routes = [];
        foreach ($wizards as $wizardName => $wizard) {
            $kebab = $this->studlyToKebab($wizardName);
            $routes[] = "    {\n        path: '/wizards/{$kebab}',\n        name: '{$wizardName}',\n        component: () => import('./{$wizardName}WizardPage.vue'),\n        meta: { requiresAuth: true }\n    }";
        }
        $routesArray = "[\n" . implode(",\n", $routes) . "\n]";
        $content = "import type { RouteRecordRaw } from 'vue-router'\n\nexport const WizardRoutes: RouteRecordRaw[] = {$routesArray}\n";

        $wizardsDir = PathManager::getFrontendBasePath() . '/src/pages/wizards';
        if (!is_dir($wizardsDir)) mkdir($wizardsDir, 0755, true);
        $path = "{$wizardsDir}/routes.ts";
        $this->writeFile($path, $content, overwrite: true);
    }

    private function generateMobileWizardPage(string $wizardName, array $wizard): void
    {
        $stub = $this->loadMobileStub('wizard-page.stub');
        $steps = $wizard['steps'] ?? [];

        $stepsJs = array_map(fn($s) => sprintf(
            "\t{ step: %d, name: '%s', label: '%s', module: '%s', type: '%s' }",
            $s['step'], $s['name'], $s['label'], $s['module'], $s['type']
        ), $steps);
        $stepsConfig = "[\n" . implode(",\n", $stepsJs) . "\n]";

        $formTypes = ['form', 'repeater', 'select_or_create'];
        $imports = [];
        foreach ($steps as $step) {
            if (!in_array($step['type'], $formTypes)) continue;
            if (!isset($step['module'])) continue;
            [$stepGroup, $stepModule] = explode('/', $step['module']) + ['', ''];
            if (!$stepModule) continue;
            $stepGroupLower = strtolower($stepGroup);
            $componentName = "{$stepModule}CreateForm";
            $importPath = "@/pages/modules/{$stepGroupLower}/{$stepModule}/Components/{$componentName}.vue";
            $imports[$componentName] = "import {$componentName} from '{$importPath}'";
        }
        $stepImports = implode("\n", array_values($imports));

        $panels = '';
        foreach ($steps as $step) {
            $panels .= "\t\t\t\t<div v-show=\"currentStep === {$step['step']}\">\n";
            if (in_array($step['type'], $formTypes) && isset($step['module'])) {
                [, $stepModule] = explode('/', $step['module']) + ['', ''];
                $componentName = "{$stepModule}CreateForm";
                if ($step['type'] === 'repeater') {
                    $panels .= "\t\t\t\t\t<div v-for=\"(row, idx) in stepData['step_{$step['step']}']\" :key=\"idx\" class=\"relative border rounded-lg p-3 mb-2\">\n";
                    $panels .= "\t\t\t\t\t\t<button type=\"button\" @click=\"stepData['step_{$step['step']}'].splice(idx, 1)\"\n";
                    $panels .= "\t\t\t\t\t\t\tclass=\"absolute top-2 right-2 text-xs text-destructive\">Remove</button>\n";
                    $panels .= "\t\t\t\t\t\t<{$componentName} :inline=\"true\" v-model:data=\"stepData['step_{$step['step']}'][idx]\" />\n";
                    $panels .= "\t\t\t\t\t</div>\n";
                    $panels .= "\t\t\t\t\t<button type=\"button\" @click=\"stepData['step_{$step['step']}'].push({})\"\n";
                    $panels .= "\t\t\t\t\t\tclass=\"text-sm text-primary\">+ Add row</button>\n";
                } else {
                    $panels .= "\t\t\t\t\t<{$componentName} :inline=\"true\" v-model:data=\"stepData['step_{$step['step']}']\" />\n";
                }
            } else {
                $panels .= "\t\t\t\t\t<!-- {$step['type']} step: {$step['name']} ({$step['module']}) — implement custom UI here -->\n";
            }
            $panels .= "\t\t\t\t</div>\n";
        }

        $primary = $this->getPrimaryModule($wizard);
        $primaryModule = $primary ? $primary['module'] : 'Home';
        $kebab = $this->studlyToKebab($wizardName);

        $content = str_replace(
            ['[[stepsConfig]]', '[[stepImports]]', '[[stepPanels]]', '[[wizard-name]]', '[[Label]]', '[[PrimaryModule]]'],
            [$stepsConfig, $stepImports, $panels, $kebab, $wizard['label'], $primaryModule],
            $stub
        );

        $wizardsDir = PathManager::getMobileAppBasePath() . '/resources/js/src/pages/wizards';
        if (!is_dir($wizardsDir)) mkdir($wizardsDir, 0755, true);
        $path = "{$wizardsDir}/{$wizardName}WizardPage.vue";
        $this->writeFile($path, $content);
    }

    private function generateMobileRoutesFile(array $wizards): void
    {
        $routes = [];
        foreach ($wizards as $wizardName => $wizard) {
            $kebab = $this->studlyToKebab($wizardName);
            $routes[] = "    {\n        path: '/wizards/{$kebab}',\n        name: '{$wizardName}',\n        component: () => import('./{$wizardName}WizardPage.vue'),\n        meta: { requiresAuth: true }\n    }";
        }
        $routesArray = "[\n" . implode(",\n", $routes) . "\n]";
        $content = "import type { RouteRecordRaw } from 'vue-router'\n\nexport const WizardRoutes: RouteRecordRaw[] = {$routesArray}\n";

        $wizardsDir = PathManager::getMobileAppBasePath() . '/resources/js/src/pages/wizards';
        if (!is_dir($wizardsDir)) mkdir($wizardsDir, 0755, true);
        $path = "{$wizardsDir}/routes.ts";
        $this->writeFile($path, $content, overwrite: true);
    }
}
