<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

/**
 * Writes the locale keys the generator derives (P123: only keys under the module's own namespace), leaving every other
 * key, hand-added ones included, as it is.
 */
final class LocaleMerger
{
    /**
     * @param  array<string, string>  $keys  dotted key => text, always written
     * @param  array<string, string>  $ifMissing  dotted key => text, written only where the key is not there yet
     */
    public static function merge(array $locale, array $keys, array $ifMissing = []): array
    {
        foreach ($ifMissing as $dotted => $text) {
            if (self::get($locale, $dotted) === null) {
                $keys[$dotted] ??= $text;
            }
        }
        foreach ($keys as $dotted => $text) {
            $ref = &$locale;
            $parts = explode('.', $dotted);
            foreach (array_slice($parts, 0, -1) as $p) {
                if (! isset($ref[$p]) || ! is_array($ref[$p])) {
                    $ref[$p] = [];
                }
                $ref = &$ref[$p];
            }
            $ref[end($parts)] = $text;
            unset($ref);
        }

        return $locale;
    }

    private static function get(array $locale, string $dotted): mixed
    {
        foreach (explode('.', $dotted) as $p) {
            if (! is_array($locale) || ! array_key_exists($p, $locale)) {
                return null;
            }
            $locale = $locale[$p];
        }

        return $locale;
    }

    public static function encode(array $locale): string
    {
        return ManifestMerger::encode($locale);
    }
}
