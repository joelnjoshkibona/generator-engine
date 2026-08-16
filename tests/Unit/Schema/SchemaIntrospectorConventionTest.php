<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use Blutrixx\GeneratorEngine\Schema\SchemaConventions;
use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for SchemaIntrospector::meta()'s unconditional
 * convention behavior (2026-08-16, superseding the 2026-07-26
 * divergence-check design this file used to cover).
 *
 * Bug the 2026-07-26 design closed: meta() used to derive
 * has_timestamps/has_soft_deletes/has_uuid/has_creator_updater purely from
 * whether the corresponding column existed on the LIVE table at
 * introspection time -- silently returning `false` for every one of them on
 * a freshly-authored table, since this project's schema-authoring workflow
 * instructs authors to leave those columns OUT of hand-written SQL entirely.
 *
 * Why the divergence check was then removed rather than kept: audit columns
 * are not user-configurable business data any more than they're an option
 * in the module wizard UI -- a module built through the UI never lets an
 * author add/omit id/uuid/timestamps, they're simply always there. Treating
 * a real table's audit-column layout as something to verify (and refuse to
 * proceed without matching) made a deliberately opinionated convention
 * negotiable per-table, with no way to reach the actual escape hatch from
 * SYSTEM_SHELL's `make:modules-from-db` CLI at all -- any table missing even
 * one conventional column hard-failed introspection entirely, with no path
 * forward short of hand-patching this package's PHP source.
 *
 * Current behavior: meta() returns SchemaConventions::DEFAULT_FLAGS
 * unconditionally for an existing table -- no live-schema check of the
 * audit columns, no divergence exception, nothing to opt out of.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaIntrospector::meta()
 * @see \Blutrixx\GeneratorEngine\Schema\SchemaConventions
 */
class SchemaIntrospectorConventionTest extends TestCase
{
    // ─── 1. Convention flags are always applied for an existing table ────

    public function test_meta_returns_convention_defaults_for_an_existing_table(): void
    {
        $introspector = new class('zzz_convention_test') extends SchemaIntrospector {
            public function exists(): bool
            {
                return true;
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

        $meta = $introspector->meta();

        $this->assertSame(SchemaConventions::DEFAULT_FLAGS, [
            'has_timestamps'      => $meta['has_timestamps'],
            'has_soft_deletes'    => $meta['has_soft_deletes'],
            'has_uuid'            => $meta['has_uuid'],
            'has_creator_updater' => $meta['has_creator_updater'],
        ]);
    }

    public function test_meta_never_throws_regardless_of_what_the_caller_passes_in(): void
    {
        // meta()'s $meta parameter is accepted only for call-site
        // compatibility -- it has no effect on the return value and can
        // never trigger an exception, unlike the pre-2026-08-16 design.
        $introspector = new class('zzz_convention_test') extends SchemaIntrospector {
            public function exists(): bool
            {
                return true;
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

        $meta = $introspector->meta(['anything' => 'is-ignored', 'skip_convention_check' => false]);

        $this->assertTrue($meta['has_soft_deletes']);
    }

    // ─── 2. Explicit caller-supplied flags still win (strict-mode precedence) ─

    public function test_explicit_caller_flag_wins_over_convention_default_when_layered_on_meta_output(): void
    {
        $introspector = new class('zzz_convention_test') extends SchemaIntrospector {
            public function exists(): bool
            {
                return true;
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

        // Caller explicitly overrides has_uuid AFTER calling meta() -- the
        // mechanism a consuming project uses to force a flag for a
        // deliberately-divergent table it has chosen to accommodate.
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

    // ─── 3. Missing table (not yet created) is tolerated ──────────────────

    public function test_missing_table_never_throws_and_convention_flags_are_not_applied(): void
    {
        // Table-does-not-exist keeps its own "all false" contract --
        // convention flags only ever apply to a live-inspectable table; a
        // not-yet-migrated table's meta() output is expected to be
        // overridden explicitly by the caller (this is exactly what
        // IntrospectionToConfigMetaWiringTest's "not yet migrated" scenario
        // already exercises).
        $introspector = new class('zzz_not_yet_created') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }
        };

        $meta = $introspector->meta();

        $this->assertFalse($meta['has_timestamps']);
        $this->assertFalse($meta['has_soft_deletes']);
        $this->assertFalse($meta['has_uuid']);
        $this->assertFalse($meta['has_creator_updater']);
    }

    public function test_missing_table_never_throws_with_an_empty_meta_array(): void
    {
        $introspector = new class('zzz_bulk_scaffold_target') extends SchemaIntrospector {
            public function exists(): bool
            {
                return false;
            }
        };

        $meta = $introspector->meta([]);

        $this->assertIsArray($meta);
    }
}
