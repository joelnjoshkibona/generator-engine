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

    public function test_filterable_fields_always_includes_id_and_uuid(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'parent_id', 'status_id'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'parent_id', 'status_id', 'id', 'uuid']", $result);
    }

    public function test_filterable_fields_does_not_duplicate_id_or_uuid_if_already_configured(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'id', 'uuid'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'id', 'uuid']", $result);
        $this->assertSame(1, substr_count($result, "'id'"));
        $this->assertSame(1, substr_count($result, "'uuid'"));
    }

    public function test_filterable_fields_from_filterfields_config_still_appends_id_and_uuid(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterFields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ],
            ]]],
        ]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['name', 'id', 'uuid']", $result);
    }

    public function test_filterable_fields_with_no_config_still_yields_id_and_uuid(): void
    {
        $generator = $this->makeGenerator([]);

        $result = $generator->callGenerateFilterableFields();

        $this->assertSame("['id', 'uuid']", $result);
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

    public function test_filter_fields_appends_id_entry_for_frontend_filter_control(): void
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
        // "uuid" must never appear in the frontend-facing filter fields output.
        $this->assertStringNotContainsString('uuid', $result);
        $this->assertSame(1, substr_count($result, "'key' => \"id\""));
    }

    public function test_filter_fields_derived_fallback_still_appends_id_and_never_uuid(): void
    {
        // Simulates an introspected module: no explicit filterFields, only
        // filterableFields (which, per IntrospectionToConfig, never contains
        // "id"/"uuid" since both are excluded system columns).
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterableFields' => ['name', 'parent_id'],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"name\"", $result);
        $this->assertStringContainsString("'key' => \"parent_id\"", $result);
        $this->assertStringContainsString("'key' => \"id\"", $result);
        $this->assertStringNotContainsString('uuid', $result);
    }

    public function test_filter_fields_does_not_duplicate_id_if_already_configured(): void
    {
        $generator = $this->makeGenerator([
            'features' => ['backend' => ['list' => [
                'filterFields' => [
                    ['key' => 'id', 'label' => 'Custom ID Label', 'type' => 'number'],
                ],
            ]]],
        ]);

        $result = $generator->callGenerateFilterFields();

        $this->assertSame(1, substr_count($result, "'key' => \"id\""));
        // The caller-supplied entry must win — not the auto-appended default.
        $this->assertStringContainsString("'label' => \"Custom ID Label\"", $result);
    }

    public function test_filter_fields_with_no_config_at_all_still_yields_id_only(): void
    {
        $generator = $this->makeGenerator([]);

        $result = $generator->callGenerateFilterFields();

        $this->assertStringContainsString("'key' => \"id\"", $result);
        $this->assertStringNotContainsString('uuid', $result);
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
}
