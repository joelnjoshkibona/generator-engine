<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The EFFECTS that generated specs cannot see. The generated action spec proves a dialog closes; the
 * generated bulk spec proves a result drawer opens. Neither proves a record changed, and every
 * generated service is a write-once stub ("TODO: implement ...") with nothing to change it.
 *
 * What is real here, and why:
 *  - `close` is a bulk action with `status_target: DONE`, whose service the engine DOES write
 *    (`status_id = Model::DONE`), so it is the one bulk effect that needs no overlay.
 *  - `expedite`, `escalate` and `assign` are stubs the fixture's module-overlays/ fill in (an overlay
 *    is copied over the generated file after generation), so a bulk row can fail, a wizard's input can
 *    reach the record, and `serviceMethod`/`serviceArgs` can be seen to bind a real method.
 *  - `archive` is left as generated: a no-op body, which is exactly what a real project starts with.
 *  - Export and import stay mechanism-only. The list service is written wholesale (no hand region), so
 *    an overlay would be a copy that hides regressions in the very file under test.
 *
 * Acts as the seeded DEVELOPER; `suite_tickets` has no location column, so scope is not in play.
 */
class TicketActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    private function ticket(array $overrides = []): SuiteTicketsModel
    {
        return SuiteTicketsModel::create(array_merge([
            'title' => 'Ticket ' . uniqid(),
            'status_id' => SuiteTicketsModel::OPEN,
            'priority' => 'normal',
            'created_by_id' => UsersModel::DEVELOPER,
        ], $overrides))->fresh();
    }

    private function bulk(array $payload)
    {
        return $this->postJson('/api/suite-tickets/bulk-action', $payload);
    }

    // ─── Bulk actions ────────────────────────────────────────────────────────

    public function test_a_status_target_bulk_action_changes_every_selected_row(): void
    {
        $tickets = [$this->ticket(), $this->ticket(), $this->ticket()];
        $untouched = $this->ticket();

        $response = $this->bulk(['action' => 'close', 'mode' => 'ids', 'ids' => array_map(fn ($t) => $t->uuid, $tickets)])
            ->assertStatus(200);

        $this->assertSame(3, $response->json('data.succeeded_count'));
        $this->assertSame(0, $response->json('data.failed_count'));
        foreach ($tickets as $t) {
            $this->assertSame(SuiteTicketsModel::DONE, (int) SuiteTicketsModel::find($t->id)->status_id);
        }
        $this->assertSame(SuiteTicketsModel::OPEN, (int) SuiteTicketsModel::find($untouched->id)->status_id, 'a row not selected is untouched');
    }

    public function test_a_row_that_fails_is_reported_and_does_not_undo_the_others(): void
    {
        $ok = $this->ticket(['priority' => 'normal']);
        $fails = $this->ticket(['priority' => 'normal', 'reason' => 'BLOCKED']);

        $response = $this->bulk(['action' => 'expedite', 'mode' => 'ids', 'ids' => [$ok->uuid, $fails->uuid]]);

        // 207 Multi-Status: some rows succeeded, some did not -- never a blanket 200 or 500.
        $response->assertStatus(207);
        $this->assertSame(1, $response->json('data.succeeded_count'));
        $this->assertSame(1, $response->json('data.failed_count'));
        $this->assertStringContainsString('Blocked tickets', json_encode($response->json('data.failed')));

        $this->assertSame('high', SuiteTicketsModel::find($ok->id)->priority);
        $this->assertSame('normal', SuiteTicketsModel::find($fails->id)->priority, 'the failed row is untouched');
    }

    public function test_filter_mode_acts_on_everything_matching_minus_the_excluded_rows(): void
    {
        $marker = 'BULKF-' . uniqid();
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = $this->ticket(['title' => "{$marker} {$i}"]);
        }
        $other = $this->ticket(['title' => 'Unrelated ' . uniqid()]);

        $response = $this->bulk([
            'action' => 'close',
            'mode' => 'filter',
            'filter' => ['filters' => ['title' => ['operator' => 'contains', 'value' => $marker]]],
            'exclude_ids' => [$rows[0]->uuid],
        ])->assertStatus(200);

        $this->assertSame(3, $response->json('data.succeeded_count'));
        $this->assertSame(SuiteTicketsModel::OPEN, (int) SuiteTicketsModel::find($rows[0]->id)->status_id, 'the excluded row');
        $this->assertSame(SuiteTicketsModel::DONE, (int) SuiteTicketsModel::find($rows[1]->id)->status_id);
        $this->assertSame(SuiteTicketsModel::OPEN, (int) SuiteTicketsModel::find($other->id)->status_id, 'a row the filter does not match');
    }

    public function test_a_generated_stub_bulk_action_runs_and_reports_success(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();

        $response = $this->bulk(['action' => 'archive', 'mode' => 'ids', 'ids' => [$a->uuid, $b->uuid]])->assertStatus(200);

        $this->assertSame(2, $response->json('data.succeeded_count'));
    }

    public function test_bulk_requests_are_validated(): void
    {
        $t = $this->ticket();

        $this->bulk(['action' => 'nope', 'mode' => 'ids', 'ids' => [$t->uuid]])->assertStatus(422);
        $this->bulk(['action' => 'close', 'mode' => 'sideways', 'ids' => [$t->uuid]])->assertStatus(422);
        $this->bulk(['action' => 'close', 'mode' => 'ids', 'ids' => []])->assertStatus(422);
        $this->bulk(['action' => 'close', 'mode' => 'filter'])->assertStatus(422);
    }

    // ─── Export and import: the mechanism, not row logic ─────────────────────

    public function test_export_returns_the_rows_as_csv(): void
    {
        $t = $this->ticket(['title' => 'Exported ' . uniqid()]);

        $response = $this->get('/api/suite-tickets/list/export?format=csv')->assertStatus(200);

        $this->assertStringContainsString('csv', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $header = strtolower(strtok($body, "\n"));
        $this->assertStringContainsString('title', $header);
        $this->assertStringContainsString($t->title, $body);
    }

    public function test_the_import_template_names_the_columns_and_a_dry_run_writes_nothing(): void
    {
        $template = $this->get('/api/suite-tickets/import/template?format=csv')->assertStatus(200);
        $this->assertStringContainsString('title', strtolower(strtok($template->streamedContent(), "\n")));

        $before = SuiteTicketsModel::count();
        $csv = "title,priority\nImport A " . uniqid() . ",low\nImport B " . uniqid() . ",high\n";

        $response = $this->post('/api/suite-tickets/import', [
            'file' => UploadedFile::fake()->createWithContent('tickets.csv', $csv),
            'dry_run' => '1',
        ], ['Accept' => 'application/json'])->assertStatus(200);

        $this->assertTrue((bool) $response->json('data.dry_run'));
        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame($before, SuiteTicketsModel::count(), 'a dry run writes nothing');
    }

    // ─── Action services ─────────────────────────────────────────────────────

    public function test_a_wizard_actions_input_reaches_the_record_and_is_validated(): void
    {
        $t = $this->ticket();

        $this->postJson("/api/suite-tickets/escalate/{$t->uuid}/create", [
            'priority' => 'high', 'assignee_id' => UsersModel::DEVELOPER, 'reason' => 'Customer is waiting',
        ])->assertStatus(200);

        $fresh = SuiteTicketsModel::find($t->id);
        $this->assertSame('high', $fresh->priority);
        $this->assertSame((int) UsersModel::DEVELOPER, (int) $fresh->assignee_id);
        $this->assertSame('Customer is waiting', $fresh->reason);

        $this->postJson("/api/suite-tickets/escalate/{$t->uuid}/create", ['priority' => 'urgent'])->assertStatus(422);
        $this->postJson('/api/suite-tickets/escalate/00000000-0000-0000-0000-000000000000/create', [
            'priority' => 'low', 'assignee_id' => UsersModel::DEVELOPER, 'reason' => 'x',
        ])->assertStatus(404);
    }

    public function test_service_method_and_service_args_bind_a_real_method_and_the_splash_preloads(): void
    {
        $t = $this->ticket(['assignee_id' => null]);

        $splash = $this->getJson("/api/suite-tickets/{$t->uuid}/assign/splash")->assertStatus(200);
        $this->assertNotEmpty($splash->json('data.assignees'), 'the splash pre-loads the option list');
        $this->assertNull($splash->json('data.current_assignee_id'));

        // The endpoint calls assignTo($data, $uuid, $user) -- named by serviceMethod, with the
        // arguments serviceArgs listed -- not the default execute($data, $uuid).
        $this->postJson("/api/suite-tickets/assign/{$t->uuid}/create", ['assignee_id' => UsersModel::DEVELOPER])
            ->assertStatus(200);

        $this->assertSame((int) UsersModel::DEVELOPER, (int) SuiteTicketsModel::find($t->id)->assignee_id);
        $this->assertSame(
            (int) UsersModel::DEVELOPER,
            (int) $this->getJson("/api/suite-tickets/{$t->uuid}/assign/splash")->json('data.current_assignee_id')
        );
    }

    public function test_an_action_with_two_url_params_is_routed_with_both(): void
    {
        $t = $this->ticket();

        $this->postJson("/api/suite-tickets/archive-by-year/{$t->uuid}/2026/create", [])->assertStatus(200);
        $this->postJson('/api/suite-tickets/archive-by-year/00000000-0000-0000-0000-000000000000/2026/create', [])->assertStatus(404);
    }
}
