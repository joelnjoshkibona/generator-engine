<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class MigrationGenerator extends BaseGenerator
{
    protected array $fields;
    protected array $indexes;
    protected string $tableName;
    protected string $idType;
    protected string $idColumnName;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->fields = $config['columns'];
        $this->indexes = $config['indexes'] ?? [];
        $this->tableName = $config['table_name'];
        
        // Extract ID configuration
        $this->idType = $config['id_type'];
        $this->idColumnName = 'id';
    }

    public function generate(): bool
    {
        // Bug this guards against: the "create" migration filename embeds
        // the CURRENT timestamp (date('Y_m_d_His')), which is different on
        // every invocation — so BaseGenerator::writeFile()'s file_exists()
        // check (which compares this exact, always-fresh filename) could
        // never detect that a migration for this table already existed.
        // Re-running generation for a module whose table already had a
        // hand-written migration therefore wrote a SECOND "create" migration
        // with a slightly different (introspected, and sometimes wrong)
        // schema right alongside the real one (confirmed for
        // LedgerTransactions and member_phones). Guard by TABLE NAME instead
        // of exact filename.
        if ($this->createMigrationAlreadyExists()) {
            return false;
        }

        $content = $this->getTemplateContent('migration', 'backend');
        $content = $this->replacePlaceholders($content, [
            '[[tableName]]' => $this->tableName,
            '[[schema]]' => $this->generateSchema(),
            '[[auditFields]]' => $this->generateAuditFields(),
            '[[indexes]]' => $this->generateIndexes(),
        ]);

        $fileName = date('Y_m_d_His') . "_create_{$this->tableName}_table.php";
        $filePath = "{$this->modulePath}/Migrations/{$fileName}";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Whether a "create {table} table" migration already exists in this
     * module's Migrations directory, regardless of its timestamp prefix.
     */
    protected function createMigrationAlreadyExists(): bool
    {
        $dir = "{$this->modulePath}/Migrations";
        if (!is_dir($dir)) {
            return false;
        }

        $matches = glob($dir . '/*_create_' . $this->tableName . '_table.php');

        return !empty($matches);
    }

    protected function generateSchema(): string
    {
        $schema = [];

        // Generate primary key based on ID type
        $schema[] = $this->generatePrimaryKey();

        // Collect morph pair column names so we skip individual emissions
        $morphs         = $this->config['morphs'] ?? [];
        $morphPairCols  = [];
        $emittedMorphs  = []; // track which morph names we've already emitted
        foreach ($morphs as $morph) {
            if (!empty($morph['type_column'])) {
                $morphPairCols[$morph['type_column']] = $morph['name'];
            }
            if (!empty($morph['id_column'])) {
                $morphPairCols[$morph['id_column']] = $morph['name'];
            }
        }

        // Add other fields; replace morph pair columns with a single morphs() call
        foreach ($this->fields as $field) {
            $name = $field['name'];

            if (isset($morphPairCols[$name])) {
                $morphName = $morphPairCols[$name];
                if (!isset($emittedMorphs[$morphName])) {
                    $nullable = (bool) ($field['nullable'] ?? false);
                    $morphLine = $nullable
                        ? "\$table->nullableMorphs('{$morphName}');"
                        : "\$table->morphs('{$morphName}');";
                    $schema[] = $morphLine;
                    $emittedMorphs[$morphName] = true;
                }
                // Skip the individual column — the morphs() call covers both
                continue;
            }

            $schema[] = $this->generateFieldSchema($field);
        }

        return implode("\n            ", $schema);
    }

    protected function generatePrimaryKey(): string
    {
        switch ($this->idType) {
            case 'autoincrement':
                return '$table->id();';
                
            case 'uuid':
                return '$table->string(\'' . $this->idColumnName . '\')->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();';
                
            case 'manual':
                return "\$table->integer('{$this->idColumnName}')->primary();";
                
            default:
                return '$table->id();';
        }
    }

    protected function generateFieldSchema(array $field): string
    {
        // Ignore id field
        if ($field['name'] === $this->idColumnName) {
            return '';
        }

        $name = $field['name'];
        $type = $field['type'] ?? 'string';
        $length = $field['length'] ?? null;
        $nullable = $field['nullable'] ?? false;
        $default = $field['default'] ?? null;
        $unique = $field['unique'] ?? false;
        $precision = $field['precision'] ?? null;
        $scale = $field['scale'] ?? null;

        $schema = "\$table->{$type}('{$name}'";
        
        // Handle decimal type with precision and scale
        if ($type === 'decimal') {
            $precision = $precision ?? 10;  // Default precision
            $scale = $scale ?? 2;  // Default scale
            $schema .= ", {$precision}, {$scale}";
        } elseif ($length && in_array($type, ['string', 'char'])) {
            // Handle string/char with length
            $schema .= ", {$length}";
        }
        
        $schema .= ')';
        
        if ($nullable) {
            $schema .= '->nullable()';
        }
        
        if ($default !== null && $default !== '') {
            // Handle boolean type defaults
            if ($type === 'boolean') {
                // Convert string 'true'/'false' to boolean values
                if (is_string($default)) {
                    $boolValue = strtolower($default) === 'true' ? 'true' : 'false';
                    $schema .= "->default({$boolValue})";
                } else {
                    $schema .= "->default(" . ($default ? 'true' : 'false') . ")";
                }
            } elseif (is_string($default)) {
                $schema .= "->default('{$default}')";
            } else {
                $schema .= "->default({$default})";
            }
        }
        
        if ($unique) {
            $constraintName = $this->safeConstraintName([$name], 'unique');
            $schema .= "->unique('{$constraintName}')";
        }
        
        // Add comment if provided
        $comment = $field['comment'] ?? null;
        if ($comment && trim($comment) !== '') {
            // Escape single quotes and backslashes in comment for PHP string
            $escapedComment = addslashes($comment);
            $schema .= "->comment('{$escapedComment}')";
        }
        
        return $schema . ';';
    }

    protected function generateIndexes(): string
    {
        if (empty($this->indexes)) {
            return '';
        }

        $indexes = [];
        foreach ($this->indexes as $index) {
            $indexes[] = $this->generateIndexSchema($index);
        }

        return "\n            " . implode("\n            ", $indexes);
    }

    protected function generateIndexSchema(array $index): string
    {
        $columns = is_array($index['columns'])
            ? $index['columns']
            : array_map('trim', explode(',', $index['columns']));
        $name = $index['name'] ?? null;
        $unique = $index['unique'] ?? false;
        
        $method = $unique ? 'unique' : 'index';
        $columnsStr = "'" . implode("', '", $columns) . "'";
        
        $name = $name ?? $this->safeConstraintName($columns, $method);

        return "\$table->{$method}([{$columnsStr}], '{$name}');";
    }

    protected function safeConstraintName(array $columns, string $type): string
    {
        $auto = $this->tableName . '_' . implode('_', $columns) . '_' . $type;
        if (strlen($auto) <= 64) {
            return $auto;
        }
        // Name exceeds MySQL's 64-char limit — keep a readable prefix and append an 8-char hash
        $hash   = substr(md5($auto), 0, 8);
        $suffix = '_' . $hash . '_' . $type;
        $prefix = substr($this->tableName . '_' . implode('_', $columns), 0, 64 - strlen($suffix));
        return $prefix . $suffix;
    }

    protected function generateAuditFields(): string
    {
        // Audit fields use foreignId() for proper FK semantics
        $auditFields = [
            '$table->foreignId(\'created_by_id\');',
            '$table->foreignId(\'updated_by_id\')->nullable();'
        ];

        return "\n            " . implode("\n            ", $auditFields);
    }
}
