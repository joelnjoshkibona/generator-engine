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
            'connection' => $this->config['connection'] ?? null,
            'generated_at' => date('Y-m-d H:i:s'),
            'generator_version' => '1.0.0',
        ];
        $this->config['meta_data'] = $configData;

        // Ensure version is set for new modules
        if (!isset($this->config['version'])) {
            $this->config['version'] = '1.0.0';
        }

        // `_introspection` (e.g. the global FK graph) is scaffold-time-only
        // bookkeeping, injected by the caller purely so PathManager/relation
        // resolution can see it *during this run*. Nothing ever reads it back
        // from a persisted module.json (ModuleScaffolder::mergePersistedFields()
        // only carries forward delegations/actions/processors/constants/
        // menu_config/morphs/mobile_app.mode). Persisting it anyway means every
        // module.json balloons by the size of the *entire* database's (or, on a
        // shared DB server, every co-hosted project's) FK graph regardless of
        // the module's own column count, and leaks unrelated schema metadata
        // into a file that is normally committed to source control. Strip any
        // leading-underscore transient key before writing to disk.
        $persistedConfig = array_filter(
            $this->config,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );

        // `delegations`, `actions`, `constants` and `json_rules` are KEYED MAPS (docs/module-config.md shows them as
        // `{}`), but an empty PHP array encodes as `[]`. So every fresh module.json opened with `"delegations": []`,
        // and anyone following that empty scaffold as their template wrote a flat array and got integer keys instead
        // of delegation names. An empty map is written as `{}`, so the empty and populated states share one shape.
        // json_decode(..., true) reads `{}` back as [], so nothing that reads module.json changes.
        foreach (['delegations', 'actions', 'constants', 'json_rules'] as $mapKey) {
            if (array_key_exists($mapKey, $persistedConfig) && $persistedConfig[$mapKey] === []) {
                $persistedConfig[$mapKey] = new \stdClass();
            }
        }

        $filePath = rtrim($this->modulePath, '/') . '/module.json';
        return $this->writeFile($filePath, json_encode($persistedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
