<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

class EditSplashServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        // Splash is opt-in: only generate when constants are declared in the module config.
        if (empty($this->config['constants'])) {
            return false;
        }

        // `editSplash: {}` (no splashData) is a valid declaration -- "the route exists, nothing to preload" -- and
        // RoutesGenerator, ControllerGenerator and PhpUnitTestGenerator all treat it as enabled (isset). This
        // gate used empty(), so an empty declaration got the route and a controller method calling a service
        // class that was never written.
        $backendConfig = $this->config['features']['backend']['editSplash'] ?? null;
        if ($backendConfig === null || $backendConfig === false) {
            return false; // Feature not enabled
        }
        
        $content = $this->getTemplateContent('Features/editSplash/service', 'backend');
        
        $replacements = [
            '[[splashData]]' => $this->generateSplashData('editSplash'),
        ];
        
        // Add imports for splash services
        $splashData = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
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
        
        $serviceName = $this->moduleName . 'EditSplashService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }
}

