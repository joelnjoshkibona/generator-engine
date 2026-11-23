<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * Each column's validation, as data a target renders. Lifted from IntrospectionToConfig::buildBackendFields (presence,
 * type rule, max length, unique) and BaseServiceGenerator::generateValidationRules (foreign keys resolved through the
 * related module, enum domains); the legacy copies stay as they are.
 *
 * One entry per column: ['field', 'rules' => list<string|array{rule: string, ...}>, 'comment' => ?string]. A rule is
 * a plain string ('required', 'max:200') or a structured one the target spells in its own terms:
 *   ['rule' => 'unique', 'table' => 'tasks', 'column' => 'name', 'scoped' => true, 'ignoreSelf' => bool]
 *   ['rule' => 'exists', 'table' => 'users', 'column' => 'id', 'scoped' => false, 'module' => 'Users']
 *   ['rule' => 'in', 'values' => [...]]
 */
final class ValidationRules
{
    /** @return list<array{field: string, rules: list<string|array<string, mixed>>, comment: ?string}> */
    public static function for(ModuleSpec $spec, string $operation, ModuleResolver $modules): array
    {
        $edit = $operation === 'edit';
        $entries = [];
        foreach ($spec->columns() as $column) {
            if ($spec->fill($column) !== null) {
                continue; // P133: the system fills it (the signed-in user, an action, the server): never taken from a form
            }
            $kind = ColumnTypes::kind($column);
            $nullable = (bool) ($column['nullable'] ?? false);
            $rules = $edit ? ['sometimes'] : [];
            $comment = null;

            if ($kind === 'workflow') {
                // P47: a new record without a status starts in the first; an edit may not clear it
                $wf = $spec->workflow();
                $rules = $edit ? ['sometimes', 'required', 'integer'] : ['nullable', 'integer'];
                $rules[] = ['rule' => 'exists', 'table' => "{$wf['key']}_statuses", 'column' => 'id', 'scoped' => true];
                $comment = $edit ? 'P47' : 'P47: absent on create = the first status';
                $entries[] = ['field' => $column['name'], 'rules' => $rules, 'comment' => $comment];

                continue;
            }

            $rules[] = $nullable ? 'nullable' : 'required';
            switch ($kind) {
                case 'foreign':
                    $module = $column['relatedModule'];
                    $rules[] = 'integer';
                    $rules[] = ['rule' => 'exists', 'table' => $modules->table($module), 'column' => 'id', 'scoped' => $modules->isOrganisationScoped($module), 'module' => $module];
                    break;
                case 'string':
                    $rules[] = 'string';
                    $rules[] = 'max:'.ColumnTypes::length($column);
                    break;
                case 'text':
                    $rules[] = 'string';
                    $rules[] = 'max:'.((int) ($column['max'] ?? 5000));
                    break;
                case 'integer':
                    $rules[] = 'integer';
                    break;
                case 'decimal':
                    $rules[] = 'numeric';
                    break;
                case 'boolean':
                    $rules[] = 'boolean';
                    break;
                case 'date':
                    $rules[] = 'date_format:Y-m-d';
                    break;
                case 'datetime':
                    $rules[] = 'date';
                    break;
                case 'json':
                    $rules[] = 'array';
                    break;
                case 'media':
                    // P131: the media's uuid, a file in the organisation's library
                    $rules[] = 'string';
                    $rules[] = ['rule' => 'exists', 'table' => 'media', 'column' => 'uuid', 'scoped' => true];
                    $comment = 'P131: a file in the Media library, by its uuid';
                    break;
                case 'enum':
                    $rules[] = ['rule' => 'in', 'values' => array_values($column['values'] ?? [])];
                    break;
                default:
                    $rules[] = 'string';
            }
            if (! empty($column['unique'])) {
                $rules[] = ['rule' => 'unique', 'table' => $spec->table(), 'column' => $column['name'], 'scoped' => $spec->isOrganisationScoped(), 'ignoreSelf' => $edit];
            }
            foreach ($spec->uniqueTogether() as $set) {
                if (in_array($column['name'], $set, true)) {
                    // on each column of the set: an edit that changes only one of them is checked too
                    $rules[] = ['rule' => 'unique_together', 'table' => $spec->table(), 'columns' => $set, 'ignoreSelf' => $edit];
                }
            }
            $entries[] = ['field' => $column['name'], 'rules' => $rules, 'comment' => $comment];
        }

        return $entries;
    }
}
