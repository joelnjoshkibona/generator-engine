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
}
