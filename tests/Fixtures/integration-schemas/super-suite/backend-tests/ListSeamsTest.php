<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteContracts\SuiteContractsModel;
use App\Project\Modules\System\Suite\SuiteTickets\Services\SuiteTicketsListService;
use App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The list endpoint's contract against GENERATED modules: what each filter operator does to a number
 * and to a date, how sorting is allowlisted, how pagination is capped, and the hand-owned seams a list
 * service offers (a row enricher, a pre-scoped query, grouped counts).
 *
 * Why here rather than in a generated spec. Each generated spec exercises ONE filter per module (the
 * first text column) and never clicks a sortable header, so the operators a number or a date column
 * actually uses were never driven by anything generated. The seams are the reverse: the engine's unit
 * tests assert their emitted strings, and the app's own tests run them against a hand-written model.
 * This is the first place they meet generated code.
 *
 * suite_contracts supplies the columns (a decimal, two dates, a string with a default); suite_tickets
 * has a nullable column and is the module the seams are called on. Acts as the seeded DEVELOPER;
 * neither module has a location column.
 */
class ListSeamsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    private function contract(string $marker, string $suffix, string $rent, string $start, string $status = 'draft'): SuiteContractsModel
    {
        return SuiteContractsModel::create([
            'code' => "{$marker}-{$suffix}",
            'status' => $status,
            'deposit_status' => 'none',
            'start_date' => $start,
            'end_date' => date('Y-m-d', strtotime($start . ' +1 year')),
            'billing_cycle_months' => 12,
            'grace_days' => 0,
            'monthly_rent' => $rent,
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
    }

    /**
     * Four contracts under one marker, so every assertion is scoped to rows this test made.
     *
     * @return array<string, SuiteContractsModel> keyed A..D
     */
    private function contracts(): array
    {
        $m = 'LS' . substr(uniqid(), -8);

        return [
            'marker' => $m,
            'A' => $this->contract($m, 'A', '100.00', '2026-01-10', 'draft'),
            'B' => $this->contract($m, 'B', '500.00', '2026-02-10', 'active'),
            'C' => $this->contract($m, 'C', '900.00', '2026-03-10', 'active'),
            'D' => $this->contract($m, 'D', '1300.00', '2026-04-10', 'terminated'),
        ];
    }

    /**
     * Codes (suffix only) of the marker's contracts the list returns for these extra query parameters,
     * in the order returned.
     *
     * @return list<string>
     */
    private function listed(string $marker, string $extraQuery = ''): array
    {
        $url = '/api/suite-contracts/list?params[per_page]=100'
            . '&filters[code][operator]=contains&filters[code][value]=' . $marker . $extraQuery;
        $rows = $this->getJson($url)->assertStatus(200)->json('data.data');

        return array_map(fn ($r) => substr($r['code'], strlen($marker) + 1), $rows);
    }

    private function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }

    // ─── Number filters ──────────────────────────────────────────────────────

    public function test_number_comparison_operators(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];
        $rent = fn (string $op, $v) => "&filters[monthly_rent][operator]={$op}&filters[monthly_rent][value]={$v}";

        $this->assertSame(['C', 'D'], $this->sorted($this->listed($m, $rent('gt', 500))), 'gt excludes the boundary');
        $this->assertSame(['B', 'C', 'D'], $this->sorted($this->listed($m, $rent('gte', 500))), 'gte includes it');
        $this->assertSame(['A'], $this->sorted($this->listed($m, $rent('lt', 500))));
        $this->assertSame(['A', 'B'], $this->sorted($this->listed($m, $rent('lte', 500))));
        $this->assertSame(['B'], $this->sorted($this->listed($m, $rent('eq', '500'))));
        $this->assertSame(['A', 'C', 'D'], $this->sorted($this->listed($m, $rent('neq', '500'))));
    }

    public function test_number_between_in_and_not_in(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];

        $between = '&filters[monthly_rent][operator]=between&filters[monthly_rent][value][]=500&filters[monthly_rent][value][]=900';
        $this->assertSame(['B', 'C'], $this->sorted($this->listed($m, $between)), 'between is inclusive at both ends');

        $in = '&filters[monthly_rent][operator]=in&filters[monthly_rent][value][]=100&filters[monthly_rent][value][]=1300';
        $this->assertSame(['A', 'D'], $this->sorted($this->listed($m, $in)));

        $nin = '&filters[monthly_rent][operator]=nin&filters[monthly_rent][value][]=100&filters[monthly_rent][value][]=1300';
        $this->assertSame(['B', 'C'], $this->sorted($this->listed($m, $nin)));
    }

    // ─── Date filters ────────────────────────────────────────────────────────

    public function test_date_filters_treat_a_bare_date_as_the_whole_day(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];
        $start = fn (string $op, $v) => "&filters[start_date][operator]={$op}&filters[start_date][value]={$v}";

        $this->assertSame(['B'], $this->listed($m, $start('eq', '2026-02-10')));
        $this->assertSame(['A', 'C', 'D'], $this->sorted($this->listed($m, $start('neq', '2026-02-10'))), 'neq excludes the whole day');
        $this->assertSame(['A', 'B'], $this->sorted($this->listed($m, $start('lte', '2026-02-10'))), 'lte includes the day itself');

        $between = '&filters[start_date][operator]=between&filters[start_date][value][]=2026-02-10&filters[start_date][value][]=2026-03-10';
        $this->assertSame(['B', 'C'], $this->sorted($this->listed($m, $between)), 'between includes both boundary days');
    }

    // ─── Text and null filters ───────────────────────────────────────────────

    public function test_text_operators_and_membership_on_a_string_column(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];
        $status = fn (string $op, $v) => "&filters[status][operator]={$op}&filters[status][value]={$v}";

        $this->assertSame(['B', 'C'], $this->sorted($this->listed($m, $status('eq', 'active'))));
        $this->assertSame(['B', 'C'], $this->sorted($this->listed($m, $status('begins', 'act'))));
        $this->assertSame(['A'], $this->sorted($this->listed($m, $status('ends', 'raft'))));
        $this->assertSame(['D'], $this->sorted($this->listed($m, $status('contains', 'minat'))));
    }

    public function test_null_and_not_null_on_a_nullable_column(): void
    {
        $marker = 'LN' . substr(uniqid(), -8);
        $unassigned = SuiteTicketsModel::create(['title' => "{$marker} unassigned", 'status_id' => 1, 'priority' => 'normal', 'created_by_id' => UsersModel::DEVELOPER])->fresh();
        $assigned = SuiteTicketsModel::create(['title' => "{$marker} assigned", 'status_id' => 1, 'priority' => 'normal', 'assignee_id' => UsersModel::DEVELOPER, 'created_by_id' => UsersModel::DEVELOPER])->fresh();

        $base = "/api/suite-tickets/list?params[per_page]=100&filters[title][operator]=contains&filters[title][value]={$marker}";
        $titles = fn (string $extra) => array_column($this->getJson($base . $extra)->assertStatus(200)->json('data.data'), 'title');

        $this->assertSame(["{$marker} unassigned"], $titles('&filters[assignee_id][operator]=null&filters[assignee_id][value]=1'));
        $this->assertSame(["{$marker} assigned"], $titles('&filters[assignee_id][operator]=not_null&filters[assignee_id][value]=1'));
    }

    public function test_a_filter_the_list_does_not_allow_is_ignored_not_an_error(): void
    {
        $c = $this->contracts();

        // A column outside the allowlist, and an operator that does not exist: neither may 500, and
        // neither may narrow the result (an ignored filter is not "matches nothing").
        $this->assertSame(['A', 'B', 'C', 'D'], $this->sorted($this->listed(
            $c['marker'],
            '&filters[password][operator]=eq&filters[password][value]=x&filters[status][operator]=frobnicate&filters[status][value]=x'
        )));
    }

    // ─── Sorting and pagination ──────────────────────────────────────────────

    public function test_sorting_by_an_allowed_column_orders_both_ways(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];

        $this->assertSame(['A', 'B', 'C', 'D'], $this->listed($m, '&params[sort]=monthly_rent&params[order]=asc'));
        $this->assertSame(['D', 'C', 'B', 'A'], $this->listed($m, '&params[sort]=monthly_rent&params[order]=desc'));
        $this->assertSame(['A', 'B', 'C', 'D'], $this->listed($m, '&params[sort]=start_date&params[order]=asc'));
    }

    public function test_sorting_by_a_column_outside_the_allowlist_falls_back_instead_of_failing(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];

        // An arbitrary string must never reach ORDER BY. `uuid` is filterable but not sortable here.
        $this->getJson('/api/suite-contracts/list?params[sort]=' . urlencode('monthly_rent; DROP TABLE users') . '&params[order]=asc')
            ->assertStatus(200);
        $this->assertCount(4, $this->listed($m, '&params[sort]=uuid&params[order]=asc'));
        // The default is newest first, so the last-created row leads.
        $this->assertSame('D', $this->listed($m)[0]);
        // An invalid direction is not "asc".
        $this->assertSame('D', $this->listed($m, '&params[sort]=created_at&params[order]=sideways')[0]);
    }

    public function test_pagination_is_capped_and_pages_do_not_overlap(): void
    {
        $c = $this->contracts();
        $m = $c['marker'];
        $q = '/api/suite-contracts/list?filters[code][operator]=contains&filters[code][value]=' . $m . '&params[sort]=monthly_rent&params[order]=asc';

        $page1 = $this->getJson($q . '&params[per_page]=2&params[page]=1')->assertStatus(200);
        $page2 = $this->getJson($q . '&params[per_page]=2&params[page]=2')->assertStatus(200);
        $this->assertSame(4, $page1->json('data.meta.total'));
        $this->assertSame(2, $page1->json('data.meta.last_page'));
        $this->assertSame(
            ['A', 'B', 'C', 'D'],
            array_map(fn ($r) => substr($r['code'], strlen($m) + 1), array_merge($page1->json('data.data'), $page2->json('data.data'))),
            'two pages of two cover four rows once each'
        );

        $this->assertLessThanOrEqual(
            100,
            $this->getJson($q . '&params[per_page]=5000')->json('data.meta.per_page'),
            'a client cannot ask for more than 100 a page'
        );
    }

    // ─── Hand-owned seams on a generated list service ────────────────────────

    private function ticketRows(string $marker, int $count, array $overrides = []): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = SuiteTicketsModel::create(array_merge([
                'title' => "{$marker} {$i}", 'status_id' => 1, 'priority' => 'normal', 'created_by_id' => UsersModel::DEVELOPER,
            ], $overrides))->fresh();
        }

        return $rows;
    }

    private function markerFilter(string $marker): array
    {
        return ['params' => ['per_page' => 100], 'filters' => ['title' => ['operator' => 'contains', 'value' => $marker]]];
    }

    public function test_a_row_enricher_adds_fields_to_the_list_and_to_the_export(): void
    {
        $marker = 'EN' . substr(uniqid(), -8);
        $this->ticketRows($marker, 3);
        $shout = fn (array $rows) => array_map(fn ($r) => $r + ['shout' => strtoupper($r['title'])], $rows);

        $list = SuiteTicketsListService::execute($this->markerFilter($marker), false, 'csv', null, $shout);
        $rows = $list['data']['data'];
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(strtoupper($row['title']), $row['shout']);
        }

        // The point of the seam: an enrichment applied by post-processing the LIST response never reached
        // the export path. The enricher is on both.
        $export = SuiteTicketsListService::execute($this->markerFilter($marker), true, 'csv', null, $shout);
        ob_start();
        $export->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString(strtoupper($marker), $csv, 'the enriched value is in the export');
    }

    public function test_an_enricher_that_changes_the_row_count_is_refused(): void
    {
        $marker = 'EL' . substr(uniqid(), -8);
        $this->ticketRows($marker, 3);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('one row per input row');

        SuiteTicketsListService::execute($this->markerFilter($marker), false, 'csv', null, fn (array $rows) => array_slice($rows, 1));
    }

    public function test_a_pre_scoped_query_narrows_the_list_before_pagination(): void
    {
        $marker = 'PQ' . substr(uniqid(), -8);
        $this->ticketRows($marker, 2, ['priority' => 'high']);
        $this->ticketRows($marker . 'x', 3, ['priority' => 'low']);

        $result = SuiteTicketsListService::execute(
            $this->markerFilter($marker),
            false,
            'csv',
            SuiteTicketsModel::query()->where('priority', 'high')
        );

        $this->assertSame(2, $result['data']['meta']['total'], 'the caller-supplied scope is part of the count, not applied afterwards');
        foreach ($result['data']['data'] as $row) {
            $this->assertSame('high', $row['priority']);
        }
    }

    public function test_scoped_query_and_list_counts_group_under_the_same_scope_as_the_list(): void
    {
        $marker = 'LC' . substr(uniqid(), -8);
        $this->ticketRows($marker, 3, ['priority' => 'high']);
        $this->ticketRows($marker, 2, ['priority' => 'low']);
        $data = $this->markerFilter($marker);

        $this->assertSame(5, SuiteTicketsListService::scopedQuery(SuiteTicketsModel::query(), $data)->count());

        $counts = SuiteTicketsListService::listCounts(SuiteTicketsModel::query(), 'priority', $data);
        $byValue = array_column($counts['data'], 'count', 'value');
        $this->assertSame(3, $byValue['high']);
        $this->assertSame(2, $byValue['low']);
        $this->assertSame('priority', $counts['meta']['field']);

        // The counted field's OWN filter is dropped -- "what would the list hold if I picked this value".
        $narrowed = $data;
        $narrowed['filters']['priority'] = ['operator' => 'eq', 'value' => 'high'];
        $again = array_column(SuiteTicketsListService::listCounts(SuiteTicketsModel::query(), 'priority', $narrowed)['data'], 'count', 'value');
        $this->assertSame(2, $again['low'], 'the other groups stay visible when this field is filtered');
    }

    public function test_list_counts_refuses_a_field_that_is_not_countable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SuiteTicketsListService::listCounts(SuiteTicketsModel::query(), 'password');
    }
}
