<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;

/**
 * BaseGeneratorFrontendOptOutTest proves the guard works for the helpers it lives in; this proves nothing has
 * learned to go around it.
 *
 * A generator that calls file_put_contents()/unlink()/rename()/copy() itself is invisible to
 * BaseGenerator::isBlockedFrontendPath(): it would create, replace or delete files in the frontend of a module
 * that opted out of it (`features.frontend.enabled: false`), and nothing would notice until a hand-written page
 * was gone. Two of exactly those existed (FrontendLocaleGenerator, PlaywrightTestGenerator's legacy-spec cleanup)
 * and were routed through putFile()/removeFile().
 *
 * The rule is structural, not a list to keep up to date: outside BaseGenerator, a file may make a raw write only
 * if it never refers to the frontend tree at all (no PathManager::getFrontend*() call, no "FRONTEND" literal).
 * Backend, mobile and menu-seed generators qualify; a frontend generator never can.
 */
class FrontendWritesGoThroughTheOptOutGuardTest extends TestCase
{
    private const RAW_WRITES = ['file_put_contents', 'unlink', 'rename', 'copy'];

    /** @return array<string, array{raw: string[], frontendRefs: string[], usesPatchesRegions: bool}> keyed by path under src/Generators */
    private function scan(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Generators';
        $result = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            // Comments and whitespace out, so a docblock that merely mentions file_put_contents() or FRONTEND cannot count.
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
            ));

            $raw = [];
            $frontendRefs = [];
            $usesPatchesRegions = false;
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                $previous = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                $isMemberOrDeclaration = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

                if ($token[0] === T_STRING && in_array($token[1], self::RAW_WRITES, true) && $next === '(' && !$isMemberOrDeclaration) {
                    $raw[] = $token[1] . '()';
                }
                if ($token[0] === T_STRING && str_starts_with($token[1], 'getFrontend')) {
                    $frontendRefs[] = $token[1] . '()';
                }
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], 'FRONTEND')) {
                    $frontendRefs[] = $token[1];
                }
                if ($token[0] === T_STRING && $token[1] === 'PatchesRegions') {
                    $usesPatchesRegions = true;
                }
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $result[$relative] = ['raw' => $raw, 'frontendRefs' => $frontendRefs, 'usesPatchesRegions' => $usesPatchesRegions];
        }

        ksort($result);

        return $result;
    }

    public function test_the_scan_sees_the_generators_and_the_guarded_helpers(): void
    {
        // Guards the guard: a wrong path or a broken tokenizer would make the next tests pass over nothing.
        $scan = $this->scan();

        $this->assertGreaterThan(40, count($scan));
        $this->assertNotEmpty($scan['BaseGenerator.php']['raw'], 'BaseGenerator owns the writes; the scan should see them');
        $this->assertNotEmpty($scan['BaseGenerator.php']['frontendRefs'], 'and it is the one file that knows the frontend root');
    }

    public function test_no_generator_writes_or_deletes_files_itself_unless_it_never_touches_the_frontend(): void
    {
        $offenders = [];
        foreach ($this->scan() as $file => $facts) {
            if ($file === 'BaseGenerator.php' || $facts['raw'] === []) {
                continue;
            }
            if ($facts['frontendRefs'] !== []) {
                $offenders[] = "{$file}: " . implode(', ', array_unique($facts['raw'])) . ' next to ' . implode(', ', array_unique($facts['frontendRefs']));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These generators write or delete files themselves AND refer to the frontend tree, so they can change an opted-out "
            . "module's hand-written frontend past BaseGenerator's guard. Use writeFile()/writeFileOnce()/writeFileAlways()/"
            . "putFile()/removeFile() instead."
        );
    }

    public function test_the_region_patching_trait_is_only_used_by_generators_that_never_touch_the_frontend(): void
    {
        // PatchesRegions rewrites a file it is handed and cannot tell whose frontend it is; that is safe only while
        // every user hands it backend files.
        $offenders = [];
        foreach ($this->scan() as $file => $facts) {
            if ($file !== 'PatchesRegions.php' && $facts['usesPatchesRegions'] && $facts['frontendRefs'] !== []) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'PatchesRegions writes without the opt-out guard; do not use it from a generator that touches the frontend.');
    }
}
