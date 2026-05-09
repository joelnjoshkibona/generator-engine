<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class MigrationUpdateGenerator extends BaseGenerator
{
    protected array $previousColumns;
    protected array $newColumns;
    protected string $tableName;
    protected array $previousIndexes;
    protected array $newIndexes;
    
    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->previousColumns = $config['previous_columns'] ?? [];
        $this->newColumns = $config['new_columns'] ?? [];
        $this->previousIndexes = $config['previous_indexes'] ?? [];
        $this->newIndexes = $config['new_indexes'] ?? [];
        $this->tableName = $config['table_name'];
    }
    
    public function generate(): bool
    {
        $changes = $this->detectChanges();
        
        \Illuminate\Support\Facades\Log::info('MigrationUpdateGenerator::detectChanges() result', [
            'table' => $this->tableName,
            'added_columns' => array_keys($changes['added'] ?? []),
            'modified_columns' => array_keys($changes['modified'] ?? []),
            'removed_columns' => array_keys($changes['removed'] ?? []),
            'added_indexes' => count($changes['indexes_added'] ?? []),
            'removed_indexes' => count($changes['indexes_removed'] ?? []),
            'has_no_changes' => $this->hasNoChanges($changes),
        ]);
        
        if ($this->hasNoChanges($changes)) {
            \Illuminate\Support\Facades\Log::warning('MigrationUpdateGenerator: No changes detected, skipping migration', [
                'table' => $this->tableName,
            ]);
            return false; // No migration needed
        }
        
        $content = $this->getTemplateContent('migration_update', 'backend');
        $content = $this->replacePlaceholders($content, [
            '[[tableName]]' => $this->tableName,
            '[[upChanges]]' => $this->generateUpChanges($changes),
            '[[downChanges]]' => $this->generateDownChanges($changes),
            '[[changeDescription]]' => $this->generateChangeDescription($changes),
        ]);
        
        $suffix = $this->getChangeSuffix($changes);
        $fileName = date('Y_m_d_His') . "_update_{$this->tableName}_table_{$suffix}.php";
        $filePath = "{$this->modulePath}/Migrations/{$fileName}";
        
        return $this->writeFile($filePath, $content);
    }
    
    protected function detectChanges(): array
    {
        // Index by column name for easy comparison
        $prevCols = $this->indexByName($this->previousColumns);
        $newCols = $this->indexByName($this->newColumns);
        
        return [
            'added' => $this->detectAdded($prevCols, $newCols),
            'modified' => $this->detectModified($prevCols, $newCols),
            'removed' => $this->detectRemoved($prevCols, $newCols),
            'indexes_added' => $this->detectIndexesAdded(),
            'indexes_removed' => $this->detectIndexesRemoved(),
        ];
    }
    
    protected function indexByName(array $columns): array
    {
        $indexed = [];
        foreach ($columns as $col) {
            $indexed[$col['name']] = $col;
        }
        return $indexed;
    }
    
    protected function detectAdded(array $prev, array $new): array
    {
        $added = [];
        foreach ($new as $name => $col) {
            if (!isset($prev[$name])) {
                $added[$name] = $col;
            }
        }
        return $added;
    }
    
    protected function detectModified(array $prev, array $new): array
    {
        $modified = [];
        foreach ($new as $name => $newCol) {
            if (isset($prev[$name])) {
                $oldCol = $prev[$name];
                if ($this->hasColumnChanged($oldCol, $newCol)) {
                    $modified[$name] = [
                        'old' => $oldCol,
                        'new' => $newCol
                    ];
                }
            }
        }
        return $modified;
    }
    
    protected function detectRemoved(array $prev, array $new): array
    {
        $removed = [];
        foreach ($prev as $name => $col) {
            if (!isset($new[$name])) {
                $removed[$name] = $col;
            }
        }
        return $removed;
    }
    
    protected function hasColumnChanged(array $old, array $new): bool
    {
        // Compare relevant properties
        $compareKeys = ['type', 'length', 'nullable', 'default', 'unique'];
        foreach ($compareKeys as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }
    
    protected function hasNoChanges(array $changes): bool
    {
        return empty($changes['added']) 
            && empty($changes['modified']) 
            && empty($changes['removed'])
            && empty($changes['indexes_added'])
            && empty($changes['indexes_removed']);
    }
    
    protected function generateUpChanges(array $changes): string
    {
        $statements = [];
        
        // Added columns
        foreach ($changes['added'] as $name => $col) {
            $statements[] = $this->generateAddColumn($col);
        }
        
        // Modified columns
        foreach ($changes['modified'] as $name => $change) {
            $statements[] = $this->generateModifyColumn($change['new']);
        }
        
        // Removed columns (commented out for safety)
        foreach ($changes['removed'] as $name => $col) {
            $statements[] = $this->generateDropColumn($col, true); // true = commented
        }
        
        // Added indexes
        foreach ($changes['indexes_added'] as $index) {
            $statements[] = $this->generateAddIndex($index);
        }
        
        // Removed indexes
        foreach ($changes['indexes_removed'] as $index) {
            $statements[] = $this->generateDropIndex($index, true);
        }
        
        return implode("\n            ", $statements);
    }
    
    protected function generateDownChanges(array $changes): string
    {
        $statements = [];
        
        // Reverse: Drop added columns
        foreach ($changes['added'] as $name => $col) {
            $statements[] = $this->generateDropColumn($col, true);
        }
        
        // Reverse: Restore modified columns
        foreach ($changes['modified'] as $name => $change) {
            $statements[] = $this->generateModifyColumn($change['old']);
        }
        
        // Reverse: Restore removed columns
        foreach ($changes['removed'] as $name => $col) {
            $statements[] = $this->generateAddColumn($col, true);
        }
        
        return implode("\n            ", $statements);
    }
    
    protected function generateAddColumn(array $col, bool $commented = false): string
    {
        $prefix = $commented ? '// ' : '';
        $schema = $this->buildColumnSchema($col);
        return "{$prefix}{$schema};";
    }
    
    protected function generateModifyColumn(array $col): string
    {
        $schema = $this->buildColumnSchema($col);
        return "{$schema}->change();";
    }
    
    protected function generateDropColumn(array $col, bool $commented = false): string
    {
        $prefix = $commented ? '// ' : '';
        $warning = $commented ? " // ⚠️ CAUTION: Dropping this column will lose data!" : '';
        return "{$prefix}\$table->dropColumn('{$col['name']}');{$warning}";
    }
    
    protected function buildColumnSchema(array $col): string
    {
        $name = $col['name'];
        $type = $col['type'];
        $length = $col['length'] ?? null;
        
        $schema = "\$table->{$type}('{$name}'";
        
        if ($length && in_array($type, ['string', 'char'])) {
            $schema .= ", {$length}";
        }
        
        $schema .= ')';
        
        if ($col['nullable'] ?? false) {
            $schema .= '->nullable()';
        }
        
        if (isset($col['default']) && $col['default'] !== null) {
            if (is_string($col['default'])) {
                $schema .= "->default('{$col['default']}')";
            } else {
                $schema .= "->default({$col['default']})";
            }
        }
        
        if ($col['unique'] ?? false) {
            $schema .= '->unique()';
        }
        
        // Add comment if provided
        $comment = $col['comment'] ?? null;
        if ($comment && trim($comment) !== '') {
            // Escape single quotes and backslashes in comment for PHP string
            $escapedComment = addslashes($comment);
            $schema .= "->comment('{$escapedComment}')";
        }
        
        return $schema;
    }
    
    protected function generateChangeDescription(array $changes): string
    {
        $parts = [];
        
        if (!empty($changes['added'])) {
            $parts[] = 'Added: ' . implode(', ', array_keys($changes['added']));
        }
        if (!empty($changes['modified'])) {
            $parts[] = 'Modified: ' . implode(', ', array_keys($changes['modified']));
        }
        if (!empty($changes['removed'])) {
            $parts[] = 'Removed: ' . implode(', ', array_keys($changes['removed']));
        }
        
        return implode(' | ', $parts);
    }
    
    protected function getChangeSuffix(array $changes): string
    {
        if (!empty($changes['added'])) {
            $firstAdded = array_key_first($changes['added']);
            return "add_{$firstAdded}";
        }
        if (!empty($changes['modified'])) {
            $firstModified = array_key_first($changes['modified']);
            return "modify_{$firstModified}";
        }
        if (!empty($changes['removed'])) {
            $firstRemoved = array_key_first($changes['removed']);
            return "remove_{$firstRemoved}";
        }
        return 'update_schema';
    }
    
    // Index detection methods
    protected function detectIndexesAdded(): array
    {
        // Compare previous and new indexes
        $prevIndexNames = array_column($this->previousIndexes, 'name');
        $newIndexNames = array_column($this->newIndexes, 'name');
        
        $added = [];
        foreach ($this->newIndexes as $index) {
            if (!in_array($index['name'], $prevIndexNames)) {
                $added[] = $index;
            }
        }
        return $added;
    }
    
    protected function detectIndexesRemoved(): array
    {
        $prevIndexNames = array_column($this->previousIndexes, 'name');
        $newIndexNames = array_column($this->newIndexes, 'name');
        
        $removed = [];
        foreach ($this->previousIndexes as $index) {
            if (!in_array($index['name'], $newIndexNames)) {
                $removed[] = $index;
            }
        }
        return $removed;
    }
    
    protected function generateAddIndex(array $index): string
    {
        $columns = is_array($index['columns'])
            ? $index['columns']
            : array_map('trim', explode(',', $index['columns']));
        $columnsStr = "'" . implode("', '", $columns) . "'";
        return "\$table->index([{$columnsStr}], '{$index['name']}');";
    }
    
    protected function generateDropIndex(array $index, bool $commented = false): string
    {
        $prefix = $commented ? '// ' : '';
        return "{$prefix}\$table->dropIndex('{$index['name']}');";
    }
}
