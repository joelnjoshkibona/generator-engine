<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\PatchesRegions;
use PHPUnit\Framework\TestCase;

/**
 * The hand-* region migration (engine v3.5.17) needs to split existing
 * region content into statement/member chunks, compare a chunk's code
 * against what the generator would produce today (ignoring whitespace and
 * comments), and tell a commented chunk apart from a bare one. These are
 * the token-based primitives that make that possible without ever
 * re-splitting on raw braces, which a string literal like `'}'` or an
 * attribute `#[...]` would break.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\PatchesRegions
 */
class PatchesRegionsSplitTest extends TestCase
{
    private function harness(): object
    {
        return new class {
            use PatchesRegions;

            public function callExtractRegion(string $fileContent, string $regionName): ?string
            {
                return $this->extractRegion($fileContent, $regionName);
            }

            public function callRegionMarkerCount(string $fileContent, string $regionName): int
            {
                return $this->regionMarkerCount($fileContent, $regionName);
            }

            public function callSplitPhpStatements(string $code): array
            {
                return $this->splitPhpStatements($code);
            }

            public function callSplitClassMembers(string $code): array
            {
                return $this->splitClassMembers($code);
            }

            public function callCodeSignature(string $chunk): string
            {
                return $this->codeSignature($chunk);
            }

            public function callHasComment(string $chunk): bool
            {
                return $this->hasComment($chunk);
            }

            public function callRenderRegion(string $name, string $inner, string $indent = ''): string
            {
                return $this->renderRegion($name, $inner, $indent);
            }
        };
    }

    public function test_a_users_shape_region_splits_into_two_chunks_with_the_comment_on_the_first(): void
    {
        $code = <<<'PHP'
            // Splash routes, hand-maintained like the custom-routes region below
            Route::middleware(['auth:sanctum'])
                ->get('/users/create/splash', [UsersController::class, 'createSplash']);
            Route::get('/users/edit/splash', [UsersController::class, 'editSplash']);
            PHP;

        $chunks = $this->harness()->callSplitPhpStatements($code);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('// Splash routes, hand-maintained', $chunks[0]);
        $this->assertStringContainsString('createSplash', $chunks[0]);
        $this->assertStringNotContainsString('createSplash', $chunks[1]);
        $this->assertStringContainsString('editSplash', $chunks[1]);
    }

    public function test_a_closure_route_is_a_single_chunk(): void
    {
        $code = "Route::get('/x', function () { return 1; });";

        $chunks = $this->harness()->callSplitPhpStatements($code);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('return 1', $chunks[0]);
    }

    public function test_class_members_are_named_by_function_name_with_a_trailing_comment_unnamed(): void
    {
        $code = <<<'PHP'
            /** {version} */
            public function a(): void
            {
            }

            public function b(): string
            {
                return '}';
            }

            public function c(): void
            {
                $x = 1;
                $s = "{$x}";
                $cb = function () { return 1; };
            }

            #[\Override]
            public function d(): void
            {
            }

            // comment
            PHP;

        $chunks = $this->harness()->callSplitClassMembers($code);

        $this->assertSame(['a', 'b', 'c', 'd', null], array_column($chunks, 'name'));
        $this->assertStringContainsString('{version}', $chunks[0]['text']);
        $this->assertStringContainsString("return '}';", $chunks[1]['text']);
        $this->assertStringContainsString('#[\Override]', $chunks[3]['text']);
        $this->assertSame('// comment', trim($chunks[4]['text']));
    }

    public function test_codeSignature_ignores_whitespace_and_comments_but_hasComment_still_tells_them_apart(): void
    {
        $bare = "public function foo(): void\n{\n    return;\n}";
        $reindented = "public function foo(): void {\n        return;\n    }";
        $docblocked = "/** A docblock. */\n    public function foo(): void\n    {\n        return;\n    }";

        $h = $this->harness();

        $this->assertSame($h->callCodeSignature($bare), $h->callCodeSignature($reindented));
        $this->assertSame($h->callCodeSignature($bare), $h->callCodeSignature($docblocked));

        $this->assertFalse($h->callHasComment($bare));
        $this->assertFalse($h->callHasComment($reindented));
        $this->assertTrue($h->callHasComment($docblocked));
    }

    public function test_extractRegion_marker_count_and_renderRegion(): void
    {
        $h = $this->harness();

        $withMarkers = "before\n// [generator:region:hand-routes:start]\n  inner text  \n// [generator:region:hand-routes:end]\nafter";
        $this->assertSame("\n  inner text  \n", $h->callExtractRegion($withMarkers, 'hand-routes'));

        $this->assertNull($h->callExtractRegion('no markers here', 'hand-routes'));

        $onlyStart = "// [generator:region:hand-routes:start]\nsome text\n";
        $this->assertSame(1, $h->callRegionMarkerCount($onlyStart, 'hand-routes'));
        $this->assertSame(2, $h->callRegionMarkerCount($withMarkers, 'hand-routes'));
        $this->assertSame(0, $h->callRegionMarkerCount('no markers here', 'hand-routes'));

        $this->assertSame(
            "    // [generator:region:x:start]\n    // [generator:region:x:end]",
            $h->callRenderRegion('x', '', '    '),
        );
    }
}
