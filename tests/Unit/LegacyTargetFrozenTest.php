<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit;

use Blutrixx\GeneratorEngine\Tests\LegacyManifest;
use PHPUnit\Framework\TestCase;

/**
 * BOOTBOX_PLAN D8: the legacy (SYSTEM_SHELL) target is frozen, bug fixes only. Its files are checked against
 * tests/legacy-frozen.json; a bug fix records itself in the changelog and runs bin/freeze-legacy.
 */
class LegacyTargetFrozenTest extends TestCase
{
    public function test_the_legacy_target_is_unchanged_or_its_fix_is_recorded(): void
    {
        require_once dirname(__DIR__).'/LegacyManifest.php';
        $root = dirname(__DIR__, 2);
        $frozen = json_decode((string) file_get_contents("{$root}/tests/legacy-frozen.json"), true);
        $now = LegacyManifest::compute($root);

        $changed = array_keys(array_diff_assoc($now, $frozen));
        $removed = array_keys(array_diff_key($frozen, $now));
        $this->assertSame([], [...$changed, ...$removed], "The legacy target is frozen (D8): bug fixes only.\n"
            ."For a bug fix, add it under \"### Legacy target (frozen): fixed\" in CHANGELOG.md's top entry and run bin/freeze-legacy.\n"
            .'New work goes into the Bootbox target (src/Targets/Bootbox, src/Core).');
    }
}
