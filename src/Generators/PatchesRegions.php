<?php

namespace Blutrixx\GeneratorEngine\Generators;

trait PatchesRegions
{
    /**
     * Replace the content inside a named generator region in a file.
     *
     * Supports two marker styles:
     *   HTML  — <!-- [generator:region:{name}:start] --> ... <!-- [generator:region:{name}:end] -->
     *   JS/TS — // [generator:region:{name}:start]       ... // [generator:region:{name}:end]
     *
     * Idempotent: calling twice with the same content replaces rather than duplicates.
     * Returns false if file doesn't exist or markers not found.
     */
    protected function patchRegion(string $filePath, string $regionName, string $newContent): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $fileContent = file_get_contents($filePath);

        // Try HTML comment style first
        $htmlStart = "<!-- [generator:region:{$regionName}:start] -->";
        $htmlEnd   = "<!-- [generator:region:{$regionName}:end] -->";

        if (str_contains($fileContent, $htmlStart) && str_contains($fileContent, $htmlEnd)) {
            $pattern = '/' . preg_quote($htmlStart, '/') . '[\s\S]*?' . preg_quote($htmlEnd, '/') . '/';
            $replacement = $htmlStart . (strlen(trim($newContent)) > 0 ? "\n" . $newContent . "\n" : '') . $htmlEnd;
            $newFileContent = preg_replace($pattern, $replacement, $fileContent);
            if ($newFileContent !== $fileContent) {
                file_put_contents($filePath, $newFileContent);
                return true;
            }
            return false;
        }

        // Try JS/TS comment style
        $jsStart = "// [generator:region:{$regionName}:start]";
        $jsEnd   = "// [generator:region:{$regionName}:end]";

        if (str_contains($fileContent, $jsStart) && str_contains($fileContent, $jsEnd)) {
            $pattern = '/' . preg_quote($jsStart, '/') . '[\s\S]*?' . preg_quote($jsEnd, '/') . '/';
            $replacement = $jsStart . (strlen(trim($newContent)) > 0 ? "\n" . $newContent . "\n" : '') . $jsEnd;
            $newFileContent = preg_replace($pattern, $replacement, $fileContent);
            if ($newFileContent !== $fileContent) {
                file_put_contents($filePath, $newFileContent);
                return true;
            }
            return false;
        }

