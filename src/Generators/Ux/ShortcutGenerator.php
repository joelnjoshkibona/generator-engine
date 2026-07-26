<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PatchesRegions;

class ShortcutGenerator extends BaseUxGenerator
{
    use PatchesRegions;

    public function generate(): void
    {
        foreach ($this->blueprint['shortcuts'] ?? [] as $moduleName => $shortcuts) {
            $group = $this->getGroupForModule($moduleName);
            if (!$group) continue;
            $this->generateShortcutsComponent($moduleName, $group, $shortcuts);
            $this->generateMobileShortcutsComponent($moduleName, $group, $shortcuts);
        }
    }

    private function generateShortcutsComponent(string $moduleName, string $group, array $shortcuts): void
    {
        $stub = $this->loadStub('shortcuts.stub');

        $items = array_map(function ($s) {
            $wizardPart = isset($s['wizard']) ? ", wizard: '{$s['wizard']}'" : '';
            $targetPart = isset($s['target']) ? ", target: '{$s['target']}'" : '';
            $routeName = isset($s['target']) ? $this->targetToRouteName($s['target']) : '';
            $routePart = $routeName ? ", routeName: '{$routeName}'" : '';
            $prefillJson = json_encode((object)($s['prefill'] ?? []));
            return "    { label: '{$s['label']}', icon: '{$s['icon']}'{$wizardPart}{$targetPart}{$routePart}, prefill: {$prefillJson} }";
        }, $shortcuts);

        $shortcutsConfig = "[\n" . implode(",\n", $items) . "\n]";
        $content = str_replace('[[shortcutsConfig]]', $shortcutsConfig, $stub);

        $path = $this->getModuleFrontendPath($moduleName, $group) . "/{$moduleName}Shortcuts.vue";
        $this->writeFile($path, $content);
        $this->patchDetailsLayout($moduleName, $group);
    }

    private function targetToRouteName(string $target): string
    {
        $module = explode('/', $target)[1] ?? $target;
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
        return $kebab . '-create';
    }

    private function patchDetailsLayout(string $moduleName, string $group): void
    {
        $layoutPath = $this->getModuleFrontendPath($moduleName, $group) . "/{$moduleName}DetailsLayout.vue";
        if (!file_exists($layoutPath)) return;

        $importLine = "import {$moduleName}Shortcuts from './{$moduleName}Shortcuts.vue'";
        $componentTag = "\t\t\t\t<{$moduleName}Shortcuts :record=\"record\" class=\"mb-4\" />";

        // Try region-based patching first (generated files with region markers)
        $importPatched  = $this->patchRegion($layoutPath, 'shortcut-import', $importLine);
        $contentPatched = $this->patchRegion($layoutPath, 'shortcuts', $componentTag);

        if ($importPatched || $contentPatched) {
            $this->created[] = $layoutPath . ' (shortcuts region patched)';
            return;
        }

        // Fallback: string-anchor patching for files without region markers
        $content = file_get_contents($layoutPath);
        if (str_contains($content, "{$moduleName}Shortcuts")) return;

        $importAnchor = "import MainLayout from '@/layouts/MainLayout.vue'";
        if (str_contains($content, $importAnchor)) {
            $content = str_replace(
                $importAnchor,
                "{$importAnchor}\nimport {$moduleName}Shortcuts from './{$moduleName}Shortcuts.vue'",
                $content
            );
        }

        $contentAnchor = '<!-- Main Content Area -->';
        if (str_contains($content, $contentAnchor)) {
            $content = str_replace(
                $contentAnchor,
                "<{$moduleName}Shortcuts :record=\"record\" class=\"mb-4\" />\n\n\t\t\t\t<!-- Main Content Area -->",
                $content
            );
        }

        if (!str_contains($content, $importAnchor) && !str_contains($content, $contentAnchor)) {
            // Neither anchor matched: the file is unchanged, so writing it back would
            // just be a no-op that falsely reports the shortcut as wired up. Report it
            // as skipped instead — matches BaseUxGenerator::writeFile()'s convention of
            // recording best-effort, non-fatal misses in $this->skipped rather than
            // throwing, since a hand-edited layout file is expected, not exceptional.
            $this->skipped[] = $layoutPath . ' (shortcuts not patched: no matching anchor found)';
            return;
        }

        file_put_contents($layoutPath, $content);
        $this->created[] = $layoutPath . ' (shortcuts patched)';
    }

