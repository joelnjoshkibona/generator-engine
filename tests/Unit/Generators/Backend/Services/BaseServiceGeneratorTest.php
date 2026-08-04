<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Regression coverage for the "id first / sortable / filterable, uuid
 * filterable-only" fix (v2.10.7) in BaseServiceGenerator.
 *
 * Bug: freshly scaffolded modules' ListService never allowed sorting or
 * filtering by "id" — $filterableFields/$sortableFields (backend allow-lists)
 * and $data['filterFields'] (the array driving the frontend DataTableFilter
 * UI) only ever contained schema-derived fields. "uuid" never appeared
 * anywhere, so there was no way to filter by it via the API even though
 * every table in this codebase has a uuid column.
 *
 * Fix: generateFilterableFields() and generateSortableFields() now always
 * append "id" (both) and "uuid" (filterable only, never sortable) to the
 * backend allow-lists if not already present — matching the standard first,
 * sortable+filterable ID column BaseComponentGenerator::generateColumnsFromListFields()
 * now emits. generateFilterFields() always appends an "id" entry so the
 * frontend filter UI gets a control for it, but deliberately never adds
 * "uuid" — it stays backend-filterable only ("hidden but filterable"),
 * matching the rule that uuid must never be a visible column or filter
 * control.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateFilterableFields()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateSortableFields()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateFilterFields()
 */
