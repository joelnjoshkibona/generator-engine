<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for three of the "information-loss gaps" fixed
 * together: decimal precision/scale, composite unique constraints, and enum
 * columns all losing their real DB metadata on the way into the generated
 * config.
 *
 * All three fixes are pure, DB-free static helpers (mirroring
 * filterFileColumns()'s established pattern in this class — see
 * SchemaIntrospectorFileColumnsTest for the same rationale: this package has
 * no illuminate/database dev dependency to spin up a real Schema facade
 * against in tests), so they're exercised directly here against hand-built
 * Schema::getColumns() / Schema::getIndexes()-shaped fixtures.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::extractPrecisionScale()
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::extractEnumValues()
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::normalizeIndexGroups()
 */
class SchemaIntrospectorPrecisionScaleEnumIndexTest extends TestCase
{
    // ─── extractPrecisionScale() ────────────────────────────────────────

    public function test_decimal_with_precision_and_scale_is_extracted(): void
    {
        $result = SchemaIntrospector::extractPrecisionScale(['type' => 'decimal(12,4)']);

        $this->assertSame(12, $result['precision']);
        $this->assertSame(4, $result['scale']);
    }

    public function test_decimal_with_precision_scale_and_unsigned_suffix_is_still_extracted(): void
    {
        $result = SchemaIntrospector::extractPrecisionScale(['type' => 'decimal(12,4) unsigned']);

        $this->assertSame(12, $result['precision']);
        $this->assertSame(4, $result['scale']);
    }

    public function test_the_previously_masking_10_2_fixture_shape_still_extracts_correctly(): void
    {
        // The exact shape that masked this bug in the first place: a
        // decimal(10,2) column happens to match the old hardcoded default,
        // so the bug was invisible until a DIFFERENT precision/scale was used.
        $result = SchemaIntrospector::extractPrecisionScale(['type' => 'decimal(10,2)']);

        $this->assertSame(10, $result['precision']);
        $this->assertSame(2, $result['scale']);
    }

    public function test_non_decimal_single_number_type_yields_null_precision_and_scale(): void
    {
        // varchar(255) / int(11) etc. -- single-number parens must NOT be
        // mistaken for a (precision, scale) pair.
        $this->assertSame(
            ['precision' => null, 'scale' => null],
            SchemaIntrospector::extractPrecisionScale(['type' => 'varchar(255)'])
        );
        $this->assertSame(
            ['precision' => null, 'scale' => null],
            SchemaIntrospector::extractPrecisionScale(['type' => 'int(11)'])
        );
    }

    public function test_missing_type_key_yields_null_precision_and_scale(): void
    {
        $this->assertSame(
            ['precision' => null, 'scale' => null],
            SchemaIntrospector::extractPrecisionScale([])
        );
    }

    // ─── extractEnumValues() ────────────────────────────────────────────

    public function test_enum_values_are_extracted_in_order(): void
    {
        $result = SchemaIntrospector::extractEnumValues(['type' => "enum('active','inactive','pending')"]);

        $this->assertSame(['active', 'inactive', 'pending'], $result);
    }

    public function test_enum_values_with_escaped_quote_are_unescaped(): void
    {
        // MySQL DDL renders an embedded literal quote inside an enum value
        // backslash-escaped (\') -- the form our regex targets.
        $result = SchemaIntrospector::extractEnumValues(['type' => "enum('it\\'s','ok')"]);

        $this->assertSame(["it's", 'ok'], $result);
    }

    public function test_non_enum_type_returns_null(): void
    {
        $this->assertNull(SchemaIntrospector::extractEnumValues(['type' => 'varchar(255)']));
        $this->assertNull(SchemaIntrospector::extractEnumValues([]));
    }

    // ─── normalizeType() enum branch ────────────────────────────────────

    public function test_enum_raw_type_normalizes_to_enum(): void
    {
        $introspector = new SchemaIntrospector('irrelevant_table');
        $method = new \ReflectionMethod(SchemaIntrospector::class, 'normalizeType');
        $method->setAccessible(true);

        $this->assertSame('enum', $method->invoke($introspector, 'enum', 'status', false));
    }

    // ─── normalizeIndexGroups() ─────────────────────────────────────────

    public function test_primary_key_index_is_excluded(): void
    {
        $raw = [
            ['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'products_sku_unique', 'columns' => ['sku'], 'unique' => true, 'primary' => false],
        ];

        $result = SchemaIntrospector::normalizeIndexGroups($raw);

        $this->assertCount(1, $result);
        $this->assertSame(['sku'], $result[0]['columns']);
    }

    public function test_composite_unique_index_is_preserved_with_all_its_columns(): void
    {
        $raw = [
            ['name' => 'products_tenant_id_sku_unique', 'columns' => ['tenant_id', 'sku'], 'unique' => true, 'primary' => false],
        ];

        $result = SchemaIntrospector::normalizeIndexGroups($raw);

        $this->assertSame([
            ['name' => 'products_tenant_id_sku_unique', 'columns' => ['tenant_id', 'sku'], 'unique' => true],
        ], $result);
    }

    public function test_non_unique_index_is_preserved_with_unique_false(): void
    {
        $raw = [
            ['name' => 'idx_products_category_id', 'columns' => ['category_id'], 'unique' => false, 'primary' => false],
        ];

        $result = SchemaIntrospector::normalizeIndexGroups($raw);

        $this->assertSame([
            ['name' => 'idx_products_category_id', 'columns' => ['category_id'], 'unique' => false],
        ], $result);
    }

    public function test_index_with_no_columns_is_dropped(): void
    {
        $raw = [
            ['name' => 'weird', 'columns' => [], 'unique' => false, 'primary' => false],
        ];

        $this->assertSame([], SchemaIntrospector::normalizeIndexGroups($raw));
    }
}
