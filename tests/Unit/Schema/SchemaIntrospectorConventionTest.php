<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use Blutrixx\GeneratorEngine\Schema\SchemaConventionDivergenceException;
use Blutrixx\GeneratorEngine\Schema\SchemaConventions;
use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the convention-driven rewrite of
 * SchemaIntrospector::meta() (2026-07-26).
 *
 * Bug this closes: meta() used to derive has_timestamps/has_soft_deletes/
 * has_uuid/has_creator_updater purely from whether the corresponding
 * column existed on the LIVE table at introspection time. This project's
 * own schema-authoring workflow instructs authors to leave those columns
 * OUT of hand-written SQL entirely ("the generator adds these to every
 * module automatically"), so the raw-presence derivation silently returned
 * `false` for every one of them on a freshly-authored table, and callers
 * that trusted meta() verbatim lost SoftDeletes/timestamps/uuid/audit
 * columns across generation waves until someone manually
 * `ALTER TABLE ... ADD COLUMN deleted_at` first.
 *
 * Fix: meta() now always returns SchemaConventions::DEFAULT_FLAGS (every
 * flag `true`, since every generated table gets all four columns by
 * convention -- see SchemaConventions' docblock for the real migrations
 * this was verified against), and instead uses the live table (when it
 * exists) only to detect DIVERGENCE from that convention, surfaced loudly
 * via SchemaConventionDivergenceException unless the caller opts out via
 * $meta[SchemaConventions::SKIP_CHECK_META_KEY].
 *
 * Exercised via the same anonymous-subclass technique
 * SchemaIntrospectorMetaTest already uses (this package has no
 * illuminate/database dev dependency to spin up a real Schema facade
 * against in tests) -- the live-schema-hitting has*() methods meta()
 * composes are stubbed with canned values standing in for "what the live
 * table actually looks like".
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::meta()
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaConventions
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaConventionDivergenceException
 */
class SchemaIntrospectorConventionTest extends TestCase
{
    /** Builds an anonymous SchemaIntrospector subclass with canned live-table answers. */
    private function introspectorWithLiveState(
        bool $exists,
        bool $hasTimestamps,
        bool $hasSoftDeletes,
        bool $hasUuid,
        bool $hasCreatorUpdater
    ): SchemaIntrospector {
        return new class(
            'zzz_convention_test',
            $exists,
            $hasTimestamps,
            $hasSoftDeletes,
            $hasUuid,
            $hasCreatorUpdater
        ) extends SchemaIntrospector {
            public function __construct(
                string $table,
                private bool $existsVal,
                private bool $timestampsVal,
                private bool $softDeletesVal,
                private bool $uuidVal,
                private bool $creatorUpdaterVal
            ) {
                parent::__construct($table);
            }

            public function exists(): bool
            {
                return $this->existsVal;
            }

            public function hasTimestamps(): bool
            {
                return $this->timestampsVal;
            }

            public function hasSoftDeletes(): bool
            {
                return $this->softDeletesVal;
            }

            public function hasUuid(): bool
            {
                return $this->uuidVal;
            }

            public function hasCreatorUpdater(): bool
            {
                return $this->creatorUpdaterVal;
            }

            public function fileColumns(): array
            {
                return [];
            }

            public function indexGroups(): array
            {
                return [];
            }
        };
    }

    // ─── 1. Convention flags applied when caller supplies no flags ───────

    public function test_meta_returns_convention_defaults_when_table_matches_convention(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: true,
            hasUuid: true,
            hasCreatorUpdater: true
        );

        $meta = $introspector->meta();

        $this->assertSame(SchemaConventions::DEFAULT_FLAGS, [
            'has_timestamps'      => $meta['has_timestamps'],
            'has_soft_deletes'    => $meta['has_soft_deletes'],
            'has_uuid'            => $meta['has_uuid'],
            'has_creator_updater' => $meta['has_creator_updater'],
        ]);
    }

    // ─── 2. Explicit caller-supplied flags still win (strict-mode precedence) ─

    public function test_explicit_caller_flag_wins_over_convention_default_when_layered_on_meta_output(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: true,
            hasUuid: true,
            hasCreatorUpdater: true
        );

        // Caller explicitly overrides has_uuid AFTER calling meta() -- this is
        // the exact mechanism a consuming project uses to force a flag for a
        // not-yet-migrated or intentionally-divergent table. build() must
        // trust the override verbatim, not the convention default.
        $meta = array_merge($introspector->meta(), [
            'has_uuid'    => false,
            'module_name' => 'ZzzConventionTest',
            'module_type' => 'Custom',
            'table_name'  => 'zzz_convention_test',
        ]);

        $config = IntrospectionToConfig::strict()->build([], $meta);

        $this->assertTrue($config['has_timestamps']);
        $this->assertTrue($config['has_soft_deletes']);
        $this->assertFalse($config['has_uuid']);
        $this->assertTrue($config['has_creator_updater']);
    }

    // ─── 3. Divergence is detected and surfaced loudly ────────────────────

    public function test_meta_throws_when_live_table_is_missing_deleted_at(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: false, // divergence: convention expects true
            hasUuid: true,
            hasCreatorUpdater: true
        );

        $this->expectException(SchemaConventionDivergenceException::class);
        $this->expectExceptionMessageMatches('/zzz_convention_test/');
        $this->expectExceptionMessageMatches('/deleted_at/');
        $this->expectExceptionMessageMatches('/skip_convention_check/');

        $introspector->meta();
    }

    public function test_meta_throws_when_live_table_is_missing_uuid(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: true,
            hasUuid: false, // divergence
            hasCreatorUpdater: true
        );

        $this->expectException(SchemaConventionDivergenceException::class);
        $this->expectExceptionMessageMatches('/has_uuid/');

        $introspector->meta();
    }

    public function test_meta_throws_when_live_table_is_missing_paired_audit_columns(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: true,
            hasUuid: true,
            hasCreatorUpdater: false // divergence
        );

        $this->expectException(SchemaConventionDivergenceException::class);
        $this->expectExceptionMessageMatches('/created_by_id/');
        $this->expectExceptionMessageMatches('/updated_by_id/');

        $introspector->meta();
    }

    public function test_meta_throws_when_live_table_is_missing_timestamps(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: false, // divergence
            hasSoftDeletes: true,
            hasUuid: true,
            hasCreatorUpdater: true
        );

        $this->expectException(SchemaConventionDivergenceException::class);
        $this->expectExceptionMessageMatches('/created_at/');

        $introspector->meta();
    }

    // ─── 4. The opt-out key suppresses the divergence signal ──────────────

    public function test_skip_convention_check_meta_key_suppresses_the_exception(): void
    {
        $introspector = $this->introspectorWithLiveState(
            exists: true,
            hasTimestamps: true,
            hasSoftDeletes: false, // would normally diverge
            hasUuid: true,
            hasCreatorUpdater: true
        );

        $meta = $introspector->meta(['skip_convention_check' => true]);

        // No exception thrown, AND the returned flag is still the convention
        // default (true) -- the opt-out silences the loud check, it does not
        // by itself change what meta() reports. A caller who wants the
        // returned flag to reflect this table's real (divergent) layout must
        // still explicitly override it afterward, same as any other flag.
        $this->assertTrue($meta['has_soft_deletes']);
    }

    public function test_opt_out_key_is_read_from_the_same_meta_bag_key_defined_on_schema_conventions(): void
    {
        $this->assertSame('skip_convention_check', SchemaConventions::SKIP_CHECK_META_KEY);
    }

    // ─── 5. Missing table (not yet created) is tolerated ──────────────────

    public function test_missing_table_never_throws_and_convention_flags_are_not_applied(): void
    {
        // Table-does-not-exist keeps its pre-existing "all false" contract
        // (see SchemaIntrospectorMetaTest) -- convention flags only ever
        // apply to a live-inspectable table; a not-yet-migrated table's
        // meta() output is expected to be overridden explicitly by the
        // caller (this is exactly what IntrospectionToConfigMetaWiringTest's
        // "not yet migrated" scenario already exercises).
        $introspector = new class('zzz_not_yet_created') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }

            public function hasTimestamps(): bool
            {
                throw new \RuntimeException('must not be called when table is missing');
            }

            public function hasSoftDeletes(): bool
            {
                throw new \RuntimeException('must not be called when table is missing');
            }

            public function hasUuid(): bool
            {
                throw new \RuntimeException('must not be called when table is missing');
            }

            public function hasCreatorUpdater(): bool
            {
                throw new \RuntimeException('must not be called when table is missing');
            }
        };

        $meta = $introspector->meta();

        $this->assertFalse($meta['has_timestamps']);
        $this->assertFalse($meta['has_soft_deletes']);
        $this->assertFalse($meta['has_uuid']);
        $this->assertFalse($meta['has_creator_updater']);
    }

    public function test_missing_table_never_throws_even_when_opt_out_key_is_absent(): void
    {
        // Explicitly proves point 4 of the spec: absence of a table is not a
        // divergence and must never require the opt-out key to avoid
        // throwing.
        $introspector = new class('zzz_bulk_scaffold_target') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }
        };

        $meta = $introspector->meta([]); // no skip_convention_check, no throw expected

        $this->assertIsArray($meta);
    }
}
