<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for IntrospectionToConfig's strict-mode $meta
 * validation.
 *
 * Bug this guards against (ROOT CAUSE A): build() used to read
 * `$meta['has_soft_deletes'] ?? false` (and the equivalent
 * array_key_exists() pattern for has_timestamps/has_uuid/has_creator_updater)
 * with no way to tell "the caller explicitly said false" apart from "the
 * caller forgot to pass this key at all". A downstream command discarded the
 * live introspection result and never threaded 'has_soft_deletes' into
 * $meta — every generated module silently lost SoftDeletes, and nobody
 * found out until migrations were hand-patched three times.
 *
 * Fix: IntrospectionToConfig::strict() (opt-in, via the strict-mode
 * constructor flag) raises InvalidArgumentException the moment a
 * schema-derived flag key is entirely absent from $meta, and also rejects
 * unknown/typo'd top-level $meta keys (both modes) so e.g. 'has_soft_delete'
 * fails loudly instead of being silently ignored while 'has_soft_deletes'
 * quietly defaults.
 *
 * The plain `new IntrospectionToConfig()` / IntrospectionToConfig::lenient()
 * constructors keep the pre-existing, unchanged, silently-defaulting
 * behaviour on purpose — every other test in this suite (and every real
 * caller outside this package) already relies on that behaviour, so it is
 * NOT the default strictness. See IntrospectionToConfig's constructor
 * docblock for the full rationale.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig
 */
class IntrospectionToConfigStrictModeTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function columns(): array
    {
        return [
            [
                'name'            => 'name',
                'type'            => 'varchar',
                'normalized_type' => 'string',
                'length'          => 255,
                'nullable'        => false,
                'default'         => null,
                'is_fk'           => false,
                'foreign_table'   => null,
                'foreign_column'  => null,
                'is_unique'       => false,
                'morph_role'      => null,
                'morph_name'      => null,
            ],
        ];
    }

    /** Minimal required-only meta bag; deliberately omits every strict flag. */
    private function requiredOnlyMeta(): array
    {
        return [
            'module_name' => 'ZzzStrictModeTest',
            'module_type' => 'Core',
            'table_name'  => 'zzz_strict_mode_tests',
        ];
    }

    /** Full meta bag with every schema-derived flag explicitly present. */
    private function fullMeta(array $overrides = []): array
    {
        return array_merge([
            'module_name'          => 'ZzzStrictModeTest',
            'module_type'          => 'Core',
            'table_name'           => 'zzz_strict_mode_tests',
            'has_timestamps'       => true,
            'has_soft_deletes'     => false,
            'has_uuid'             => true,
            'has_creator_updater'  => true,
        ], $overrides);
    }

    // ─── (a) omitting has_soft_deletes is an error in strict mode ─────────

    public function test_strict_mode_rejects_meta_missing_has_soft_deletes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has_soft_deletes/');

        IntrospectionToConfig::strict()->build($this->columns(), $this->requiredOnlyMeta());
    }

    public function test_strict_mode_rejects_meta_missing_all_four_derived_flags_and_lists_them(): void
    {
        try {
            IntrospectionToConfig::strict()->build($this->columns(), $this->requiredOnlyMeta());
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('has_timestamps', $e->getMessage());
            $this->assertStringContainsString('has_soft_deletes', $e->getMessage());
            $this->assertStringContainsString('has_uuid', $e->getMessage());
            $this->assertStringContainsString('has_creator_updater', $e->getMessage());
        }
    }

    public function test_lenient_mode_still_accepts_meta_missing_the_derived_flags(): void
    {
        // Default constructor / ::lenient() must behave exactly like before
        // this change -- every pre-existing caller relies on this.
        $config = (new IntrospectionToConfig())->build($this->columns(), $this->requiredOnlyMeta());
        $this->assertFalse($config['has_soft_deletes']);

        $configExplicitLenient = IntrospectionToConfig::lenient()->build($this->columns(), $this->requiredOnlyMeta());
        $this->assertFalse($configExplicitLenient['has_soft_deletes']);
    }

    // ─── (b) an explicit false is accepted and preserved ───────────────────

    public function test_strict_mode_accepts_explicit_false_and_preserves_it(): void
    {
        $config = IntrospectionToConfig::strict()->build(
            $this->columns(),
            $this->fullMeta(['has_soft_deletes' => false])
        );

        $this->assertArrayHasKey('has_soft_deletes', $config);
        $this->assertFalse($config['has_soft_deletes']);
    }

    public function test_strict_mode_accepts_explicit_true_and_preserves_it(): void
    {
        $config = IntrospectionToConfig::strict()->build(
            $this->columns(),
            $this->fullMeta(['has_soft_deletes' => true])
        );

        $this->assertTrue($config['has_soft_deletes']);
    }

    // ─── (c) a typo'd key is rejected with a helpful message ───────────────

    public function test_typo_d_meta_key_is_rejected_with_a_suggestion(): void
    {
        $meta = $this->fullMeta();
        unset($meta['has_soft_deletes']);
        $meta['has_soft_delete'] = false; // typo: missing trailing 's'

        try {
            IntrospectionToConfig::strict()->build($this->columns(), $meta);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('has_soft_delete', $e->getMessage());
            $this->assertStringContainsString('has_soft_deletes', $e->getMessage());
        }
    }

    public function test_typo_d_meta_key_is_rejected_even_in_lenient_mode(): void
    {
        // Unknown-key rejection is not gated behind strict mode -- a typo is
        // never intentional in either mode.
        $meta = $this->requiredOnlyMeta();
        $meta['has_soft_delete'] = false;

        $this->expectException(\InvalidArgumentException::class);

        (new IntrospectionToConfig())->build($this->columns(), $meta);
    }

    public function test_unrelated_typo_gets_no_misleading_suggestion(): void
    {
        $meta = $this->requiredOnlyMeta();
        $meta['zzz_totally_unrelated_garbage_key'] = true;

        try {
            (new IntrospectionToConfig())->build($this->columns(), $meta);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }
}