class BaseServiceGeneratorTest extends TestCase
{
    /**
     * Build a bare BaseServiceGenerator instance without running its
     * constructor, since BaseGenerator::__construct() calls
     * PathManager::ensureOutputDirectories(), which requires a booted
     * Laravel application not available in a plain PHPUnit run.
     *
     * @param array<string, mixed> $config
     */
    private function makeGenerator(array $config = []): TestBaseServiceGenerator
    {
        $ref = new ReflectionClass(TestBaseServiceGenerator::class);
        /** @var TestBaseServiceGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        $this->setProtectedProperty($generator, 'moduleName', 'TestModule');
        $this->setProtectedProperty($generator, 'moduleGroup', 'Core');
        $this->setProtectedProperty($generator, 'config', $config);

        return $generator;
    }

    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    // ─── generateFilterableFields() ─────────────────────────────────────────

    public function test_filterable_fields_always_includes_id_uuid_and_created_at(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'parent_id', 'status_id'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'parent_id', 'status_id', 'id', 'uuid', 'created_at']", $result);
    }

    public function test_filterable_fields_does_not_duplicate_id_uuid_or_created_at_if_already_configured(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'id', 'uuid', 'created_at'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'id', 'uuid', 'created_at']", $result);
        $this->assertSame(1, substr_count($result, "'id'"));
        $this->assertSame(1, substr_count($result, "'uuid'"));
        $this->assertSame(1, substr_count($result, "'created_at'"));
    }

    public function test_filterable_fields_from_filterfields_config_still_appends_id_uuid_and_created_at(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterFields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'id', 'uuid', 'created_at']", $result);
    }

    public function test_filterable_fields_with_no_config_still_yields_id_uuid_and_created_at(): void
    {
        $generator = $this->makeGenerator([]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['id', 'uuid', 'created_at']", $result);
    }

    // ─── generateSortableFields() ───────────────────────────────────────────

    public function test_sortable_fields_always_includes_id_but_never_auto_adds_uuid(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'sortableFields' => ['created_at', 'name', 'status_id'],
            ]]],
        ]);

        $result = $generator->callGenerateSortableFields();

        $this->assertSame("['created_at', 'name', 'status_id', 'id']", $result);
        $this->assertStringNotContainsString("'uuid'", $result);
    }

    public function test_sortable_fields_falls_back_to_filterable_fields_and_still_adds_id_not_uuid(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'status_id'],
            ]]],
        ]);

        $result = $generator->callGenerateSortableFields();

        // Falls back to the raw filterableFields config (NOT the id/uuid-augmented
        // output of generateFilterableFields()), then appends only "id".
        $this->assertSame("['name', 'status_id', 'id']", $result);
        $this->assertStringNotContainsString("'uuid'", $result);
    }

    public function test_sortable_fields_does_not_duplicate_id_if_already_configured(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'sortableFields' => ['id', 'name'],
            ]]],
        ]);

        $result = $generator->callGenerateSortableFields();

        $this->assertSame("['id', 'name']", $result);
        $this->assertSame(1, substr_count($result, "'id'"));
    }

    // ─── generateFilterFields() (frontend filter UI) ────────────────────────

    public function test_filter_fields_appends_id_uuid_and_created_at_entries_for_frontend_filter_control(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterFields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'status_id', 'label' => 'Status', 'type' => 'text'],
                ],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        // formatFilterField() renders values via arrayToString(), which double-quotes
        // string values (e.g. 'key' => "id"), unlike the single-quoted PHP array
        // literals generateFilterableFields()/generateSortableFields() emit.
        $this->assertStringContainsString("'key' => \"id\"", $result);
        $this->assertStringContainsString("'label' => \"ID\"", $result);
        $this->assertStringContainsString("'key' => \"uuid\"", $result);
        $this->assertStringContainsString("'label' => \"UUID\"", $result);
        $this->assertStringContainsString("'key' => \"created_at\"", $result);
        $this->assertStringContainsString("'label' => \"Created At\"", $result);
        $this->assertStringContainsString("'type' => \"date\"", $result);
        $this->assertSame(1, substr_count($result, "'key' => \"id\""));
    }

    public function test_filter_fields_derived_fallback_still_appends_id_uuid_and_created_at(): void
    {
        // Simulates an introspected module: no explicit filterFields, only
        // filterableFields (which, per IntrospectionToConfig, never contains
        // "id"/"uuid"/"created_at" since all three are excluded system columns
        // at that layer — generateFilterFields()'s own defaults are what
        // actually put them in the frontend-facing output).
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'parent_id'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"name\"", $result);
        $this->assertStringContainsString("'key' => \"parent_id\"", $result);
        $this->assertStringContainsString("'key' => \"id\"", $result);
        $this->assertStringContainsString("'key' => \"uuid\"", $result);
        $this->assertStringContainsString("'key' => \"created_at\"", $result);
    }

    public function test_filter_fields_does_not_duplicate_id_uuid_or_created_at_if_already_configured(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterFields' => [
                    ['key' => 'id', 'label' => 'Custom ID Label', 'type' => 'number'],
                    ['key' => 'uuid', 'label' => 'Custom UUID Label', 'type' => 'text'],
                    ['key' => 'created_at', 'label' => 'Custom Created Label', 'type' => 'date'],
                ],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertSame(1, substr_count($result, "'key' => \"id\""));
        $this->assertSame(1, substr_count($result, "'key' => \"uuid\""));
        $this->assertSame(1, substr_count($result, "'key' => \"created_at\""));
        // The caller-supplied entries must win — not the auto-appended defaults.
        $this->assertStringContainsString("'label' => \"Custom ID Label\"", $result);
        $this->assertStringContainsString("'label' => \"Custom UUID Label\"", $result);
        $this->assertStringContainsString("'label' => \"Custom Created Label\"", $result);
    }

    public function test_filter_fields_with_no_config_at_all_still_yields_id_uuid_and_created_at(): void
    {
        $generator = $this->makeGenerator([]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"id\"", $result);
        $this->assertStringContainsString("'key' => \"uuid\"", $result);
        $this->assertStringContainsString("'key' => \"created_at\"", $result);
    }

    // ─── generateFilterFields() fallback is type-aware, not hardcoded 'text' (2026-08-03) ──
    //
    // Bug: every field the fallback derived from filterableFields was
    // hardcoded 'type' => 'text', regardless of the column's real type — an
    // FK or enum column (e.g. an Order's `status`) rendered as a plain text
    // box running a LIKE search against an integer/enum column that could
    // never meaningfully match. getFilterFieldType()/isForeignKey() already
    // existed with (almost) the right shape but were never called anywhere
    // (grep confirmed zero call sites) and had their own latent bugs, never
    // caught for the same reason — see their own docblocks.
    //
    // Fix: the fallback now looks up each filterable field's real column
    // definition (config['columns']) and calls the fixed
    // getFilterFieldType()/buildFilterFieldOptions() instead of hardcoding
    // 'text'.
    //
    // Column fixtures below use 'type' (not 'normalized_type') deliberately
    // — config['columns'] entries are IntrospectionToConfig::buildColumn()'s
    // OUTPUT shape, which collapses the raw type + normalized_type + is_fk
    // down to a single 'type' key holding the normalized value. Using
    // 'normalized_type' here (SchemaIntrospector::columns()' raw shape) was
    // an actual mistake made and caught while building this test — see
    // isForeignKey()'s own docblock for the full story.

    public function test_filter_fields_fallback_marks_fk_column_select_paginated(): void
    {
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'customer_id', 'type' => 'foreignId'],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['customer_id'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"customer_id\"", $result);
        $this->assertStringContainsString("'type' => \"select_paginated\"", $result);
    }

    public function test_filter_fields_fallback_marks_enum_column_select_with_options(): void
    {
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'paid', 'shipped']],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['status'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"status\"", $result);
        $this->assertStringContainsString("'type' => \"select\"", $result);
        // Real options, not just the type flag — DataTableFilter.vue gates
        // its dropdown widget on field.options being present, not merely on
        // field.type === 'select'.
        $this->assertStringContainsString("'name' => \"Pending\"", $result);
        $this->assertStringContainsString("'id' => \"pending\"", $result);
        $this->assertStringContainsString("'name' => \"Shipped\"", $result);
    }

    public function test_filter_fields_fallback_marks_boolean_column_select_with_yes_no_options(): void
    {
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'is_featured', 'type' => 'boolean'],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['is_featured'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'type' => \"select\"", $result);
        $this->assertStringContainsString("'name' => \"Yes\"", $result);
        $this->assertStringContainsString("'name' => \"No\"", $result);
    }

    public function test_filter_fields_fallback_marks_biginteger_column_number_not_text(): void
    {
        // Regression for getFilterFieldType()'s own internal bug: it used
        // to check the literal string 'bigint', which never matches the
        // string a real bigint column's config['columns'] entry actually
        // carries, 'bigInteger' (camelCase, from
        // SchemaIntrospector::normalizeType()) — a non-FK bigint column
        // fell through to 'text'.
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'view_count', 'type' => 'bigInteger'],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['view_count'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"view_count\"", $result);
        $this->assertStringContainsString("'type' => \"number\"", $result);
    }

    public function test_filter_fields_fallback_marks_date_and_datetime_columns_date_not_text(): void
    {
        // getFilterFieldType() has always had a date/datetime/timestamp ->
        // 'date' branch, but nothing ever exercised it: no regression test
        // covered it, and the live SYSTEM_SHELL scratch-module verification
        // for this fix used a schema with no date/datetime column at all.
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'due_date', 'type' => 'date'],
                ['name' => 'published_at', 'type' => 'datetime'],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['due_date', 'published_at'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"due_date\"", $result);
        $this->assertStringContainsString("'key' => \"published_at\"", $result);
        // 3, not 2: "created_at" is now always auto-appended as its own
        // 'date'-type entry too (see the id/uuid/created_at defaults test
        // above) — this fixture doesn't declare a created_at column, so that
        // third entry comes entirely from the default-append, not from here.
        $this->assertSame(3, substr_count($result, "'type' => \"date\""));
    }

    public function test_filter_fields_fallback_still_marks_plain_string_column_text(): void
    {
        // Baseline: a genuinely free-text column (no enum, not an FK, not
        // boolean/numeric/date) is correctly still a plain text filter —
        // this fix narrows the fallback's scope, it doesn't remove it.
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"name\"", $result);
        $this->assertStringContainsString("'type' => \"text\"", $result);
    }

    public function test_filter_fields_fallback_defaults_to_text_when_column_definition_is_missing(): void
    {
        // Defensive case: filterableFields names a column that isn't in
        // config['columns'] at all (shouldn't normally happen, since
        // IntrospectionToConfig derives filterableFields FROM columns, but
        // a hand-authored config could do this) — must not fatal, and must
        // fall back to the old safe default rather than guessing.
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['mystery_field'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"mystery_field\"", $result);
        $this->assertStringContainsString("'type' => \"text\"", $result);
    }

    // ─── generateValidationRules() — "__ID__" unique-rule substitution (v2.10.9) ──
    //
    // Bug: generateValidationRules() passed each field's config `rules` string
    // through verbatim, so an Edit service's "unique:table,col,__ID__"-style
    // rule (meant to become "unique:table,col,{$model->id}" so the uniqueness
    // check excludes the record being edited) was emitted with the literal,
    // meaningless "__ID__" token still in it — every Edit save on a unique
    // field tripped over the record's own existing value.
    //
    // Fix: when $edit is true, any rule starting with "unique:" is rebuilt via
    // ValidationGenerator::processUniqueRule() (table/column parsed out, then
    // ",{$model->id}" appended as real PHP interpolation syntax for the
    // *generated* file to evaluate at runtime). Create services never run this
    // substitution — there's no existing record to exclude yet.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateValidationRules()
    // @see \Blutrixx\GeneratorEngine\Generators\Backend\Validation\ValidationGenerator::processUniqueRule()

    public function test_edit_validation_rules_substitute_model_id_for_unique_placeholder(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255|unique:item_categories,name,__ID__'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(true);

        $this->assertStringContainsString('"unique:item_categories,name,{$model->id}"', $result);
        $this->assertStringNotContainsString('__ID__', $result);
        $this->assertStringContainsString('"required"', $result);
        $this->assertStringContainsString('"string"', $result);
        $this->assertStringContainsString('"max:255"', $result);
    }

    public function test_create_validation_rules_do_not_substitute_model_id(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255|unique:item_categories,name'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertStringContainsString('"unique:item_categories,name"', $result);
        $this->assertStringNotContainsString('$model->id', $result);
        $this->assertStringNotContainsString('__ID__', $result);
    }

    public function test_edit_validation_rules_append_model_id_even_without_placeholder(): void
    {
        // Any unique rule on an Edit service must exclude the record being
        // edited, whether or not the config author remembered to write the
        // "__ID__" placeholder — processUniqueRule() rebuilds the clause from
        // table+column unconditionally whenever $edit is true.
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'code', 'rules' => 'required|unique:items,code'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(true);

        $this->assertStringContainsString('"unique:items,code,{$model->id}"', $result);
    }

    public function test_edit_validation_rules_leave_non_unique_rules_untouched(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'description', 'rules' => 'nullable|string|max:1000'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(true);

        $this->assertSame("['description' => [\"nullable\", \"string\", \"max:1000\"]]", $result);
    }

    // ─── file_columns validation-rule override ─────────────────────────────
    // See BaseServiceGenerator::generateValidationRules()'s file_columns
    // branch and generateFileColumnUploads(). A column marked via
    // IntrospectionToConfig's file_columns meta (threaded to $config's top
    // level) is understood as "needs upload-then-store-id handling" -- it
    // must validate as a Laravel 'file', not whatever FK/integer rule its
    // own field entry otherwise carries.

    public function test_create_validation_rule_for_file_column_is_required_file(): void
    {
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame("['image_media_id' => [\"required\", \"file\"]]", $result);
    }

    public function test_create_validation_rule_for_nullable_file_column_stays_nullable(): void
    {
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'nullable|integer'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame("['image_media_id' => [\"nullable\", \"file\"]]", $result);
    }

    public function test_edit_validation_rule_for_file_column_is_always_nullable_file(): void
    {
        // Edit always allows an optional re-upload (see
        // generateFileColumnUploads()'s edit branch) -- 'nullable', even
        // though the underlying DB column itself is NOT NULL (required on
        // create).
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(true);

        $this->assertSame("['image_media_id' => [\"nullable\", \"file\"]]", $result);
    }

    public function test_non_file_columns_are_unaffected_by_file_columns_config(): void
    {
        // Regression guard: a module with SOME file_columns must leave every
        // other field's validation rule completely untouched.
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
                ['field' => 'is_primary', 'rules' => 'required|boolean'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame(
            "['image_media_id' => [\"required\", \"file\"],\n            'is_primary' => [\"required\", \"boolean\"]]",
            $result
        );
    }

    public function test_validation_rules_regression_when_file_columns_absent(): void
    {
        // A config with no 'file_columns' key at all (the common case, and
        // every pre-existing caller) must generate exactly as before.
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'category_id', 'rules' => 'required|integer|exists:categories,id'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame("['category_id' => [\"required\", \"integer\", \"exists:categories,id\"]]", $result);
    }

    // ─── generateValidationRules() — enum `in:` constraint (v2.12.x follow-up) ──
    //
    // Bug: v2.12.0 taught SchemaIntrospector/IntrospectionToConfig to capture
    // an enum column's allowed values into a per-column `enum_values` key
    // (threaded onto $config['columns'] by IntrospectionToConfig::buildColumn()),
    // and that value reaches the migration and FactoryGenerator -- but
    // IntrospectionToConfig::buildBackendFields() (src/Schema/IntrospectionToConfig.php),
    // which assembles each field's `rules` string, never learned about it. An
    // enum column's field entry here (features.backend.create/edit.fields[])
    // therefore only ever carried whatever rule its underlying DB type
    // implied (`string`), so a value outside the allowed set was accepted by
    // validation and only rejected later by the DB with a raw SQL error
    // instead of a clean 422.
    //
    // Fix: generateValidationRules() now cross-references $config['columns']
    // (present regardless of whether the config was introspected or
    // hand-authored) by field name, and appends a
    // \Illuminate\Validation\Rule::in([...]) entry to that field's rule array
    // whenever a non-empty `enum_values` is found for it.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateValidationRules()
    // @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildBackendFields()
    // @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig::buildColumn()

    public function test_required_enum_field_gets_rule_in_constraint(): void
    {
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['draft', 'published', 'archived']],
            ],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'status', 'rules' => 'required|string'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame(
            "['status' => [\"required\", \"string\", \\Illuminate\\Validation\\Rule::in(['draft', 'published', 'archived'])]]",
            $result
        );
    }

    public function test_nullable_enum_field_keeps_nullable_alongside_rule_in(): void
    {
        // A nullable enum column must still accept null -- Rule::in() is
        // appended, not substituted for, the 'nullable' rule already present,
        // and Laravel itself skips Rule::in() (like every other rule) when a
        // nullable field's value is null.
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'priority', 'type' => 'enum', 'enum_values' => ['low', 'high']],
            ],
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'priority', 'rules' => 'nullable|string'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(true);

        $this->assertStringContainsString('"nullable"', $result);
        $this->assertStringNotContainsString('"required"', $result);
        $this->assertStringContainsString("\\Illuminate\\Validation\\Rule::in(['low', 'high'])", $result);
    }

    public function test_enum_values_containing_quotes_and_backslashes_are_escaped_via_var_export(): void
    {
        // var_export(), not addslashes(), because these values are spliced
        // into a SINGLE-quoted PHP array literal inside Rule::in([...]) --
        // addslashes() also escapes `"` to `\"`, which a single-quoted PHP
        // string does not recognize as an escape at all, so the backslash
        // would survive literally and corrupt any value containing a double
        // quote (same reasoning as FactoryGenerator's enum branch).
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'label', 'type' => 'enum', 'enum_values' => ["O'Brien's \"best\"", 'back\\slash']],
            ],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'label', 'rules' => 'required|string'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $expectedValues = implode(', ', [
            var_export("O'Brien's \"best\"", true),
            var_export('back\\slash', true),
        ]);
        $this->assertStringContainsString("\\Illuminate\\Validation\\Rule::in([{$expectedValues}])", $result);
    }

    public function test_validation_rules_regression_when_enum_values_absent(): void
    {
        // A config with no 'columns' key at all (every pre-existing caller,
        // including every test above this block) must generate byte-for-byte
        // identical output to before this feature existed -- no Rule::in()
        // anywhere.
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'status', 'rules' => 'required|string|max:50'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame("['status' => [\"required\", \"string\", \"max:50\"]]", $result);
        $this->assertStringNotContainsString('Rule::in', $result);
    }

    public function test_validation_rules_regression_when_columns_present_but_field_has_no_enum_values(): void
    {
        // A 'columns' entry for a DIFFERENT, non-enum field must not affect
        // this field's rules -- only an explicit, non-empty `enum_values` on
        // the matching column name triggers Rule::in().
        $generator = $this->makeGenerator([
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255'],
            ]]]],
        ]);

        $result = $generator->callGenerateValidationRules(false);

        $this->assertSame("['name' => [\"required\", \"string\", \"max:255\"]]", $result);
    }

    // ─── generateFileColumnUploads() ────────────────────────────────────────

    public function test_file_column_uploads_generates_media_service_call_for_create(): void
    {
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]);

        $result = $generator->callGenerateFileColumnUploads(false);

        $this->assertStringContainsString("\$validData['image_media_id'] ?? null) instanceof \\Illuminate\\Http\\UploadedFile", $result);
        $this->assertStringContainsString('\App\Project\Modules\Core\Media\Services\MediaService::createFile($validData[\'image_media_id\'], Auth::id())', $result);
        $this->assertStringNotContainsString('unset(', $result, 'Create must never unset the field -- validation already guarantees it is present.');
    }

    public function test_file_column_uploads_edit_unsets_when_no_new_file_provided(): void
    {
        $generator = $this->makeGenerator([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]);

        $result = $generator->callGenerateFileColumnUploads(true);

        $this->assertStringContainsString('MediaService::createFile', $result);
        $this->assertStringContainsString("unset(\$validData['image_media_id'])", $result, 'Edit must keep the model\'s existing media_id when no new file is sent.');
    }

    public function test_file_column_uploads_empty_when_no_file_columns_configured(): void
    {
        // Regression guard: the common case (no file_columns at all) must
        // generate an empty string, so beforeCreate()/beforeUpdate() see zero
        // diff for every module that isn't using this feature.
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string'],
            ]]]],
        ]);

        $this->assertSame('', $generator->callGenerateFileColumnUploads(false));
    }

    public function test_file_column_uploads_ignores_stray_column_not_in_this_features_fields(): void
    {
        // A file_columns entry naming a column that isn't actually part of
        // this feature's own fields (typo, or meant for a different module)
        // must be silently skipped, not emit dead code referencing a
        // nonexistent $validData key.
        $generator = $this->makeGenerator([
            'file_columns' => ['some_other_module_column'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string'],
            ]]]],
        ]);

        $this->assertSame('', $generator->callGenerateFileColumnUploads(false));
    }

    // ─── generateEagerLoadRelationships() — creator/updater gated on
    // ModuleConfigContract::hasCreatorUpdater() (2026-07-30) ────────────────
    //
    // Bug: this always appended 'creator'/'updater' to the eager-load list
    // unconditionally, in every branch (default, comma-separated custom
    // config, array custom config). Found live: a module with
    // has_creator_updater: false threw RelationNotFoundException the moment
    // its ViewService/ListService ran self::$eagerLoadRelationships through
    // $model->load(), since the model has no such relations at all. Every
    // real Core module in SYSTEM_SHELL has creator/updater tracking, so this
    // was never exercised.
    //
    // @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::hasCreatorUpdater()

    public function test_eager_load_relationships_default_includes_creator_and_updater(): void
    {
        $generator = $this->makeGenerator([]);

        $result = $generator->callGenerateEagerLoadRelationships('view');

        $this->assertSame('[\'creator\', \'updater\']', $result);
    }

    public function test_eager_load_relationships_omits_creator_and_updater_when_disabled(): void
    {
        $generator = $this->makeGenerator(['has_creator_updater' => false]);

        $result = $generator->callGenerateEagerLoadRelationships('view');

        $this->assertSame('[]', $result);
    }

    public function test_eager_load_relationships_custom_string_config_still_appends_creator_updater_by_default(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['view' => [
                'eagerLoadRelationships' => 'category, items',
            ]]],
        ]);

        $result = $generator->callGenerateEagerLoadRelationships('view');

        $this->assertSame("['category', 'items', 'creator', 'updater']", $result);
    }

    public function test_eager_load_relationships_custom_string_config_omits_creator_updater_when_disabled(): void
    {
        $generator = $this->makeGenerator([
            'has_creator_updater' => false,
            'features' => ['backend' => ['view' => [
                'eagerLoadRelationships' => 'category, items',
            ]]],
        ]);

        $result = $generator->callGenerateEagerLoadRelationships('view');

        $this->assertSame("['category', 'items']", $result);
    }

    public function test_eager_load_relationships_custom_array_config_omits_creator_updater_when_disabled(): void
    {
        $generator = $this->makeGenerator([
            'has_creator_updater' => false,
            'features' => ['backend' => ['list' => [
                'eagerLoadRelationships' => ['category'],
            ]]],
        ]);

        $result = $generator->callGenerateEagerLoadRelationships('list');

        $this->assertSame("['category']", $result);
    }

    public function test_eager_load_relationships_empty_custom_config_with_creator_updater_disabled_yields_empty_array(): void
    {
        // Custom config present but resolves to no relationships at all (e.g.
        // an empty string) AND creator/updater disabled -- must not fall back
        // to the '[]'-vs-omitted inconsistency the old code had (it used to
        // special-case "empty custom config" to return the hardcoded
        // '["creator", "updater"]' literal regardless of this flag).
        $generator = $this->makeGenerator([
            'has_creator_updater' => false,
            'features' => ['backend' => ['view' => [
                'eagerLoadRelationships' => '',
            ]]],
        ]);

        $result = $generator->callGenerateEagerLoadRelationships('view');

        $this->assertSame('[]', $result);
    }

    // ─── isForeignKey() (Finding 4) ─────────────────────────────────────────

    public function test_is_foreign_key_true_for_field_typed_foreign_id_even_without_id_suffix(): void
    {
        // $field here must match config['columns']' shape —
        // IntrospectionToConfig::buildColumn()'s OUTPUT, not
        // SchemaIntrospector::columns()' raw output. buildColumn() collapses
        // the raw type + normalized_type + is_fk down to a single 'type' key
        // holding the NORMALIZED value, so a real FK column's entry here has
        // 'type' => 'foreignId' — confirmed via live generation, not assumed.
        //
        // This assertion itself was already correct before 2026-08-03's fix
        // pass — what was missing was any real *caller* (isForeignKey() had
        // zero call sites anywhere, so this correct check never ran against
        // real data). A same-day first attempt at wiring it up briefly
        // "corrected" this check to look at 'is_fk'/'normalized_type'
        // instead, based on inspecting the wrong stage of the pipeline
        // (SchemaIntrospector's raw shape rather than
        // IntrospectionToConfig's built shape) — caught immediately by live
        // verification (a real bigInteger column fell through to 'text'
        // instead of 'number'), reverted back to this.
        $generator = $this->makeGenerator();

        $this->assertTrue($generator->callIsForeignKey('owner_ref', ['type' => 'foreignId']));
    }

    public function test_is_foreign_key_true_via_id_suffix_naming_convention(): void
    {
        $generator = $this->makeGenerator();

        $this->assertTrue($generator->callIsForeignKey('category_id', ['type' => 'string']));
    }

    public function test_is_foreign_key_false_for_plain_field(): void
    {
        $generator = $this->makeGenerator();

        $this->assertFalse($generator->callIsForeignKey('name', ['type' => 'string']));
    }

    public function test_is_foreign_key_ignores_the_legacy_unused_boolean_foreignid_key(): void
    {
        // No producer in this codebase ever sets a boolean $field['foreignId'] key
        // (only 'type' => 'foreignId'). Confirms that shape alone -- without a real
        // 'type' => 'foreignId' and without an '_id' suffix -- is not sufficient.
        $generator = $this->makeGenerator();

        $this->assertFalse($generator->callIsForeignKey('owner_ref', ['foreignId' => true, 'type' => 'string']));
    }
}

/**
 * Minimal concrete subclass exposing the protected methods under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestBaseServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        return true;
    }

    public function callGenerateFilterableFields(): string
    {
        return $this->generateFilterableFields();
    }

    public function callGenerateSortableFields(): string
    {
        return $this->generateSortableFields();
    }

    public function callGenerateFilterFields(): string
    {
        return $this->generateFilterFields();
    }

    public function callGenerateValidationRules(bool $edit = false): string
    {
        return $this->generateValidationRules($edit);
    }

    public function callGenerateFileColumnUploads(bool $isEdit): string
    {
        return $this->generateFileColumnUploads($isEdit);
    }

    public function callIsForeignKey(string $fieldName, array $field): bool
    {
        return $this->isForeignKey($fieldName, $field);
    }

    public function callGenerateEagerLoadRelationships(string $feature): string
    {
        return $this->generateEagerLoadRelationships($feature);
    }
}
