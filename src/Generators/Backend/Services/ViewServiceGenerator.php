<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

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
            // withTrashed() only compiles when the model actually `use`s
            // SoftDeletes — calling it unconditionally threw a
            // BadMethodCallException for every module without
            // has_soft_deletes, since Eloquent's __callStatic() has no such
            // method to forward to. ModuleConfigContract::hasSoftDeletes()
            // is the single sanctioned place to read this fact (see its own
            // docblock) rather than re-deriving it here.
            '[[withTrashedCall]]'        => ModuleConfigContract::hasSoftDeletes($this->config) ? 'withTrashed()->' : '',
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

