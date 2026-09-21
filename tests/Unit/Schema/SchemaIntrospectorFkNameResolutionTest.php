<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\FkAliases;
use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Which table a `*_id` column points at, decided by its name when the database declares no constraint.
 *
 * Until now only a base word that pluralised/singularised straight to a table was recognised (plus parent_id and
 * *_by_id). Anything else was silently demoted to a plain integer on a full regenerate: no FK picker, no
 * relation, and no failing test, only a live 403 or a missing select in the browser. Three shapes were missed:
 *
 *   source_quotation_id  -> quotations          a qualifier prefix blocks the match
 *   category_id          -> item_categories     the base word is not in the table name
 *   unit_of_measure_id   -> units_of_measure    pluralised on the wrong word
 *
 * The first is resolved by dropping known qualifier words; the other two cannot be guessed and are declared in
 * fk_aliases.json.
 */
class SchemaIntrospectorFkNameResolutionTest extends TestCase
{
    /** @param string[] $tables */
    private function resolve(string $table, string $column, array $tables, array $aliases = []): ?array
    {
        return SchemaIntrospector::resolveFkTargetByName($table, $column, static fn (string $t): bool => in_array($t, $tables, true), $aliases);
    }

    protected function tearDown(): void
    {
        FkAliases::reset();
        PathManager::setIssueHandler(null);
        SchemaIntrospector::setIssueHandler(null);
        parent::tearDown();
    }

    /**
     * The introspector's real entry point (a private method), against a schema that has exactly these tables.
     *
     * @param string[] $tables
     * @return array{result: ?array, warnings: string[]}
     */
    private function inferOnIntrospector(string $table, string $column, array $tables): array
    {
        $warnings = [];
        SchemaIntrospector::setIssueHandler(function (string $message) use (&$warnings) {
            $warnings[] = $message;
        });

        $method = new \ReflectionMethod(SchemaIntrospector::class, 'inferFkByConvention');
        $method->setAccessible(true);

        $introspector = new class($table, $tables) extends SchemaIntrospector {
            public function __construct(string $table, private array $tables)
            {
                parent::__construct($table);
            }

            protected function tableExists(string $table): bool
            {
                return in_array($table, $this->tables, true);
            }
        };

        return ['result' => $method->invoke($introspector, $column, [$column]), 'warnings' => $warnings];
    }

    public function test_the_introspector_uses_the_resolver_and_the_aliases_file(): void
    {
        FkAliases::set(['category_id' => 'item_categories']);

        $viaAlias = $this->inferOnIntrospector('items', 'category_id', ['items', 'item_categories']);
        $this->assertSame(['foreign_table' => 'item_categories', 'foreign_column' => 'id'], $viaAlias['result']);
        $this->assertSame([], $viaAlias['warnings'], 'a declared alias needs no note');

        FkAliases::reset();
        $this->assertNull($this->inferOnIntrospector('items', 'category_id', ['items', 'item_categories'])['result'], 'without the alias, still a plain integer');
    }

    public function test_an_inferred_qualifier_match_says_what_was_guessed_and_how_to_state_it(): void
    {
        $out = $this->inferOnIntrospector('sales_orders', 'source_quotation_id', ['sales_orders', 'quotations']);

        $this->assertSame('quotations', $out['result']['foreign_table']);
        $this->assertCount(1, $out['warnings']);
        $this->assertStringContainsString('inferred as a FK to `quotations` by ignoring the qualifier `source_`', $out['warnings'][0]);
        $this->assertStringContainsString('fk_aliases.json', $out['warnings'][0]);
    }

    public function test_an_alias_to_a_missing_table_is_reported_not_silently_dropped(): void
    {
        FkAliases::set(['category_id' => 'item_categorys']);

        $out = $this->inferOnIntrospector('items', 'category_id', ['items', 'item_categories']);

        $this->assertNull($out['result']);
        $this->assertStringContainsString('maps `items`.`category_id` to `item_categorys`, but that table does not exist', $out['warnings'][0]);
    }

    // ─── What already worked keeps working ───────────────────────────────────

    public function test_a_base_word_that_is_a_table_resolves(): void
    {
        $this->assertSame(['table' => 'item_types', 'via' => 'name'], $this->resolve('items', 'item_type_id', ['item_types']));
        $this->assertSame(['table' => 'sample', 'via' => 'name'], $this->resolve('x', 'sample_id', ['sample']), 'a singular table name');
    }

