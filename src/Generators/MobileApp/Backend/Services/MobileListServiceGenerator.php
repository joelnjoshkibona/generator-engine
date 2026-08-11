<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileListServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/list');

        $replacements = [
            '[[moduleGroup]]'       => $this->moduleGroup,
            '[[moduleName]]'        => $this->moduleName,
            '[[searchableFields]]'  => $this->buildSearchableFields(),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}ListService.php";

        return $this->writeFile($filePath, $content);
    }

    private function buildSearchableFields(): string
    {
        $columns = $this->config['columns'] ?? [];
        $lines   = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = $column['name'] ?? '';
            $type = strtolower($column['type'] ?? '');

            if ($name === '' || !in_array($type, ['string', 'varchar', 'text', 'longtext'], true)) {
                continue;
            }

            $lines[] = "orWhere('{$name}', 'LIKE', \"%{\$search}%\")";
        }

        if (empty($lines)) {
            return '                // no searchable fields';
        }

        // Chain every fragment onto one $q-> call rather than joining bare
        // statements with only newlines between them (which isn't valid PHP
        // without either -> chaining or a ; after each call).
        return "                \$q->" . implode("\n                    ->", $lines) . ';';
    }
}
