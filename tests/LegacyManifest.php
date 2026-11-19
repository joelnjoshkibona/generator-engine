<?php

namespace Blutrixx\GeneratorEngine\Tests;

/** The legacy (SYSTEM_SHELL) target's files and their checksums: src/Generators, src/Helpers, src/Schema. */
final class LegacyManifest
{
    public const DIRS = ['src/Generators', 'src/Helpers', 'src/Schema'];

    /** @return array<string, string> relative path => sha256 */
    public static function compute(string $root): array
    {
        $out = [];
        foreach (self::DIRS as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $out[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($out);

        return $out;
    }
}
