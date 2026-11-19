<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use RuntimeException;

/**
 * P123 slots: a body region between `// @generated:<slot> begin` and `// @generated:<slot> end` lines (or the
 * `<!-- … -->` form in templates). Only what is between them changes; every other line stays as it is.
 */
final class Slots
{
    private const MARKER = '/^[ \t]*(?:\/\/ @generated:([a-z][a-z-]*) (begin|end)|<!-- @generated:([a-z][a-z-]*) (begin|end) -->)[ \t]*$/';

    /** Replace one slot's lines with $body (lines ending in "\n", or ''). */
    public static function fill(string $text, string $slot, string $body, string $file = 'file'): string
    {
        $lines = explode("\n", $text);
        $begin = $end = null;
        foreach ($lines as $i => $line) {
            if (preg_match(self::MARKER, $line, $m)) {
                $name = $m[1] !== '' ? $m[1] : $m[3];
                $edge = $m[2] !== '' ? $m[2] : $m[4];
                if ($name !== $slot) {
                    continue;
                }
                if ($edge === 'begin') {
                    if ($begin !== null) {
                        throw new RuntimeException("{$file}: slot '{$slot}' begins twice");
                    }
                    $begin = $i;
                } elseif ($begin !== null && $end === null) {
                    $end = $i;
                }
            }
        }
        if ($begin === null || $end === null) {
            throw new RuntimeException("{$file}: no slot '{$slot}' (made without --generated, or the markers were removed)");
        }
        $inner = $body === '' ? [] : explode("\n", rtrim($body, "\n"));

        return implode("\n", [...array_slice($lines, 0, $begin + 1), ...$inner, ...array_slice($lines, $end)]);
    }

    /** @return list<string> the slot names in the order they begin */
    public static function names(string $text): array
    {
        $names = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::MARKER, $line, $m) && ($m[2] === 'begin' || ($m[4] ?? '') === 'begin')) {
                $names[] = $m[1] !== '' ? $m[1] : $m[3];
            }
        }

        return $names;
    }

    /** The file without its marker lines. */
    public static function strip(string $text): string
    {
        return implode("\n", array_filter(explode("\n", $text), fn ($line) => ! preg_match(self::MARKER, $line)));
    }
}
