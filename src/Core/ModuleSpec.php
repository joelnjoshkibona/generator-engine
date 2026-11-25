<?php

namespace Blutrixx\GeneratorEngine\Core;

use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * A module's generator.json, read: today's module config (module_name, module_group_name, table_name, columns with
 * type/length/nullable/unique/relatedModule, features.backend.* overrides) plus the keys a starter with its own
 * skeleton needs (scope, label, label_column, sync, keep_days, workflow, icon, search). Every reader is pure.
 */
final class ModuleSpec
{
    public const SYSTEM_COLUMNS = ['id', 'uuid', 'organization_id', 'location_id', 'version', 'created_by_id', 'updated_by_id', 'created_at', 'updated_at', 'deleted_at'];

    private function __construct(private readonly array $spec) {}

    public static function fromArray(array $spec): self
    {
        foreach (['module_name', 'columns'] as $key) {
            if (! isset($spec[$key])) {
                throw new InvalidArgumentException("generator.json: '{$key}' is required");
            }
        }
        $scope = $spec['scope'] ?? 'organisation';
        if (! in_array($scope, ['organisation', 'location', 'global'], true)) {
            throw new InvalidArgumentException("generator.json: scope '{$scope}' is not organisation, location or global");
        }
        foreach (['morphs' => 'polymorphic relations', 'file_columns' => 'file columns', 'delegations' => "related-record tabs: the child declares its tab with 'tab_on' (P86)"] as $key => $what) {
            if (! empty($spec[$key])) {
                throw new InvalidArgumentException("generator.json: '{$key}' ({$what}) is not in the Bootbox target yet");
            }
        }
        $labelColumn = $spec['label_column'] ?? 'name';
        $label = array_values(array_filter($spec['columns'], fn ($c) => ($c['name'] ?? null) === $labelColumn))[0] ?? null;
        if ($label === null || ColumnTypes::kind($label) !== 'string' || ! empty($label['nullable'])) {
            throw new InvalidArgumentException("generator.json: the label column '{$labelColumn}' must be a required string column (set label_column to the one the records are named by)");
        }
        if (! empty($spec['sync']) && empty($spec['phone'])) {
            throw new InvalidArgumentException("generator.json: 'sync' is a phone mode (P102): set 'phone' too");
        }
        if (isset($spec['keep_days']) && empty($spec['sync'])) {
            throw new InvalidArgumentException("generator.json: 'keep_days' is how long a phone keeps a sync module's records: set 'sync' too");
        }
        $taken = [
            'version' => "every record's own version (the skeleton's, for saving over a newer copy); name it something else, such as spec_version",
            'organization_id' => "the skeleton's: set scope to organisation",
            'location_id' => "the skeleton's: set scope to location",
            'created_by_id' => "the skeleton's: every record keeps who made it",
            'updated_by_id' => "the skeleton's: every record keeps who changed it last",
        ];
        foreach ($spec['columns'] as $c) {
            if (isset($taken[$c['name'] ?? ''])) {
                throw new InvalidArgumentException("generator.json: column '{$c['name']}' is {$taken[$c['name']]}");
            }
        }
        if (isset($spec['calendar'])) {
            // P128: the dates that put a record on the calendar, columns of the module's own (or the skeleton's created_at / updated_at)
            $cal = $spec['calendar'];
            if (! is_array($cal) || ! is_string($cal['start'] ?? null) || (isset($cal['end']) && ! is_string($cal['end'])) || array_diff(array_keys($cal), ['start', 'end']) !== []) {
                throw new InvalidArgumentException("generator.json: calendar is { \"start\": <date column>, \"end\": <date column> } (end optional, P128)");
            }
            $dated = ['created_at', 'updated_at'];
            foreach ($spec['columns'] as $c) {
                if (in_array(ColumnTypes::kind($c), ['date', 'datetime'], true)) {
                    $dated[] = $c['name'];
                }
            }
            foreach (array_filter([$cal['start'], $cal['end'] ?? null]) as $which => $column) {
                if (! in_array($column, $dated, true)) {
                    throw new InvalidArgumentException("generator.json: calendar.".($which === 0 ? 'start' : 'end')." ({$column}) must be a date or datetime column (P128)");
                }
            }
        }
        // P123 (2026-11-21): no two records share these columns' values (each set two or more of its own columns)
        foreach ((array) ($spec['unique'] ?? []) as $i => $set) {
            $names = array_column($spec['columns'], 'name');
            if (! is_array($set) || count($set) < 2 || array_diff($set, $names) !== [] || count(array_unique($set)) !== count($set)) {
                throw new InvalidArgumentException("generator.json: unique[{$i}] is a list of two or more of the module's columns (one column: \"unique\": true on it)");
            }
        }
        if (! empty($spec['children'])) {
            throw new InvalidArgumentException("generator.json: 'children' is declared by each child instead, with 'tab_on' (P86)");
        }
        foreach ($spec['tab_on'] ?? [] as $parent => $tab) {
            if (empty($tab['key']) || empty($tab['via']) || empty($tab['tab'])) {
                throw new InvalidArgumentException("generator.json: tab_on.{$parent} needs key, via and tab");
            }
            $via = array_values(array_filter($spec['columns'], fn ($c) => ($c['name'] ?? null) === $tab['via']))[0] ?? null;
            if ($via === null || ($via['relatedModule'] ?? null) !== $parent) {
                throw new InvalidArgumentException("generator.json: tab_on.{$parent}.via ({$tab['via']}) must be a column pointing at {$parent}");
            }
        }
        if (! empty($spec['inline_items'])) {
            throw new InvalidArgumentException("generator.json: 'inline_items' are a document's lines in this target: declare them with 'lines' (P115)");
        }
        if (isset($spec['lines'])) {
            self::checkLines((array) $spec['lines'], $spec);
        }
        if (isset($spec['number'])) {
            if (empty($spec['number']['column']) || empty($spec['number']['prefix'])) {
                throw new InvalidArgumentException('generator.json: number needs column and prefix (QT makes QT-2026-0001)');
            }
            if (in_array($spec['number']['column'], array_column($spec['columns'], 'name'), true)) {
                throw new InvalidArgumentException("generator.json: number.column ({$spec['number']['column']}) is made by the server: leave it out of columns");
            }
        }
        foreach ($spec['actions'] ?? [] as $key => $action) {
            self::checkAction((string) $key, (array) $action, $spec);
        }
        foreach ($spec['columns'] as $i => $column) {
            if (empty($column['name'])) {
                throw new InvalidArgumentException("generator.json: columns[{$i}] has no name");
            }
            if (ColumnTypes::kind($column) === 'foreign' && empty($column['relatedModule'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} is a foreign key without relatedModule");
            }
            if (in_array($column['type'] ?? '', ['morphs', 'nullableMorphs'], true) || ! empty($column['file'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} ({$column['type']}) is not in the Bootbox target yet: it waits for a kit decision (D14)");
            }
            if (ColumnTypes::kind($column) === 'media') {
                // P131: always nullable (a phone sends the record before its file); the API field is the name, the column <name>_media_id
                if (empty($column['nullable'])) {
                    throw new InvalidArgumentException("generator.json: {$column['name']} (media) must be nullable: a phone sends a record before its file (P131)");
                }
                if (str_ends_with($column['name'], '_id')) {
                    throw new InvalidArgumentException("generator.json: {$column['name']} (media) is named for what it holds (photo, receipt): it is stored as {$column['name']}_media_id");
                }
                if (! in_array($column['accept'] ?? 'image', ['image', 'any'], true)) {
                    throw new InvalidArgumentException("generator.json: {$column['name']}.accept is image or any (P131)");
                }
            }
            if (ColumnTypes::kind($column) === 'enum' && ($d = ColumnTypes::defaultValue($column)) !== null && ! in_array($d, array_map('strval', (array) ($column['values'] ?? [])), true)) {
                throw new InvalidArgumentException("generator.json: {$column['name']}'s default '{$d}' is not one of its values");
            }
            if (ColumnTypes::kind($column) === 'workflow' && empty($spec['workflow'])) {
                throw new InvalidArgumentException("generator.json: {$column['name']} is a workflow column but there is no workflow");
            }
        }

        self::checkPresentation($spec);

        return new self($spec);
    }

    /** P84: what an action entry may say, and what it may not (yet). */
    private static function checkAction(string $key, array $a, array $spec): void
    {
        $where = "generator.json: actions.{$key}";
        if (! preg_match('/^[a-z][a-zA-Z0-9]*$/', $key)) {
            throw new InvalidArgumentException("{$where}: the key is camelCase (close, sendReminder): it names the route, the permission and the service");
        }
        foreach (['wizard' => 'wizards are not in the Bootbox target yet', 'confirm_step' => 'wizards are not in the Bootbox target yet', 'splash' => 'splash data is not in the Bootbox target yet', 'operations' => 'an action has one route, POST /{module}/{uuid}/{action}', 'serviceMethod' => 'the service is {Module}{Action}Service::handle()', 'serviceArgs' => 'the service is {Module}{Action}Service::handle()'] as $legacy => $why) {
            if (isset($a[$legacy])) {
                throw new InvalidArgumentException("{$where}.{$legacy}: {$why}");
            }
        }
        if (($a['uiType'] ?? 'modal') !== 'modal') {
            throw new InvalidArgumentException("{$where}: page actions are not in the Bootbox target yet (a dialog is)");
        }
        if (isset($a['urlParams']) && $a['urlParams'] !== ['uuid']) {
            throw new InvalidArgumentException("{$where}: an action runs on one record (urlParams [\"uuid\"]); other parameters are fields");
        }
        if (empty($a['label'])) {
            throw new InvalidArgumentException("{$where} needs a label");
        }
        if (! in_array($a['button'] ?? 'more', ['header', 'more'], true)) {
            throw new InvalidArgumentException("{$where}.button is header or more");
        }
        $names = array_column($spec['columns'], null, 'name');
        foreach ((array) ($a['fields'] ?? []) as $field) {
            if (! is_string($field) || ! isset($names[$field])) {
                throw new InvalidArgumentException("{$where}.fields: list the module's own column names (".json_encode($field).' is not one)');
            }
        }
        if (! empty($a['fields']) && ! empty($a['bulk'])) {
            throw new InvalidArgumentException("{$where}: a bulk action takes no fields");
        }
        if (isset($a['status'])) {
            $codes = array_column($spec['workflow']['statuses'] ?? [], 'code');
            if (! in_array($a['status'], $codes, true)) {
                throw new InvalidArgumentException("{$where}.status '{$a['status']}' is not a status of the module's workflow");
            }
        }
        // P134: a reverse undoes the last run of another action of the module, one that moves the record's status
        if (isset($a['reverses'])) {
            $target = $spec['actions'][$a['reverses']] ?? null;
            if (! is_string($a['reverses']) || $target === null || $a['reverses'] === $key) {
                throw new InvalidArgumentException("{$where}.reverses names another action of the module (".json_encode($a['reverses']).' is not one)');
            }
            if (! isset($target['status'])) {
                throw new InvalidArgumentException("{$where}.reverses: '{$a['reverses']}' moves no status, so there is no status to undo it from");
            }
            if (isset($target['reverses'])) {
                throw new InvalidArgumentException("{$where}.reverses: '{$a['reverses']}' is itself a reverse; run the action again instead");
            }
            foreach (['status' => 'it puts back the status the record had', 'fields' => 'it asks for nothing', 'bulk' => 'it runs on one record, checked against what changed since'] as $no => $why) {
                if (! empty($a[$no])) {
                    throw new InvalidArgumentException("{$where}: a reverse takes no {$no}: {$why}");
                }
            }
        }
        // P84 (amended 2026-11-22): the statuses it applies from
        if (isset($a['from'])) {
            $codes = array_column($spec['workflow']['statuses'] ?? [], 'code');
            if ($codes === []) {
                throw new InvalidArgumentException("{$where}.from names statuses, but the module has no workflow");
            }
            if (! is_array($a['from']) || $a['from'] === [] || ! array_is_list($a['from'])) {
                throw new InvalidArgumentException("{$where}.from lists the workflow statuses it applies from, like [\"draft\"]");
            }
            foreach ($a['from'] as $code) {
                if (! in_array($code, $codes, true)) {
                    throw new InvalidArgumentException("{$where}.from: '".(is_string($code) ? $code : json_encode($code))."' is not a status of the module's workflow");
                }
            }
        }
    }

    /** P115: what a document's lines may say. */
    private static function checkLines(array $l, array $spec): void
    {
        if (! in_array($l['kind'] ?? 'sale', ['sale', 'purchase', 'stock'], true)) {
            throw new InvalidArgumentException('generator.json: lines.kind is sale, purchase or stock');
        }
        if (empty($l['item_module'])) {
            throw new InvalidArgumentException('generator.json: lines.item_module names the module a line picks its item from');
        }
        if (isset($l['editable_status']) && ! in_array($l['editable_status'], array_column($spec['workflow']['statuses'] ?? [], 'code'), true)) {
            throw new InvalidArgumentException("generator.json: lines.editable_status '{$l['editable_status']}' is not a status of the module's workflow");
        }
        if (($l['kind'] ?? 'sale') !== 'stock') {
            $taken = array_intersect(['subtotal', 'tax', 'total'], array_column($spec['columns'], 'name'));
            if ($taken !== []) {
                throw new InvalidArgumentException('generator.json: a priced document keeps its own '.implode(', ', $taken).' (the server works them out from the lines): rename or drop that column');
            }
        }
        foreach (['columns', 'lots', 'price_override'] as $later) {
            if (! empty($l[$later])) {
                throw new InvalidArgumentException("generator.json: lines.{$later} is not in the Bootbox target yet");
            }
        }
    }

    public static function fromFile(string $path): self
    {
        $spec = json_decode((string) file_get_contents($path), true);
        if (! is_array($spec)) {
            throw new InvalidArgumentException("{$path} is not a JSON object");
        }

        return self::fromArray($spec);
    }

    public function raw(): array
    {
        return $this->spec;
    }

    public function key(): string
    {
        return Str::studly($this->spec['module_name']);
    }

    public function group(): string
    {
        return Str::studly($this->spec['module_group_name'] ?? $this->key());
    }

    public function table(): string
    {
        return $this->spec['table_name'] ?? Str::snake($this->key());
    }

    public function route(): string
    {
        return Str::kebab($this->key());
    }

    public function scope(): string
    {
        return $this->spec['scope'] ?? 'organisation';
    }

    public function isOrganisationScoped(): bool
    {
        return $this->scope() !== 'global';
    }

    /** What one record is called ("Task", "Unit of measure"): the spec's label, or the key's in sentence case. */
    public function recordLabel(): string
    {
        return $this->spec['label'] ?? self::headNoun(ucfirst(strtolower(Str::headline($this->key()))), fn (string $w) => Str::singular($w));
    }

    /** What the records are called ("Tasks", "Units of measure"). */
    public function recordLabels(): string
    {
        return self::headNoun($this->recordLabel(), fn (string $w) => Str::plural($w));
    }

    /**
     * A label with its head noun changed: the word before "of" when there is one ("Units of measure"), else the last
     * ("Tax rates"). Bootbox's bootbox:module words its menu the same way.
     */
    public static function headNoun(string $label, callable $change): string
    {
        $words = explode(' ', $label);
        $of = array_search('of', array_map('strtolower', $words), true);
        $i = is_int($of) && $of > 0 ? $of - 1 : count($words) - 1;
        $words[$i] = $change($words[$i]);

        return implode(' ', $words);
    }

    /** The column a record is named by. */
    public function labelColumn(): string
    {
        return $this->spec['label_column'] ?? 'name';
    }

    public function description(): ?string
    {
        return $this->spec['description'] ?? null;
    }

    /** P133: its icon, a Lucide name; without one in the spec, a guess from its name (never the placeholder Circle). */
    public function icon(): string
    {
        return $this->spec['icon'] ?? Icons::guess($this->key());
    }

    /** D7: the module also has phone screens (an opt-in: a phone app carries a product's everyday modules). */
    public function phone(): bool
    {
        return (bool) ($this->spec['phone'] ?? false);
    }

    /** P133: its place in the phone's bottom bar (`"phone": { "tab": true, "order": 1 }`), or null for none. */
    public function phoneTab(): ?int
    {
        $phone = $this->spec['phone'] ?? false;

        return is_array($phone) && ! empty($phone['tab']) ? (int) ($phone['order'] ?? 50) : null;
    }

    /** P133: the spec's own sections, first on the Overview, the form and the phone. @return list<array{key: string, label: string, label_sw: ?string, columns: list<string>}> */
    public function sections(): array
    {
        return array_map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'label_sw' => $s['label_sw'] ?? null, 'columns' => array_values($s['columns'])], $this->spec['sections'] ?? []);
    }

