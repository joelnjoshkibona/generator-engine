<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class ViewServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['view'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }
        
        $content = $this->getTemplateContent('Features/view/service', 'backend');
        
        $replacements = [
            '[[eagerLoadRelationships]]' => $this->generateEagerLoadRelationships('view'),
            '[[inlineItemsLoad]]'        => $this->generateInlineItemsLoad(),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'ViewService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }

    private function generateInlineItemsLoad(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        foreach ($inlineItems as $item) {
            $key        = $item['key'];
            $parentFk   = $item['parent_fk'];
            $childNs    = $this->buildChildNamespace($item['child_group'], $item['child_module']);
            $modelClass = "\\{$childNs}\\{$item['child_module']}Model";
            $lines[]    = "\$data['{$key}'] = {$modelClass}::where('{$parentFk}', \$model->id)->get()->toArray();";
        }

        return implode("\n        ", $lines);
    }
}