    private function generateMobileShortcutsComponent(string $moduleName, string $group, array $shortcuts): void
    {
        $stub = $this->loadMobileStub('shortcuts.stub');

        $items = array_map(function ($s) {
            $wizardPart = isset($s['wizard']) ? ", wizard: '{$s['wizard']}'" : '';
            $targetPart = isset($s['target']) ? ", target: '{$s['target']}'" : '';
            $routeName = isset($s['target']) ? $this->targetToRouteName($s['target']) : '';
            $routePart = $routeName ? ", routeName: '{$routeName}'" : '';
            $prefillJson = json_encode((object)($s['prefill'] ?? []));
            return "\t{ label: '{$s['label']}', icon: '{$s['icon']}'{$wizardPart}{$targetPart}{$routePart}, prefill: {$prefillJson} }";
        }, $shortcuts);

        $shortcutsConfig = "[\n" . implode(",\n", $items) . "\n]";
        $content = str_replace('[[shortcutsConfig]]', $shortcutsConfig, $stub);

        $path = $this->getModuleMobileAppPath($moduleName, $group) . "/{$moduleName}Shortcuts.vue";
        $this->writeFile($path, $content);
        $this->patchMobileDetailsLayout($moduleName, $group);
    }

    private function patchMobileDetailsLayout(string $moduleName, string $group): void
    {
        $layoutPath = $this->getModuleMobileAppPath($moduleName, $group) . "/{$moduleName}DetailsLayout.vue";
        if (!file_exists($layoutPath)) return;

        $importLine = "import {$moduleName}Shortcuts from './{$moduleName}Shortcuts.vue'";
        $componentTag = "\t\t\t\t<{$moduleName}Shortcuts :record=\"record\" class=\"mb-4\" />";

        // Try region-based patching first
        $importPatched  = $this->patchRegion($layoutPath, 'shortcut-import', $importLine);
        $contentPatched = $this->patchRegion($layoutPath, 'shortcuts', $componentTag);

        if ($importPatched || $contentPatched) {
            $this->created[] = $layoutPath . ' (shortcuts region patched)';
            return;
        }

        // Fallback: string-anchor patching for files without region markers
        $content = file_get_contents($layoutPath);
        if (str_contains($content, "{$moduleName}Shortcuts")) return;

        $importAnchor = "import MainLayout from '@/layouts/MainLayout.vue'";
        if (str_contains($content, $importAnchor)) {
            $content = str_replace(
                $importAnchor,
                "{$importAnchor}\nimport {$moduleName}Shortcuts from './{$moduleName}Shortcuts.vue'",
                $content
            );
        }

        $contentAnchor = '<!-- Main Content Area -->';
        if (str_contains($content, $contentAnchor)) {
            $content = str_replace(
                $contentAnchor,
                "<{$moduleName}Shortcuts :record=\"record\" class=\"mb-4\" />\n\n\t\t\t\t<!-- Main Content Area -->",
                $content
            );
        }

        if (!str_contains($content, $importAnchor) && !str_contains($content, $contentAnchor)) {
            // See patchDetailsLayout() above for rationale: no anchor matched, so
            // report this as skipped rather than silently rewriting an unchanged file.
            $this->skipped[] = $layoutPath . ' (shortcuts not patched: no matching anchor found)';
            return;
        }

        file_put_contents($layoutPath, $content);
        $this->created[] = $layoutPath . ' (shortcuts patched)';
    }
}
