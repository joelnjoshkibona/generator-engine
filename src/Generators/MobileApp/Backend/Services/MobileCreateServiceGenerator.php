<?php

namespace Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\BaseMobileBackendGenerator;

class MobileCreateServiceGenerator extends BaseMobileBackendGenerator
{
    public function generate(): bool
    {
        $content = $this->loadStub('services/create_service');

        $replacements = [
            '[[moduleGroup]]'         => $this->moduleGroup,
            '[[moduleName]]'          => $this->moduleName,
            '[[validationRules]]'     => $this->buildValidationRules(),
            '[[validationMessages]]'  => '',
            '[[beforeCreate]]'        => '',
            '[[afterCreate]]'         => '',
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/Services/{$this->moduleName}CreateService.php";

        return $this->writeFile($filePath, $content);
    }

    private function buildValidationRules(): string
    {
        $columns = $this->config['columns'] ?? [];
        $lines   = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name     = $column['name'] ?? '';
            $required = ($column['required'] ?? false) === true;
            $rules    = $column['rules'] ?? null;

            if ($name === '') {
                continue;
            }

            if ($rules !== null) {
                $lines[] = "            '{$name}' => '{$rules}',";
            } elseif ($required) {
                $type      = strtolower($column['type'] ?? 'string');
                $ruleType  = in_array($type, ['integer', 'int', 'bigint'], true) ? 'integer' : 'string';
                $lines[]   = "            '{$name}' => 'required|{$ruleType}',";
            }
        }

        return implode("\n", $lines);
    }
}