    public function test_parent_id_is_a_self_reference_and_by_id_is_users(): void
    {
        $this->assertSame(['table' => 'locations', 'via' => 'parent'], $this->resolve('locations', 'parent_id', []));
        $this->assertSame(['table' => 'users', 'via' => 'by'], $this->resolve('orders', 'approved_by_id', ['users']));
        $this->assertNull($this->resolve('orders', 'approved_by_id', []), 'no users table, no guess');
        $this->assertNull($this->resolve('orders', 'created_by_id', ['users']), 'the audit columns are not user-facing FKs');
    }

    // ─── Direction 1: a qualifier prefix ────────────────────────────────────

    public function test_a_leading_qualifier_is_ignored_when_the_rest_is_a_table(): void
    {
        $this->assertSame(
            ['table' => 'quotations', 'via' => 'qualifier', 'qualifier' => 'source'],
            $this->resolve('sales_orders', 'source_quotation_id', ['quotations'])
        );
        $this->assertSame('currencies', $this->resolve('customers', 'default_currency_id', ['currencies'])['table']);
        $this->assertSame('warehouses', $this->resolve('transfers', 'from_warehouse_id', ['warehouses'])['table']);
        $this->assertSame('warehouses', $this->resolve('transfers', 'to_warehouse_id', ['warehouses'])['table']);
    }

    public function test_more_than_one_leading_qualifier_can_be_dropped(): void
    {
        $resolved = $this->resolve('customers', 'default_billing_address_id', ['addresses']);

        $this->assertSame('addresses', $resolved['table']);
        $this->assertSame('qualifier', $resolved['via']);
    }

    public function test_the_full_name_is_tried_before_any_qualifier_is_dropped(): void
    {
        $resolved = $this->resolve('orders', 'source_quotation_id', ['source_quotations', 'quotations']);

        $this->assertSame(['table' => 'source_quotations', 'via' => 'name'], $resolved);
    }

    public function test_a_word_that_is_not_a_known_qualifier_is_never_dropped(): void
    {
        // Dropping "any leading word" would link a column to an unrelated table silently.
        $this->assertNull($this->resolve('orders', 'legacy_quotation_id', ['quotations']));
        $this->assertNull($this->resolve('orders', 'source_id', ['sources_x']), 'nothing left to strip');
    }

    // ─── Direction 2: explicit aliases ──────────────────────────────────────

    public function test_an_alias_resolves_a_compound_noun_that_no_naming_rule_can(): void
    {
        $tables = ['item_categories', 'units_of_measure'];
        $aliases = ['category_id' => 'item_categories', 'unit_of_measure_id' => 'units_of_measure'];

        $this->assertNull($this->resolve('items', 'category_id', $tables), 'without an alias the name cannot answer');
        $this->assertSame(['table' => 'item_categories', 'via' => 'alias'], $this->resolve('items', 'category_id', $tables, $aliases));
        $this->assertSame(['table' => 'units_of_measure', 'via' => 'alias'], $this->resolve('items', 'unit_of_measure_id', $tables, $aliases));
    }

    public function test_a_table_scoped_alias_wins_over_a_bare_one_and_only_applies_to_its_table(): void
    {
        $tables = ['item_categories', 'expense_categories'];
        $aliases = ['category_id' => 'item_categories', 'expenses.category_id' => 'expense_categories'];

        $this->assertSame('expense_categories', $this->resolve('expenses', 'category_id', $tables, $aliases)['table']);
        $this->assertSame('item_categories', $this->resolve('items', 'category_id', $tables, $aliases)['table']);
    }

    public function test_an_alias_beats_every_guess_including_parent_and_by(): void
    {
        $this->assertSame('employees', $this->resolve('orders', 'approved_by_id', ['users', 'employees'], ['approved_by_id' => 'employees'])['table']);
        $this->assertSame('org_units', $this->resolve('org_units', 'parent_id', ['org_units'], ['org_units.parent_id' => 'org_units'])['table']);
    }

    public function test_an_alias_to_a_table_that_does_not_exist_is_not_trusted(): void
    {
        $this->assertSame(
            ['table' => 'item_types', 'via' => 'name'],
            $this->resolve('items', 'item_type_id', ['item_types'], ['item_type_id' => 'typo_table']),
            'falls through to the convention'
        );
        $this->assertNull($this->resolve('items', 'category_id', [], ['category_id' => 'item_categories']));
    }

