<?php

namespace Blutrixx\GeneratorEngine\Targets\Bootbox;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;

/**
 * P133: a module's fields in sections of related data — the spec's own first, then Details, Amounts, Links, Dates,
 * Notes (the Timeline is the page's own). One answer for the Overview's cards, the form's order and the phone's cards.
 *
 * An entry is ['col' => column] or a special: ['location' => true] (a location-scoped module's place), ['total' => true]
 * (a priced document's total), ['lines' => true] (a document's lines, in the form).
 */
final class Sections
{
    public const DEFAULTS = ['details', 'amounts', 'links', 'dates', 'notes'];

    /**
     * The Overview: the record's picture (its first image column) first, then the sections. The name (the title), a
     * workflow status and a number (the header's), JSON (its own card) are not facts.
     *
     * @return list<array{key: string, custom: bool, entries: list<array<string, mixed>>}>
     */
    public static function overview(ModuleSpec $spec): array
    {
        $number = $spec->number()['column'] ?? null;
        $status = self::statusLink($spec)['name'] ?? null;
        $skip = fn (array $c): bool => $c['name'] === $spec->labelColumn() || $c['name'] === $number || $c['name'] === $status
            || in_array(ColumnTypes::kind($c), ['workflow', 'json'], true) || $c['name'] === self::pictureColumn($spec);

        return self::build($spec, $skip, false);
    }

    /**
     * The form: required fields first in each section, the lines before the notes, media and long text last. Columns
     * the system fills (`fill`) and JSON are not in it.
     *
     * @return list<array{key: string, custom: bool, entries: list<array<string, mixed>>}>
     */
    public static function form(ModuleSpec $spec): array
    {
        $skip = fn (array $c): bool => ColumnTypes::kind($c) === 'json' || $spec->fill($c) !== null;

        return self::build($spec, $skip, true);
    }

    /**
     * P133: a link to the platform's Statuses (a module without a workflow): the record's status, shown as the header's pill,
     * not as a link among the facts. Null when the module has a workflow or no such link.
     */
    public static function statusLink(ModuleSpec $spec): ?array
    {
        if ($spec->workflowColumn() !== null) {
            return null;
        }
        foreach ($spec->foreignKeys() as $c) {
            if (($c['relatedModule'] ?? null) === 'Statuses') {
                return $c;
            }
        }

        return null;
    }

    /** The column that is the record's picture: its first image column (P133), or null. */
    public static function pictureColumn(ModuleSpec $spec): ?string
    {
        foreach ($spec->media() as $name => $accept) {
            if ($accept === 'image') {
                return $name;
            }
        }

        return null;
    }

    /** The default section a column falls into. */
    public static function of(array $c, bool $form): string
    {
        return match (ColumnTypes::kind($c)) {
            // P133 (the design review): Amounts are money; a count, a number of days or a sort order is a detail
            'decimal' => 'amounts',
            'foreign' => 'links',
            'date', 'datetime' => 'dates',
            'text' => 'notes',
            'media' => $form ? 'media' : 'details',
            default => 'details',
        };
    }

    private static function build(ModuleSpec $spec, callable $skip, bool $form): array
    {
        $out = [];
        $placed = [];
        foreach ($spec->sections() as $s) {
            $out[$s['key']] = ['key' => $s['key'], 'custom' => true, 'entries' => []];
            foreach ($s['columns'] as $name) {
                $placed[$name] = $s['key'];
            }
        }
        $keys = $form ? ['details', 'amounts', 'links', 'dates', 'lines', 'media', 'notes'] : self::DEFAULTS;
        foreach ($keys as $k) {
            $out[$k] ??= ['key' => $k, 'custom' => false, 'entries' => []];
        }
        foreach ($spec->columns() as $c) {
            if ($skip($c)) {
                continue;
            }
            $out[$placed[$c['name']] ?? self::of($c, $form)]['entries'][] = ['col' => $c];
        }
        if ($spec->scope() === 'location') {
            $out['links']['entries'][] = ['location' => true];
        }
        if ($form && $spec->lines() !== null) {
            $out['lines']['entries'][] = ['lines' => true];
        }
        if ($form) {
            // required first in each section, otherwise the spec's order
            foreach ($out as &$section) {
                $required = fn (array $e): bool => isset($e['location']) || (isset($e['col']) && empty($e['col']['nullable']) && ColumnTypes::kind($e['col']) !== 'workflow');
                $section['entries'] = [...array_values(array_filter($section['entries'], $required)), ...array_values(array_filter($section['entries'], fn ($e) => ! $required($e)))];
            }
            unset($section);
        }

        return array_values(array_filter($out, fn (array $s) => $s['entries'] !== []));
    }
}
