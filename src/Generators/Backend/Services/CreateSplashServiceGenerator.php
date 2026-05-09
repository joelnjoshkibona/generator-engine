<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class CreateSplashServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        // Splash is opt-in: only generate when constants are declared in the module config.
        if (empty($this->config['constants'])) {
            return false;
        }

        $backendConfig = $this->config['features']['backend']['createSplash'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }
        
        $content = $this->getTemplateContent('Features/createSplash/service', 'backend');
        
        $replacements = [
            '[[splashData]]' => $this->generateSplashData('createSplash'),
        ];
        
        // Add imports for splash services
        $splashData = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
        $imports = [];
        foreach ($splashData as $source) {
            if (($source['type'] ?? 'model') === 'model' && !empty($source['module'])) {
                $module = $source['module'];
                // Resolve the full namespace from the DB (module_type + group).
                // Do NOT use $source['moduleGroup'] as the top-level segment —
                // it's the sub-group (e.g. "Accounting"), not the module_type (e.g. "System").
                $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
                $imports[] = "use {$ns}\\{$module}Model;";
            }
        }
        
        if (!empty($imports)) {
            $importsStr = implode("\n", $imports);
            $content = str_replace('use App\Project\_Src\Helpers;', "use App\Project\_Src\Helpers;\n{$importsStr}", $content);
        }
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'CreateSplashService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }
}

