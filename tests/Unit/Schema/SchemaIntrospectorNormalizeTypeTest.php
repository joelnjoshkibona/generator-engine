<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for SchemaIntrospector::normalizeType() — the actual
 * root cause of the "bogus relation guessing" defect.
 *
 * Bug: normalizeType() used to classify ANY column ending in "_id" as
 * 'foreignId' unconditionally — `if ($isFk || Str::endsWith($colName, '_id'))`
 * — even when $isFk was false (i.e. no real DB foreign key constraint AND no
 * table matching the naming convention was found by inferFkByConvention()).
 *
 * This mis-tagged plain business/string columns that merely happen to end in
 * "_id" as if they were real foreign keys. Confirmed for `external_trans_id`
 * on the real `ledger_transactions` table: a VARCHAR idempotency key with no
 * "external_trans"/"external_transes" table anywhere — yet normalizeType()
 * still returned 'foreignId' for it. Downstream,
 * ModelGenerator::generateAutoRelationshipsFromForeignIds() treats any
 * 'foreignId'-typed column as a real FK and, finding relatedModule empty (as
 * resolveRelatedModule() correctly leaves it when is_fk is false), falls
 * back to GUESSING a module name from the column name — producing a
 * belongsTo() pointing at a nonexistent "ExternalTrans" module.
 *
 * Fix: normalizeType() now trusts ONLY $isFk (which already covers both a
 * real FK constraint and "ends in _id AND a matching table actually
 * exists" via inferFkByConvention()) — a bare "_id" suffix with no
 * corroborating evidence no longer forces 'foreignId'.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::normalizeType()
 */
class SchemaIntrospectorNormalizeTypeTest extends TestCase
{
    private function callNormalizeType(string $rawType, string $colName, bool $isFk): string
    {
        $introspector = new SchemaIntrospector('irrelevant_table');

        $method = new ReflectionMethod(SchemaIntrospector::class, 'normalizeType');
        $method->setAccessible(true);

        return $method->invoke($introspector, $rawType, $colName, $isFk);
    }

    public function test_id_suffixed_string_column_with_no_fk_evidence_is_not_forced_to_foreign_id(): void
    {
        // "external_trans_id" — a VARCHAR idempotency key, no real FK, no
        // matching table. is_fk is false because neither a real constraint
        // nor inferFkByConvention() found a target table.
        $result = $this->callNormalizeType('varchar', 'external_trans_id', false);

        $this->assertNotSame('foreignId', $result, 'A bare "_id" name suffix must never override the real column type when there is no FK evidence.');
        $this->assertSame('string', $result);
    }

    public function test_id_suffixed_integer_column_with_no_fk_evidence_is_not_forced_to_foreign_id(): void
    {
        // Guards the same bug for an integer-typed "_id" column that still
        // has no FK evidence (e.g. a plain sequence/counter column).
        $result = $this->callNormalizeType('bigint', 'retry_id', false);

        $this->assertSame('bigInteger', $result);
    }

    public function test_id_suffixed_column_with_real_fk_evidence_is_still_foreign_id(): void
    {
        // A genuine FK (real constraint, or "_id" convention matched an
        // existing table) must still be classified as 'foreignId' — the fix
        // must not regress real relation detection.
        $result = $this->callNormalizeType('bigint', 'category_id', true);

        $this->assertSame('foreignId', $result);
    }

    public function test_non_id_column_is_unaffected(): void
    {
        $result = $this->callNormalizeType('varchar', 'title', false);

        $this->assertSame('string', $result);
    }
}
