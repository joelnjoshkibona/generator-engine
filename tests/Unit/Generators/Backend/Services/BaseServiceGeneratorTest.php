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
}
