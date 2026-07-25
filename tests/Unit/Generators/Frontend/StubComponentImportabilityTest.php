<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the "stub references a component that doesn't
 * exist" class of bug.
 *
 * Bug: `mobile_app/fields/file-input.stub` rendered `<FileInputFieldWithCropper
 * ... />`, but that component was never imported by the stub (or by
 * BaseComponentGenerator::generateFieldImports(), which imports
 * `FileInputField` from `@/components/form-fields/FileInputField.vue` for
 * BOTH the frontend and mobile targets) and does not exist anywhere in
 * SYSTEM_SHELL — it only ever existed in an unrelated legacy project. Any
 * generated module with a file field silently shipped a broken mobile form.
 *
 * A full "does this component actually exist on disk" check isn't
 * practical from inside this package (the consuming project — SYSTEM_SHELL —
 * isn't a guaranteed sibling checkout in every environment this test suite
 * runs in), so this test instead protects the checkable half of the
 * contract: every PascalCase component tag used in a stub's <template>
 * block must be resolvable from *that same stub file* — either imported in
 * its own <script> block, or a `[[Placeholder]]`-prefixed dynamic component
 * name resolved by the generator at render time. A tag that is neither is
 * either a typo or references a component nothing ever imports, exactly
 * like `FileInputFieldWithCropper` did.
 */
class StubComponentImportabilityTest extends TestCase
{
    private function templatesDir(): string
    {
        return dirname(__DIR__, 4) . '/src/Generators/Templates';
    }

    /** @return string[] absolute paths to every *.stub file under Templates */
    private function allStubFiles(): array
    {
        $dir = $this->templatesDir();
        $this->assertDirectoryExists($dir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'stub') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Vue built-in tags that are always available without an import.
     */
    private const VUE_BUILTINS = [
        'Transition', 'TransitionGroup', 'Teleport', 'KeepAlive', 'Suspense',
        'Component', 'Template', 'Slot',
    ];

    /**
     * Extract every PascalCase opening-tag name from the <template> section
     * of the given stub content. Tags containing a `[[Placeholder]]` token
     * (e.g. `<[[ModuleName]]EditForm`) are dynamic/generator-resolved and
     * are excluded — this test only checks tags that are literal, fixed
     * component names in the stub source.
     *
     * @return string[]
     */
    private function extractTemplateComponentTags(string $stubContent): array
    {
        if (!preg_match('/<template>(.*)<\/template>/s', $stubContent, $templateMatch)) {
            return [];
        }

        preg_match_all('/<([A-Z][A-Za-z0-9]*)\b/', $templateMatch[1], $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Names importable within a single stub file: anything named by an
     * `import X from '...'` / `import { X, Y } from '...'` statement
     * anywhere in the stub (covers both <script> and <script setup>
     * blocks, and PHP-placeholder-driven conditional imports).
     *
     * @return string[]
     */
    private function extractImportedNames(string $stubContent): array
    {
        $names = [];

        // import Foo from '...'
        preg_match_all('/import\s+([A-Z]\w*)\s+from\s+[\'"]/', $stubContent, $defaultMatches);
        $names = array_merge($names, $defaultMatches[1]);

        // import { Foo, Bar as Baz } from '...'
        preg_match_all('/import\s+(?:type\s+)?\{([^}]*)\}\s+from\s+[\'"]/', $stubContent, $namedMatches);
        foreach ($namedMatches[1] as $block) {
            foreach (explode(',', $block) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                // Handle "Foo as Bar" — the locally-usable name is the alias.
                if (preg_match('/\bas\s+(\w+)$/', $piece, $aliasMatch)) {
                    $names[] = $aliasMatch[1];
                } else {
                    $names[] = preg_replace('/^type\s+/', '', $piece);
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    public function test_no_stub_references_file_input_field_with_cropper(): void
    {
        foreach ($this->allStubFiles() as $path) {
            $content = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                'FileInputFieldWithCropper',
                $content,
                basename(dirname($path)) . '/' . basename($path) . ' references FileInputFieldWithCropper, '
                . 'a component that does not exist anywhere in SYSTEM_SHELL — it only ever existed in an '
                . 'unrelated legacy project. Use FileInputField (@/components/form-fields/FileInputField.vue) '
                . 'instead, matching BaseComponentGenerator::generateFieldImports().'
            );
        }
    }

    public function test_every_literal_component_tag_used_in_a_stub_is_imported_by_that_same_stub(): void
    {
        $failures = [];

        foreach ($this->allStubFiles() as $path) {
            $content = (string) file_get_contents($path);

            $usedTags = $this->extractTemplateComponentTags($content);
            if (empty($usedTags)) {
                continue;
            }

            $importedNames = $this->extractImportedNames($content);

            foreach ($usedTags as $tag) {
                if (in_array($tag, self::VUE_BUILTINS, true)) {
                    continue;
                }
                if (in_array($tag, $importedNames, true)) {
                    continue;
                }

                $failures[] = basename(dirname($path)) . '/' . basename($path) . " uses <{$tag}> but does not "
                    . 'import it in its own <script> block.';
            }
        }

        $this->assertEmpty(
            $failures,
            "Found stub component tags with no matching import in the same file:\n" . implode("\n", $failures)
        );
    }
}
