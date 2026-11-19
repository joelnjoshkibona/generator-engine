<?php

namespace Blutrixx\GeneratorEngine\Core;

/**
 * A list's allowlists and filters, as data (lifted from BaseServiceGenerator's collect/append helpers and
 * generateFilterFields/getFilterFieldType). A spec's features.backend.list.* entries win over what is derived.
 */
final class ListSpec
{
    private const FILTERABLE_KINDS = ['string', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'enum'];

    /** The label, the workflow, foreign keys, the location (location scope), then other plain columns, then the system ones. */
    public static function filterable(ModuleSpec $spec): array
    {
        if (is_array($given = $spec->backend('list', 'filterableFields'))) {
            return $given;
        }
        $label = $spec->labelColumn();
        $fields = $spec->column($label) ? [$label] : [];
        foreach ($spec->columns() as $c) {
            if (ColumnTypes::kind($c) === 'workflow') {
                $fields[] = $c['name'];
            }
        }
        foreach ($spec->foreignKeys() as $c) {
            $fields[] = $c['name'];
        }
        if ($spec->scope() === 'location') {
            $fields[] = 'location_id';
        }
        foreach ($spec->columns() as $c) {
            if (in_array(ColumnTypes::kind($c), self::FILTERABLE_KINDS, true) && ! in_array($c['name'], $fields, true)) {
                $fields[] = $c['name'];
            }
        }

        return array_values(array_unique([...$fields, 'created_at', 'uuid', 'id']));
    }

    /** created_at first, dates, the label, then id. */
    public static function sortable(ModuleSpec $spec): array
    {
        if (is_array($given = $spec->backend('list', 'sortableFields'))) {
            return $given;
        }
        $dates = array_map(fn ($c) => $c['name'], array_filter($spec->columns(), fn ($c) => in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)));
        $label = $spec->column($spec->labelColumn()) ? [$spec->labelColumn()] : [];

        return array_values(array_unique(['created_at', ...$dates, ...$label, 'id']));
    }

    /** The label and the text columns. */
    public static function searchable(ModuleSpec $spec): array
    {
        if (is_array($given = $spec->backend('list', 'searchableFields'))) {
            return $given;
        }
        $fields = $spec->column($spec->labelColumn()) ? [$spec->labelColumn()] : [];
        foreach ($spec->columns() as $c) {
            if (ColumnTypes::kind($c) === 'text') {
                $fields[] = $c['name'];
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Relations to load with each row: the creator, each foreign key's (narrowed to id, uuid and its label), the location.
     *
     * @return list<string>
     */
    public static function eager(ModuleSpec $spec, ModuleResolver $modules, bool $creatorNarrow = false): array
    {
        $with = [$creatorNarrow ? 'creator:id,uuid,name' : 'creator'];
        foreach ($spec->foreignKeys() as $c) {
            $with[] = ColumnTypes::relationName($c['name']).':id,uuid,'.$modules->labelColumn($c['relatedModule']);
        }
        if ($spec->scope() === 'location') {
            $with[] = 'location:id,uuid,'.$modules->labelColumn('Locations');
        }

        return $with;
    }

    /**
     * The list page's filters, the search first. Each entry: key, label, type (text, number, boolean, date, select,
     * select_paginated, workflow), and module for a picker or values for an enum.
     *
     * @return list<array<string, mixed>>
     */
    public static function filterFields(ModuleSpec $spec): array
    {
        $fields = [['key' => 'search', 'label' => 'Search', 'type' => 'text']];
        foreach (self::filterable($spec) as $name) {
            if (in_array($name, ['uuid', 'id'], true)) {
                continue;
            }
            if ($name === 'created_at') {
                $fields[] = ['key' => 'created_at', 'label' => 'Created', 'type' => 'date'];

                continue;
            }
            if ($name === 'location_id') {
                $fields[] = ['key' => 'location_id', 'label' => 'Location', 'type' => 'select_paginated', 'module' => 'Locations'];

                continue;
            }
            $c = $spec->column($name);
            if ($c === null) {
                continue;
            }
            $entry = ['key' => $name, 'label' => ColumnTypes::label($c)];
            $fields[] = $entry + match (ColumnTypes::kind($c)) {
                'workflow' => ['type' => 'workflow', 'workflow' => $spec->workflow()['key']],
                'foreign' => ['type' => 'select_paginated', 'module' => $c['relatedModule']],
                'enum' => ['type' => 'select', 'values' => array_values($c['values'] ?? [])],
                'integer', 'decimal' => ['type' => 'number'],
                'boolean' => ['type' => 'boolean'],
                'date', 'datetime' => ['type' => 'date'],
                default => ['type' => 'text'],
            };
        }

        return $fields;
    }
}