    /** P133: who fills a column when it is not the person in the form (user, workflow, server), or null. */
    public function fill(array $column): ?string
    {
        return $column['fill'] ?? null;
    }

    /**
     * P133: where the module sits in the sidebar. A module that lives only as another's tab (tab_on) is off the menu
     * unless it says hidden: false. @return array{section: ?string, position: ?int, hidden: bool}
     */
    public function menu(): array
    {
        $m = $this->spec['menu'] ?? [];

        return ['section' => $m['section'] ?? null, 'position' => isset($m['position']) ? (int) $m['position'] : null, 'hidden' => (bool) ($m['hidden'] ?? $this->tabOn() !== [])];
    }

    /** P133: the sidebar section this module declares for itself and its neighbours, or null. @return array{key: string, title: string, title_sw: ?string, icon: ?string, position: ?int}|null */
    public function menuSection(): ?array
    {
        $s = $this->spec['menu_section'] ?? null;

        return $s === null ? null : ['key' => $s['key'], 'title' => $s['title'], 'title_sw' => $s['title_sw'] ?? null, 'icon' => $s['icon'] ?? null, 'position' => isset($s['position']) ? (int) $s['position'] : null];
    }

    /**
     * P133: what a spec should say and does not (shown, never refused): a link to Users named like a person who acted
     * without `fill`, a string named like a kind without `values`, no icon of its own.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $out = [];
        if (! isset($this->spec['icon'])) {
            $out[] = "no icon: guessed {$this->icon()} from its name (set \"icon\" to a Lucide name)";
        }
        foreach ($this->columns() as $c) {
            $kind = ColumnTypes::kind($c);
            if ($kind === 'foreign' && ($c['relatedModule'] ?? null) === 'Users' && preg_match('/_by(_id)?$/', $c['name']) && ! isset($c['fill'])) {
                $out[] = "{$c['name']}: a person who acted is usually filled by the system, not typed (set \"fill\": user, workflow or server)";
            }
            if ($kind === 'string' && ! isset($c['values']) && preg_match('/(^|_)(type|kind|direction|method|category|mode|channel)$/', $c['name'])) {
                $out[] = "{$c['name']}: named like a fixed list; give it \"values\" (a choice, never free text)";
            }
        }
        if (! isset($this->spec['list']) && $this->looksLikeGallery()) {
            $out[] = 'its records are mainly an image: set "list": "grid" to list them as a gallery of thumbnails';
        }

        return $out;
    }

    /**
     * A module whose records are mainly an image: an image column, and besides it only a caption, the link to its parent
     * tab, flags and an order (an item's images). Only a hint: the spec chooses (P133 amended), never a guess.
     */
    private function looksLikeGallery(): bool
    {
        $image = array_search('image', $this->media(), true);
        if ($image === false || $this->workflow() !== null || $this->lines() !== null) {
            return false;
        }
        $via = array_column($this->tabOn(), 'via');
        foreach ($this->columns() as $c) {
            $kind = ColumnTypes::kind($c);
            $minor = $c['name'] === $image || $c['name'] === $this->labelColumn() || in_array($c['name'], $via, true) || $kind === 'boolean'
                || ($kind === 'integer' && preg_match('/(^|_)(sort_order|position|order)$/', $c['name']));
            if (! $minor) {
                return false;
            }
        }

        return true;
    }

