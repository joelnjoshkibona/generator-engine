<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

/**
 * The web side's imports slot (P123: no formatter reorders TS/Vue imports there, so the generator owns a slot of
 * import lines). Names the file already imports elsewhere are left out: a name imported twice fails typecheck.
 */
final class TsImports
{
    /**
     * @param  array<string, list<string>>  $wanted  module specifier => names (`type X` for a type, `default:X` for a default import)
     */
    public static function slot(string $file, array $wanted): string
    {
        $have = self::importedNames(self::withoutSlot($file));
        ksort($wanted); // a stable order: by module specifier
        $lines = '';
        foreach ($wanted as $from => $names) {
            $default = null;
            $named = [];
            foreach (array_unique($names) as $n) {
                $local = preg_replace('/^(type |default:)/', '', $n);
                if (in_array($local, $have, true)) {
                    continue;
                }
                str_starts_with($n, 'default:') ? $default = $local : $named[] = $n;
            }
            if ($default !== null) {
                $lines .= "import {$default} from '{$from}'\n";
            }
            if ($named !== []) {
                $lines .= 'import { '.implode(', ', $named)." } from '{$from}'\n";
            }
        }

        return $lines;
    }

    /** @return list<string> local names bound by the file's import lines */
    public static function importedNames(string $text): array
    {
        $names = [];
        preg_match_all('/^import\s+(.+?)\s+from\s+[\'"][^\'"]+[\'"]/m', $text, $m);
        foreach ($m[1] as $clause) {
            if (preg_match('/^(?:type\s+)?([A-Za-z_$][\w$]*)\s*(?:,|$)/', $clause, $d)) {
                $names[] = $d[1];
            }
            if (preg_match('/\{([^}]*)\}/', $clause, $b)) {
                foreach (explode(',', $b[1]) as $part) {
                    $part = trim(preg_replace('/^type\s+/', '', trim($part)));
                    if ($part !== '') {
                        $names[] = trim(preg_split('/\s+as\s+/', $part)[1] ?? $part);
                    }
                }
            }
        }

        return $names;
    }

    private static function withoutSlot(string $text): string
    {
        return (string) preg_replace('/^[ \t]*\/\/ @generated:imports begin\n.*?^[ \t]*\/\/ @generated:imports end\n/ms', '', $text);
    }
}
