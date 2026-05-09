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
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'ViewService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }
}