    public function sync(): bool
    {
        return (bool) ($this->spec['sync'] ?? false);
    }

    /** P128: the dates that put a record on the calendar, or null. @return array{start: string, end: ?string}|null */
    public function calendar(): ?array
    {
        $cal = $this->spec['calendar'] ?? null;

        return is_array($cal) ? ['start' => $cal['start'], 'end' => $cal['end'] ?? null] : null;
    }

    public function keepDays(): ?int
    {
        return isset($this->spec['keep_days']) ? (int) $this->spec['keep_days'] : null;
    }

    /** @return array{key: string, statuses: array<int, array<string, mixed>>}|null */
    public function workflow(): ?array
    {
        return $this->spec['workflow'] ?? null;
    }

    public function search(): ?array
    {
        return $this->spec['search'] ?? null;
    }

    /** The module's own columns, in order, without the skeleton's (id, scope, version, audit, timestamps). */
    public function columns(): array
    {
        return array_values(array_filter($this->spec['columns'], fn (array $c) => ! in_array($c['name'], self::SYSTEM_COLUMNS, true)));
    }

    public function column(string $name): ?array
    {
        foreach ($this->columns() as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return null;
    }

    /** P123 (2026-11-21): the sets of columns no two records share. @return list<list<string>> */
    public function uniqueTogether(): array
    {
        return array_values(array_map(fn ($set) => array_values($set), (array) ($this->spec['unique'] ?? [])));
    }

    /** P131: the media columns, name => what they accept (image or any). @return array<string, string> */
    public function media(): array
    {
        $out = [];
        foreach ($this->columns() as $c) {
            if (ColumnTypes::kind($c) === 'media') {
                $out[$c['name']] = (string) ($c['accept'] ?? 'image');
            }
        }

        return $out;
    }

    /** P133: the spec's presentation keys (icon, phone, sections, fill, menu, menu_section), checked. */
    private static function checkPresentation(array $spec): void
    {
        if (isset($spec['icon']) && (! is_string($spec['icon']) || $spec['icon'] === 'Circle' || ! Icons::exists($spec['icon']))) {
            throw new InvalidArgumentException("generator.json: icon '".json_encode($spec['icon'])."' is not a Lucide icon (and never Circle, the placeholder): name one from lucide.dev, e.g. Package (P133)");
        }
        $phone = $spec['phone'] ?? false;
        if (is_array($phone) && (array_diff(array_keys($phone), ['tab', 'order']) !== [] || (isset($phone['order']) && ! is_int($phone['order'])))) {
            throw new InvalidArgumentException('generator.json: phone is true or { "tab": true, "order": 1 } (P133)');
        }
        $names = array_column($spec['columns'], 'name');
        $placed = [];
        foreach ($spec['sections'] ?? [] as $s) {
            if (! is_string($s['key'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]*$/', $s['key']) || ! is_string($s['label'] ?? null) || ! is_array($s['columns'] ?? null)) {
                throw new InvalidArgumentException('generator.json: a section is { "key": "stock", "label": "Stock", "columns": [...] } (P133)');
            }
            foreach ($s['columns'] as $col) {
                if (! in_array($col, $names, true)) {
                    throw new InvalidArgumentException("generator.json: section {$s['key']} names '{$col}', which is not one of its columns");
                }
                if (isset($placed[$col])) {
                    throw new InvalidArgumentException("generator.json: '{$col}' is in two sections ({$placed[$col]}, {$s['key']})");
                }
                $placed[$col] = $s['key'];
            }
        }
        foreach ($spec['columns'] as $c) {
            if (isset($c['fill']) && ! in_array($c['fill'], ['user', 'workflow', 'server'], true)) {
                throw new InvalidArgumentException("generator.json: {$c['name']}'s fill is user, workflow or server (P133)");
            }
            if (isset($c['meta']) && ! is_bool($c['meta'])) {
                throw new InvalidArgumentException("generator.json: {$c['name']}'s meta is true or false: false keeps it off the list's meta line (contract 12)");
            }
            if (isset($c['span']) && ! in_array($c['span'], [1, 2, 3, 4], true)) {
                throw new InvalidArgumentException("generator.json: {$c['name']}'s span is 1, 2, 3 or 4 quarters of a row (P133)");
            }
            if (($c['fill'] ?? null) === 'user' && ($c['relatedModule'] ?? null) !== 'Users') {
                throw new InvalidArgumentException("generator.json: {$c['name']} is filled with the signed-in user, so it links to Users");
            }
        }
        if (isset($spec['list'])) {
            if (! in_array($spec['list'], ['rows', 'grid'], true)) {
                throw new InvalidArgumentException('generator.json: list is "rows" or "grid" (P133)');
            }
            $images = array_filter($spec['columns'], fn (array $c) => ColumnTypes::kind($c) === 'media' && ($c['accept'] ?? 'image') === 'image');
            if ($spec['list'] === 'grid' && $images === []) {
                throw new InvalidArgumentException('generator.json: list "grid" shows each record\'s image: give it an image column (type media, accept image)');
            }
        }
        foreach ($spec['workflow']['statuses'] ?? [] as $st) {
            if (isset($st['locked']) && ! is_bool($st['locked'])) {
                throw new InvalidArgumentException("generator.json: status {$st['code']}'s locked is true or false (P133)");
            }
        }
        if (isset($spec['menu']) && (! is_array($spec['menu']) || array_diff(array_keys($spec['menu']), ['section', 'position', 'hidden']) !== [])) {
            throw new InvalidArgumentException('generator.json: menu is { "section": "spending", "position": 1, "hidden": false } (P133)');
        }
        if (isset($spec['menu_section'])) {
            $ms = $spec['menu_section'];
            if (! is_string($ms['key'] ?? null) || ! is_string($ms['title'] ?? null) || (isset($ms['icon']) && ! Icons::exists($ms['icon']))) {
                throw new InvalidArgumentException('generator.json: menu_section is { "key": "spending", "title": "Spending", "icon": "Wallet" } with a Lucide icon (P133)');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function foreignKeys(): array
    {
        return array_values(array_filter($this->columns(), fn (array $c) => ColumnTypes::kind($c) === 'foreign'));
    }

    /**
     * P86: the tabs this module adds to its parents' pages, each its own list scoped to the parent record:
     * parent module key => ['key' => tab key, 'via' => this module's column to the parent, 'tab' => label, 'tab_sw' => ?].
     *
     * @return array<string, array{key: string, via: string, tab: string, tab_sw?: string}>
     */
    public function tabOn(): array
    {
        return $this->spec['tab_on'] ?? [];
    }

    /**
     * P84: the record actions, normalised (the legacy placement/destructive read as button/danger):
     * key => [label, label_sw, icon, button (header|more), danger, confirm, bulk, approval, effect, status (a workflow
     * code it moves to), fields (the module's column names it asks for), apikey (its route is open to API keys, P127)].
     *
     * @return array<string, array{label: string, label_sw: ?string, icon: ?string, button: string, danger: bool, confirm: bool, bulk: bool, approval: bool, apikey: bool, effect: ?string, status: ?string, fields: list<string>}>
     */
    public function actions(): array
    {
        $out = [];
        foreach ($this->spec['actions'] ?? [] as $key => $a) {
            $out[$key] = [
                'label' => $a['label'],
                'label_sw' => $a['label_sw'] ?? null,
                'icon' => $a['icon'] ?? null,
                'button' => $a['button'] ?? (($a['placement'] ?? null) === 'main' ? 'header' : 'more'),
                'danger' => (bool) ($a['danger'] ?? $a['destructive'] ?? false),
                'confirm' => (bool) ($a['confirm'] ?? false),
                'bulk' => (bool) ($a['bulk'] ?? false),
                'approval' => (bool) ($a['approval'] ?? false),
                'apikey' => (bool) ($a['apikey'] ?? false),
                'effect' => $a['effect'] ?? null,
                'effect_sw' => $a['effect_sw'] ?? null,
                'status' => $a['status'] ?? null,
                // P134: a reverse applies from the status the action it reverses leads to, unless it says otherwise
                'from' => isset($a['from']) ? array_values((array) $a['from'])
                    : (isset($a['reverses']) ? [$this->spec['actions'][$a['reverses']]['status']] : null),
                'reverses' => $a['reverses'] ?? null,
                'fields' => array_values((array) ($a['fields'] ?? [])),
            ] + $this->doneOf($a);
        }

        return $out;
    }

    /**
     * P133 (amended 2026-11-23): what an action's toast says once it is done: its own `done`, else the status it leads to
     * ("Submitted"); none without either (the shell then says "{label} done").
     *
     * @return array{done: ?string, done_sw: ?string}
     */
    private function doneOf(array $a): array
    {
        $st = isset($a['status']) ? (array_column($this->workflow()['statuses'] ?? [], null, 'code')[$a['status']] ?? null) : null;

        return [
            'done' => $a['done'] ?? $st['label'] ?? null,
            'done_sw' => $a['done_sw'] ?? (isset($a['done']) ? null : ($st['label_sw'] ?? null)),
        ];
    }

    /** P133 (amended 2026-11-23): how the list shows its records: rows, or a grid of thumbnails (a gallery). */
    public function listAs(): string
    {
        return ($this->spec['list'] ?? 'rows') === 'grid' ? 'grid' : 'rows';
    }

    /** The codes of the workflow's locked statuses (`locked`, else `is_terminal`), P133 amended. @return list<string> */
    public function lockedStatuses(): array
    {
        return array_values(array_map(fn (array $s) => (string) $s['code'], array_filter($this->workflow()['statuses'] ?? [], fn (array $s) => (bool) ($s['locked'] ?? $s['is_terminal'] ?? false))));
    }

    /** The column that holds the workflow's status (type workflow), or null. */
    public function workflowColumn(): ?string
    {
        foreach ($this->columns() as $c) {
            if (ColumnTypes::kind($c) === 'workflow') {
                return $c['name'];
            }
        }

        return null;
    }

    /**
     * P115: the document's lines, or null: kind (sale|purchase|stock), item_module, item_price (the item's price column,
     * for a priced kind), prices_include_vat, editable_status (a workflow code; none = always editable).
     *
     * @return array{kind: string, item_module: string, item_price: ?string, prices_include_vat: bool, editable_status: ?string, label_sw: ?string}|null
     */
    public function lines(): ?array
    {
        $l = $this->spec['lines'] ?? null;

        return $l === null ? null : [
            'kind' => $l['kind'] ?? 'sale',
            'item_module' => $l['item_module'],
            'item_price' => $l['item_price'] ?? null,
            'prices_include_vat' => (bool) ($l['prices_include_vat'] ?? false),
            'editable_status' => $l['editable_status'] ?? null,
            'label_sw' => $l['label_sw'] ?? null,
        ];
    }

    /** P115: lines with money (sale, purchase); a stock document has quantities only. */
    public function priced(): bool
    {
        return $this->lines() !== null && $this->lines()['kind'] !== 'stock';
    }

    /** P115: the document's number, made on create, or null: column, prefix, label, label_sw. */
    public function number(): ?array
    {
        $n = $this->spec['number'] ?? null;

        return $n === null ? null : ['column' => $n['column'], 'prefix' => $n['prefix'], 'label' => $n['label'] ?? 'Number', 'label_sw' => $n['label_sw'] ?? null];
    }

    /** The lines table: quotes → quote_lines; its key to the document: quote_id. */
    public function linesTable(): string
    {
        return Str::singular($this->table()).'_lines';
    }

    public function linesKey(): string
    {
        return Str::singular($this->table()).'_id';
    }

    /** An override from features.backend.{feature}.{key}, or null. */
    public function backend(string $feature, string $key): mixed
    {
        return $this->spec['features']['backend'][$feature][$key] ?? null;
    }
}
