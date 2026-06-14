<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

/**
 * Generates per-module i18n locale files (en.json + sw.json).
 *
 * Output path: {frontendModulePath}/locales/en.json (and sw.json)
 *
 * The namespace key in each file matches the [[moduleRoute]] stub placeholder
 * (Str::kebab of the module name), so generated Vue pages resolve translations
 * automatically via import.meta.glob in the app's i18n.ts setup.
 *
 * Swahili values are seeded with working defaults; a human translator can
 * refine them without touching any generated PHP or TypeScript code.
 */
class FrontendLocaleGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        $singular = Str::singular($this->moduleName);
        $route    = Str::kebab($this->moduleName); // matches [[moduleRoute]]

        $enKeys = [
            'title'            => $this->moduleName,
            'singular'         => $singular,
            'create_btn'       => "Create {$singular}",
            'edit_btn'         => "Edit {$singular}",
            'delete_btn'       => "Delete {$singular}",
            'details_title'    => "{$singular} Details",
            'created_success'  => "{$singular} created successfully",
            'updated_success'  => "{$singular} updated successfully",
            'deleted_success'  => "{$singular} deleted successfully",
            'restored_success' => "{$singular} restored successfully",
            'created_error'    => "Failed to create {$singular}",
            'updated_error'    => "Failed to update {$singular}",
            'restored_error'   => "Failed to restore {$singular}",
            'failed_load'      => "Failed to load {$singular} data. Please refresh the page.",
        ];

        $swKeys = [
            'title'            => $this->moduleName,
            'singular'         => $singular,
            'create_btn'       => "Unda {$singular}",
            'edit_btn'         => "Hariri {$singular}",
            'delete_btn'       => "Futa {$singular}",
            'details_title'    => "Maelezo ya {$singular}",
            'created_success'  => "{$singular} imeundwa mafanikio",
            'updated_success'  => "{$singular} imesasishwa mafanikio",
            'deleted_success'  => "{$singular} imefutwa mafanikio",
            'restored_success' => "{$singular} imerejeshwa mafanikio",
            'created_error'    => "Imeshindwa kuunda {$singular}",
            'updated_error'    => "Imeshindwa kusasisha {$singular}",
            'restored_error'   => "Imeshindwa kurudisha {$singular}",
            'failed_load'      => "Imeshindwa kupakia data ya {$singular}. Tafadhali onyesha upya ukurasa.",
        ];

        // Add column title keys for each list field so generated t('module.col_*') calls resolve
        $listFields = $this->config['features']['frontend']['list']['fields'] ?? [];
        foreach ($listFields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';
            if (empty($key)) {
                continue;
            }
            $cleanKey = preg_replace(['/_id$/', '/_at$/'], '', $key);
            $label = $field['title'] ?? $field['label'] ?? ucwords(str_replace('_', ' ', $cleanKey));
            $enKeys["col_{$key}"] = $label;
            $swKeys["col_{$key}"] = $label; // Human translator can refine the Swahili value
        }

        $localesDir = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . '/locales';

        if (!is_dir($localesDir)) {
            mkdir($localesDir, 0755, true);
        }

        $enPath = $localesDir . '/en.json';
        $swPath = $localesDir . '/sw.json';

        $wrote = false;

        if ($this->force || !file_exists($enPath)) {
            file_put_contents($enPath, json_encode(
                [$route => $enKeys],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ) . "\n");
            $wrote = true;
        }

        if ($this->force || !file_exists($swPath)) {
            file_put_contents($swPath, json_encode(
                [$route => $swKeys],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ) . "\n");
            $wrote = true;
        }

        return $wrote;
    }
}