        return false;
    }

    /**
     * Read the current content inside a named generator region.
     *
     * Returns null when the file doesn't exist or the region's markers
     * aren't present yet — distinct from '' (markers present, region
     * genuinely empty). Callers that need to *add to* a region (as opposed
     * to patchRegion()'s replace-the-whole-region semantics) use this to
     * read what's there, append, and pass the result back to patchRegion().
     * Also doubles as the "do the markers exist yet" check a self-heal path
     * needs before inserting them into a file generated before this region
     * existed.
     */
    protected function getRegionContent(string $filePath, string $regionName): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $fileContent = file_get_contents($filePath);

        $htmlStart = "<!-- [generator:region:{$regionName}:start] -->";
        $htmlEnd   = "<!-- [generator:region:{$regionName}:end] -->";
        if (str_contains($fileContent, $htmlStart) && str_contains($fileContent, $htmlEnd)) {
            $pattern = '/' . preg_quote($htmlStart, '/') . '([\s\S]*?)' . preg_quote($htmlEnd, '/') . '/';
            return preg_match($pattern, $fileContent, $m) ? trim($m[1]) : '';
        }

        $jsStart = "// [generator:region:{$regionName}:start]";
        $jsEnd   = "// [generator:region:{$regionName}:end]";
        if (str_contains($fileContent, $jsStart) && str_contains($fileContent, $jsEnd)) {
            $pattern = '/' . preg_quote($jsStart, '/') . '([\s\S]*?)' . preg_quote($jsEnd, '/') . '/';
            return preg_match($pattern, $fileContent, $m) ? trim($m[1]) : '';
        }

        return null;
    }

    /**
     * Same lookup as getRegionContent(), but returns the RAW, untrimmed inner
     * text and distinguishes "markers absent" (null) from "markers present,
     * region empty" ('') the same way. Added for the hand-* region migration
     * (engine v3.5.17): the migration needs to tell a genuinely-empty hand
     * region apart from a freshly-inserted one, and needs the untrimmed text
     * so it can normalize() it itself rather than losing leading/trailing
     * blank lines before that decision is made.
     */
    protected function extractRegion(string $fileContent, string $regionName): ?string
    {
        $htmlStart = "<!-- [generator:region:{$regionName}:start] -->";
        $htmlEnd   = "<!-- [generator:region:{$regionName}:end] -->";
        if (str_contains($fileContent, $htmlStart) && str_contains($fileContent, $htmlEnd)) {
            $pattern = '/' . preg_quote($htmlStart, '/') . '([\s\S]*?)' . preg_quote($htmlEnd, '/') . '/';
            return preg_match($pattern, $fileContent, $m) ? $m[1] : '';
        }

        $jsStart = "// [generator:region:{$regionName}:start]";
        $jsEnd   = "// [generator:region:{$regionName}:end]";
        if (str_contains($fileContent, $jsStart) && str_contains($fileContent, $jsEnd)) {
            $pattern = '/' . preg_quote($jsStart, '/') . '([\s\S]*?)' . preg_quote($jsEnd, '/') . '/';
            return preg_match($pattern, $fileContent, $m) ? $m[1] : '';
        }

        return null;
    }

    /**
     * How many of a region's two markers (start/end) are present in
     * $fileContent — 0, 1 or 2. A count of 1 means a hand-edit left the file
     * with a dangling marker (e.g. the closing marker was deleted by hand);
     * generators that own hand-* regions abort rather than write over a file
     * in that state (engine v3.5.17, Design rule 6). Detects whichever
     * marker style (HTML or JS/TS) the file actually carries a marker for,
     * matching extractRegion()'s own style detection.
     */
    protected function regionMarkerCount(string $fileContent, string $regionName): int
    {
        $htmlCount = (int) str_contains($fileContent, "<!-- [generator:region:{$regionName}:start] -->")
            + (int) str_contains($fileContent, "<!-- [generator:region:{$regionName}:end] -->");
        if ($htmlCount > 0) {
            return $htmlCount;
        }

        return (int) str_contains($fileContent, "// [generator:region:{$regionName}:start]")
            + (int) str_contains($fileContent, "// [generator:region:{$regionName}:end]");
    }

    /**
     * Split a block of PHP statements (route registrations, use/import
     * lines) into one chunk per top-level statement, each still carrying
     * any comment immediately preceding it (comments have no statement
     * terminator of their own, so they accumulate into the following
     * chunk's buffer). Depth-tracks brackets so a multi-line statement like
     * `Route::middleware([...])\n    ->get(...);` is never split mid
     * statement — this is exactly the NJIWA Messages/MobileReleases shape
     * (engine v3.5.17).
     *
     * @return list<string>
     */
    protected function splitPhpStatements(string $code): array
    {
        $tokens = \PhpToken::tokenize("<?php\n" . $code);
        array_shift($tokens); // drop the leading T_OPEN_TAG this method added

        $chunks = [];
        $buffer = '';
        $depth = 0;

        foreach ($tokens as $token) {
            $text = $token->text;

            if ($text === '{' || $text === '(' || $text === '[' || $token->id === T_DOLLAR_OPEN_CURLY_BRACES || $token->id === T_ATTRIBUTE) {
                $depth++;
            } elseif ($text === '}' || $text === ')' || $text === ']') {
                $depth--;
            }

            $buffer .= $text;

            if ($text === ';' && $depth === 0) {
                $chunks[] = $buffer;
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
    }

    /**
     * Split a class body (controller methods, or a use-statement block) into
     * one chunk per member, each carrying its preceding comment/docblock. A
     * member closes on a top-level `;` (a property or trait-use statement)
     * or on the `}` that returns bracket depth to 0 once the chunk has seen
     * a `function` keyword (a method body) — so a `}` inside the method's
     * own nested braces never closes the chunk early. `name` is the method
     * name for a function chunk (the first non-whitespace, non-`&` token
     * after `function`), null otherwise — used to detect a hand-written
     * method that shadows a generated one by name (engine v3.5.17).
     *
     * @return list<array{name: ?string, text: string}>
     */
    protected function splitClassMembers(string $code): array
    {
        $tokens = \PhpToken::tokenize("<?php\n" . $code);
        array_shift($tokens);

        $chunks = [];
        $buffer = '';
        $depth = 0;
        $sawFunction = false;
        $captureName = false;
        $name = null;

        foreach ($tokens as $token) {
            $text = $token->text;

            if ($token->id === T_FUNCTION && !$sawFunction) {
                // Only the chunk's OWN function keyword names it -- a closure
                // inside the method body (e.g. `$cb = function () {...};`) is
                // also T_FUNCTION and must not overwrite $name with its own
                // next token (an anonymous function's next token is often
                // literally `(`).
                $sawFunction = true;
                $captureName = true;
            } elseif ($captureName && $token->id !== T_WHITESPACE && $text !== '&') {
                $name = $text;
                $captureName = false;
            }

            if ($text === '{' || $text === '(' || $text === '[' || $token->id === T_DOLLAR_OPEN_CURLY_BRACES || $token->id === T_ATTRIBUTE) {
                $depth++;
            } elseif ($text === '}' || $text === ')' || $text === ']') {
                $depth--;
            }

            $buffer .= $text;

            if ($depth === 0 && ($text === ';' || ($text === '}' && $sawFunction))) {
                $chunks[] = ['name' => $name, 'text' => $buffer];
                $buffer = '';
                $sawFunction = false;
                $captureName = false;
                $name = null;
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = ['name' => $name, 'text' => $buffer];
        }

        return array_values(array_filter($chunks, static fn (array $chunk): bool => trim($chunk['text']) !== ''));
    }

    /**
     * A chunk's token texts, with whitespace and comments stripped and the
     * remaining tokens joined by a single space. Two chunks that differ only
     * in blank lines, indentation or a docblock have the same signature —
     * this is what lets the hand-region migration (engine v3.5.17) tell "the
     * generator would still produce this exact code" apart from "this is
     * genuinely different, keep it as hand-written". Comments are excluded
     * from the signature but not lost — hasComment() below is how the
     * migration still tells a bare re-indented copy apart from a
     * deliberately-annotated one.
     */
    protected function codeSignature(string $chunk): string
    {
        $parts = [];
        foreach (\PhpToken::tokenize("<?php\n" . $chunk) as $token) {
            if (in_array($token->id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $parts[] = $token->text;
        }

        return implode(' ', $parts);
    }

    /**
     * Whether a chunk carries a `//`, `#` or `/* * /`/docblock comment
     * anywhere in it. A commented chunk is never treated as "just a stale
     * copy of what the generator would produce" during the hand-region
     * migration (engine v3.5.17) — a developer who bothered to annotate
     * hand-written code gets to keep it verbatim rather than have it
     * silently folded back into generated output on the next --force.
     */
    protected function hasComment(string $chunk): bool
    {
        foreach (\PhpToken::tokenize("<?php\n" . $chunk) as $token) {
            if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a named region's start/end markers around its inner content,
     * indented by $indent. Mirrors the byte shape every generator already
     * emits for custom-* regions, so a hand-* region (engine v3.5.17) reads
     * identically in a generated file. An empty $inner renders just the two
     * marker lines with nothing between them.
     */
    protected function renderRegion(string $name, string $inner, string $indent = ''): string
    {
        return $indent . '// [generator:region:' . $name . ":start]\n"
            . ($inner !== '' ? $inner . "\n" : '')
            . $indent . '// [generator:region:' . $name . ':end]';
    }
}
