<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for SchemaIntrospector::meta() — the single reliable
 * source for every schema-derived $meta key IntrospectionToConfig::build()
 * reads, replacing the hand-assembled $meta array every consumer call site
 * used to build by hand.
 *
 * Bug this closes: SchemaIntrospector::indexGroups() existed and
 * IntrospectionToConfig::build() already read $meta['index_groups'], but no
 * consumer ever passed it — composite unique constraints and indexes
 * silently vanished from every generated migration. meta() collects every
 * schema-derived key build() understands in one call, so a future new key
 * only needs wiring here once instead of at every call site.
 *
 * meta() is orchestration over this class's own public exists()/
 * fileColumns()/indexGroups() methods (each already hitting the live schema
 * connection on its own) plus SchemaConventions::DEFAULT_FLAGS for the four
 * audit-column booleans — those are now hardcoded convention constants, not
 * derived from any live-schema call (see meta()'s own docblock: no per-table
 * check of the real audit columns happens at all, existing table or not).
 * This package has no illuminate/database dev dependency to spin up a real
 * Schema facade against in tests (the same constraint
 * SchemaIntrospectorFileColumnsTest's docblock documents for
 * filterFileColumns()), so meta()'s ORCHESTRATION logic — not the
 * underlying live-schema calls it composes, which are each covered
 * elsewhere on their own terms — is exercised here via an anonymous
 * subclass that overrides exists()/fileColumns()/indexGroups() with canned
 * values. This is a legitimate, common technique for unit-testing
 * composition logic in a class with no constructor-injected seam, and it
 * exactly matches meta()'s actual contract: "call these methods, merge in
 * the fixed convention flags, and shape the result into this array".
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::meta()
 */
class SchemaIntrospectorMetaTest extends TestCase
{
    // ─── missing table ──────────────────────────────────────────────────

    public function test_meta_returns_all_defaults_and_does_not_throw_when_table_is_missing(): void
    {
        // exists() => false must short-circuit meta() before it ever calls
        // fileColumns()/indexGroups() -- each throws here so the test fails
        // loudly if that guarantee regresses.
        $introspector = new class('zzz_table_that_does_not_exist') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }

            public function fileColumns(): array
            {
                throw new \RuntimeException('must not be called when table is missing');
            }

            public function indexGroups(): array
            {
                throw new \RuntimeException('must not be called when table is missing');
            }
        };

        $meta = $introspector->meta();

        $this->assertSame([
            'has_timestamps'      => false,
            'has_soft_deletes'    => false,
            'has_uuid'            => false,
            'has_creator_updater' => false,
            'file_columns'        => [],
            'index_groups'        => [],
        ], $meta);
    }

    // ─── existing table ─────────────────────────────────────────────────

    public function test_meta_collects_every_schema_derived_key_for_an_existing_table(): void
    {
        $introspector = new class('zzz_meta_tests') extends SchemaIntrospector {
            public function exists(): bool
            {
                return true;
            }

            public function fileColumns(): array
            {
                return ['avatar'];
            }

            public function indexGroups(): array
            {
                return [
                    ['name' => 'zzz_meta_tests_name_avatar_unique', 'columns' => ['name', 'avatar'], 'unique' => true],
                ];
            }
        };

        $meta = $introspector->meta();

        $this->assertSame([
            'has_timestamps'      => true,
            'has_soft_deletes'    => true,
            'has_uuid'            => true,
            'has_creator_updater' => true,
            'file_columns'        => ['avatar'],
            'index_groups'        => [
                ['name' => 'zzz_meta_tests_name_avatar_unique', 'columns' => ['name', 'avatar'], 'unique' => true],
            ],
        ], $meta);
    }

    public function test_meta_keys_are_exactly_the_six_schema_derived_keys_build_reads(): void
    {
        $introspector = new class('zzz_missing_table') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }
        };

        $meta = $introspector->meta();

        $this->assertSame(
            ['has_timestamps', 'has_soft_deletes', 'has_uuid', 'has_creator_updater', 'file_columns', 'index_groups'],
            array_keys($meta)
        );
    }
}
