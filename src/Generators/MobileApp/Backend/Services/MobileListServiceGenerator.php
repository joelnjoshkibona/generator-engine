<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileListServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/list_service');

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

            $lines[] = "                \$q->orWhere('{$name}', 'LIKE', \"%{\$search}%\")";
        }

        if (empty($lines)) {
            return '                // no searchable fields';
        }

        return implode("\n", $lines);
    }
}