    // ─── The aliases file ───────────────────────────────────────────────────

    public function test_the_aliases_file_is_read_and_underscore_keys_are_ignored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fk-aliases-');
        file_put_contents($path, json_encode([
            '_comment' => 'why these exist',
            'category_id' => 'item_categories',
            'sales_orders.source_quotation_id' => 'quotations',
            'bad' => 12,
        ]));

        try {
            $this->assertSame(
                ['category_id' => 'item_categories', 'sales_orders.source_quotation_id' => 'quotations'],
                FkAliases::readFile($path)
            );
        } finally {
            unlink($path);
        }
    }

    public function test_a_file_that_is_not_an_object_is_reported_and_read_as_empty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fk-aliases-');
        file_put_contents($path, '"not an object"');
        $reported = [];
        PathManager::setIssueHandler(function (string $message) use (&$reported) {
            $reported[] = $message;
        });

        try {
            $this->assertSame([], FkAliases::readFile($path));
            $this->assertCount(1, $reported);
            $this->assertStringContainsString('fk_aliases.json', $reported[0]);
        } finally {
            unlink($path);
        }
    }

    public function test_lookup_prefers_the_table_scoped_key_and_nothing_loads_without_a_booted_app(): void
    {
        FkAliases::set(['category_id' => 'item_categories', 'expenses.category_id' => 'expense_categories']);

        $this->assertSame('expense_categories', FkAliases::lookup('expenses', 'category_id'));
        $this->assertSame('item_categories', FkAliases::lookup('items', 'category_id'));
        $this->assertNull(FkAliases::lookup('items', 'unknown_id'));

        FkAliases::reset();
        $this->assertSame([], FkAliases::all(), 'no Laravel application, so no file to read');
    }

    // ─── Direction 3: a correction made by hand in module.json survives the next --force ───────────

    public function test_a_persisted_foreign_key_is_remembered_and_resolves_where_the_name_cannot(): void
    {
        PathManager::setModuleRegistry([['name' => 'ItemCategories', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'item_categories']]);

        $count = FkAliases::rememberFromConfig([
            'table_name' => 'items',
            'columns' => [
                ['name' => 'category_id', 'type' => 'foreignId', 'relatedModule' => 'ItemCategories'],
                ['name' => 'quantity', 'type' => 'integer'],
                ['name' => 'owner_id', 'type' => 'foreignId', 'relatedModule' => 'Nobody'],
            ],
        ]);

        $this->assertSame(1, $count, 'only the foreignId column whose module resolves to a table');
        $this->assertNull($this->resolve('items', 'category_id', ['item_categories']), 'forgotten, the name still cannot answer');
        $this->assertSame(
            ['table' => 'item_categories', 'via' => 'alias'],
            $this->resolve('items', 'category_id', ['item_categories'], FkAliases::all())
        );
        $this->assertNull($this->resolve('items', 'category_id', ['item_categories'], ['other.category_id' => 'item_categories']), 'remembered per table');
        PathManager::setModuleRegistry([]);
    }

    public function test_a_declared_alias_overrides_a_remembered_one_and_reset_forgets_both(): void
    {
        FkAliases::remember('items', 'category_id', 'old_categories');
        FkAliases::set(['items.category_id' => 'item_categories']);

        $this->assertSame('item_categories', FkAliases::all()['items.category_id'], 'what the developer declared wins');

        FkAliases::reset();
        $this->assertSame([], FkAliases::all());
    }

    public function test_a_remembered_target_that_no_longer_exists_is_not_trusted(): void
    {
        FkAliases::remember('items', 'category_id', 'dropped_table');

        $this->assertNull($this->resolve('items', 'category_id', ['items'], FkAliases::all()));
    }

    public function test_the_introspector_keeps_a_remembered_foreign_key_and_does_not_call_it_a_file_error(): void
    {
        FkAliases::remember('items', 'category_id', 'item_categories');

        $kept = $this->inferOnIntrospector('items', 'category_id', ['items', 'item_categories']);
        $this->assertSame('item_categories', $kept['result']['foreign_table']);

        FkAliases::reset();
        FkAliases::remember('items', 'category_id', 'dropped_table');
        $dropped = $this->inferOnIntrospector('items', 'category_id', ['items']);
        $this->assertNull($dropped['result']);
        $this->assertSame([], $dropped['warnings'], 'fk_aliases.json was not involved, so it is not blamed');
    }
}
