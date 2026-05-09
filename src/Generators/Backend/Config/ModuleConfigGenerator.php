<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Config;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class ModuleConfigGenerator extends BaseGenerator
{
    protected array $config;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->config = $config;
    }

    public function generate(): bool
    {
        // Prepare config with metadata

        $configData = [
            'name' => $this->moduleName,
            'namespace' => $this->getNamespace(),
            'path' => $this->modulePath,
            'route' => '',
            'generated_at' => date('Y-m-d H:i:s'),
            'generator_version' => '1.0.0',
        ];
        $this->config['meta_data'] = $configData;
        
        // Ensure version is set for new modules
        if (!isset($this->config['version'])) {
            $this->config['version'] = '1.0.0';
        }

        $filePath = rtrim($this->modulePath, '/') . '/module.json';
        return $this->writeFile($filePath, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
