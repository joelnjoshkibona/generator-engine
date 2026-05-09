<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class InnerStyleServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        // This generator should be called via generateCustomFeature()
        return false;
    }

    public function generateCustomFeature(string $featureKey, array $customFeature): bool
    {
        $content = $this->getTemplateContent("Features/custom/service", 'backend');

        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $relatedModule = $customFeature['relatedModule'] ?? null;
        
        // Handle relatedModule as object or string
        $relatedModuleName = '';

        if ($relatedModule) {
            if (is_array($relatedModule)) {
                $relatedModuleName = $relatedModule['name'] ?? '';
            } else {
                $relatedModuleName = $relatedModule;
            }
        }

        // Resolve the full namespace path for the related module (e.g. "System\Inventory").
        // The `group` field in relatedModule config is the sub-group name, not the module_type.
        // PathManager::resolveBackendModuleNamespace() does the authoritative DB lookup.
        $relatedModuleGroupPath = 'Core';
        if (!empty($relatedModuleName)) {
            $fullNs = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($relatedModuleName);
            $relatedModuleGroupPath = str_replace(
                ['App\\Project\\Modules\\', "\\{$relatedModuleName}"],
                '',
                $fullNs
            );
        }

        // Get parent context configuration
        $filterKey = $customFeature['filterKey'] ?? 'parent_id';
        $parentIdField = $customFeature['parentIdField'] ?? 'id';

        $content = $this->replacePlaceholders($content, [
            '[[FeatureName]]' => $featureName,
            '[[featureName]]' => strtolower($featureName),
            '[[RelatedModuleName]]' => $relatedModuleName,
            '[[RelatedModuleGroup]]' => $relatedModuleGroupPath,
            '[[relatedModule]]' => strtolower($relatedModuleName),
            '[[filterKey]]' => $filterKey,
            '[[parentIdField]]' => $parentIdField,
        ]);
        
        $serviceFileName = $this->moduleName . $featureName . 'Service.php';
        $filePath = "{$this->modulePath}/Services/{$serviceFileName}";
        
        return $this->writeFile($filePath, $content);
    }
}

