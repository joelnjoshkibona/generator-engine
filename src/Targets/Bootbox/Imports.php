<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

/**
 * Imports have no slot (P123): Pint orders imports across any marker. The generator adds the ones its body needs after
 * the file's last import and runs Pint, which orders them and removes the ones nothing uses.
 */
final class Imports
{
    /** @param list<string> $classes fully qualified */
    public static function add(string $text, array $classes): string
    {
        $missing = array_values(array_filter(array_unique($classes), fn ($c) => ! preg_match('/^use '.preg_quote($c, '/').';$/m', $text)));
        if ($missing === []) {
            return $text;
        }
        $lines = implode('', array_map(fn ($c) => "use {$c};\n", $missing));
        if (preg_match_all('/^use [A-Z][^;(]*;\n/m', $text, $m, PREG_OFFSET_CAPTURE) && $m[0] !== []) {
            $last = end($m[0]);
            $at = $last[1] + strlen($last[0]);
        } else {
            preg_match('/^namespace [^;]+;\n/m', $text, $n, PREG_OFFSET_CAPTURE);
            $at = isset($n[0]) ? $n[0][1] + strlen($n[0][0]) : 0;
            $lines = "\n".$lines;
        }

        return substr($text, 0, $at).$lines.substr($text, $at);
    }
}
