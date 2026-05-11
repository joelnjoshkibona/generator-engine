<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class CompositeGenerator extends BaseUxGenerator
{
    public function generate(): void
    {
        foreach ($this->blueprint['composites'] ?? [] as $moduleName => $composite) {
            $group = $this->getGroupForModule($moduleName);
            if (!$group) continue;

            $this->generateBackendService($moduleName, $group, $composite);
            $this->generateFrontendPage($moduleName, $group, $composite);
        }
    }

    private function generateBackendService(string $moduleName, string $group, array $composite): void
    {
        $stub = $this->loadStub('composite-service.stub');

        $sections = $composite['sections'] ?? [];
        $parentSection = $sections[0]['name'] ?? 'details';
        $childSectionStubs = '';
        foreach (array_slice($sections, 1) as $section) {
            if ($section['type'] === 'repeater') {
                $childSectionStubs .= "        // 2. Create {$section['name']} records\n";
                $childSectionStubs .= "        // foreach (\$data['{$section['name']}'] ?? [] as \$item) {\n";
                $childSectionStubs .= "        //     \$parent->{$section['name']}()->create(\$item);\n";
                $childSectionStubs .= "        // }\n\n";
            }
        }

        $content = str_replace(
            ['[[namespace]]', '[[ModuleName]]', '[[parentSection]]', '[[childSectionStubs]]', '[[Label]]'],
            [$this->getModuleNamespace($moduleName, $group), $moduleName, $parentSection, $childSectionStubs, $composite['label']],
            $stub
        );

        $path = $this->getModuleBackendPath($moduleName, $group) . "/Services/{$moduleName}CompositeCreateService.php";
        $this->writeFile($path, $content);
    }

    private function generateFrontendPage(string $moduleName, string $group, array $composite): void
    {
        $stub = $this->loadStub('composite-page.stub');

        $sections = $composite['sections'] ?? [];
        $firstSection = $sections[0]['name'] ?? 'details';

        $sectionDataRefs = '';
        foreach ($sections as $section) {
            if ($section['type'] === 'repeater') {
                $sectionDataRefs .= "const {$section['name']}Data = ref<Record<string, any>[]>([{}])\n";
            } else {
                $sectionDataRefs .= "const {$section['name']}Data = ref<Record<string, any>>({})\n";
            }
        }

        $sectionsJs = array_map(fn($s) => sprintf(
            "    { name: '%s', label: '%s', type: '%s', optional: %s }",
            $s['name'],
            ucwords(str_replace('_', ' ', $s['name'])),
            $s['type'],
            ($s['optional'] ?? false) ? 'true' : 'false'
        ), $sections);
        $sectionsConfig = "[\n" . implode(",\n", $sectionsJs) . "\n]";

        $payloadLines = array_map(fn($s) => "            {$s['name']}: {$s['name']}Data.value,", $sections);
        $sectionPayload = implode("\n", $payloadLines);

        $imports = [];
        foreach ($sections as $section) {
            if (!isset($section['module'])) continue;
            [$sectionGroup, $sectionModule] = explode('/', $section['module']) + ['', ''];
            if (!$sectionModule) continue;
            $sectionGroupLower = strtolower($sectionGroup);
            $componentName = "{$sectionModule}CreateForm";
            $importPath = "@/pages/modules/{$sectionGroupLower}/{$sectionModule}/Components/{$componentName}.vue";
            $imports[$componentName] = "import {$componentName} from '{$importPath}'";
        }
        $sectionImports = implode("\n", array_values($imports));

        $panels = '';
        foreach ($sections as $section) {
            $panels .= "            <div v-show=\"activeSection === '{$section['name']}'\" class=\"space-y-4\">\n";
            if (isset($section['module'])) {
                [, $sectionModule] = explode('/', $section['module']) + ['', ''];
                $componentName = "{$sectionModule}CreateForm";
                if ($section['type'] === 'repeater') {
                    $panels .= "                <div v-for=\"(row, idx) in {$section['name']}Data\" :key=\"idx\" class=\"relative border rounded-lg p-4\">\n";
                    $panels .= "                    <button type=\"button\" @click=\"{$section['name']}Data.splice(idx, 1)\"\n";
                    $panels .= "                        class=\"absolute top-2 right-2 text-xs text-destructive hover:underline\">Remove</button>\n";
                    $panels .= "                    <{$componentName} :inline=\"true\" v-model:data=\"{$section['name']}Data[idx]\" />\n";
                    $panels .= "                </div>\n";
                    $panels .= "                <button type=\"button\" @click=\"{$section['name']}Data.push({})\"\n";
                    $panels .= "                    class=\"text-sm text-primary hover:underline\">+ Add {$section['name']}</button>\n";
                } else {
                    $panels .= "                <{$componentName} :inline=\"true\" v-model:data=\"{$section['name']}Data\" />\n";
                }
            } else {
                $panels .= "                <!-- TODO: implement {$section['name']} ({$section['type']}) panel -->\n";
            }
            $panels .= "            </div>\n";
        }

        $apiEndpoint = $this->studlyToKebab($moduleName) . '/composite/create';
        $content = str_replace(
            ['[[firstSection]]', '[[sectionDataRefs]]', '[[sectionsConfig]]', '[[sectionPayload]]', '[[sectionPanels]]', '[[sectionImports]]', '[[apiEndpoint]]', '[[Label]]', '[[ModuleName]]'],
            [$firstSection, $sectionDataRefs, $sectionsConfig, $sectionPayload, $panels, $sectionImports, $apiEndpoint, $composite['label'], $moduleName],
            $stub
        );

        // Overwrite the plain CreatePage — composite IS the create page for this module
        $path = $this->getModuleFrontendPath($moduleName, $group) . "/{$moduleName}CreatePage.vue";
        $this->writeFile($path, $content, overwrite: true);
    }
}
