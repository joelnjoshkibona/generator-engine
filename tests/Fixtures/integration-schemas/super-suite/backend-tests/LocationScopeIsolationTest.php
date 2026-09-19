<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Locations\Locations\LocationsModel;
use App\Project\Modules\Core\Users\UserLocations\UserLocationsModel;
use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteNotices\SuiteNoticesModel;
use App\Project\Modules\System\Suite\SuitePings\SuitePingsModel;
use App\Project\Modules\System\Suite\SuiteSites\SuiteSitesModel;
use App\Project\Modules\System\Suite\SuiteSiteVisits\SuiteSiteVisitsModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Location scoping, end to end, against GENERATED modules and two locations.
 *
 * Every other check of it is either a string assertion on emitted code (the engine's unit tests) or
 * runs against hand-written models (BACKEND/tests/Feature/Locations). Nothing generated can express
 * "a row the caller may not see", which is why this file is hand-written: the runner copies it into
 * the Suite group after generation and removes it with the group.
 *
 * The acting user is the seeded DEVELOPER -- there is no developer bypass. Their reach is their
 * assigned location plus its descendants; a second root location created here is out of it.
 *
 *   suite_sites        location_id NOT NULL     scoped
 *   suite_notices      location_id NULLABLE     scoped, NULL is visible everywhere
 *   suite_site_visits  no location_id           scoped through its parent site (a delegation)
 *   suite_pings        location_id + location_bearing:false   NOT scoped
 */
class LocationScopeIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    private function devLocationId(): int
    {
        return (int) UserLocationsModel::where('user_id', UsersModel::DEVELOPER)->value('location_id');
    }

    private function foreignLocationId(): int
    {
        return LocationsModel::factory()->create()->id;
    }

    private function site(int $locationId, array $overrides = []): SuiteSitesModel
    {
        return SuiteSitesModel::create(array_merge([
            'name' => 'Site ' . uniqid(),
            'code' => 'S' . substr(uniqid(), -10),
            'location_id' => $locationId,
            'created_by_id' => UsersModel::DEVELOPER,
        ], $overrides))->fresh();
    }

    private function notice(?int $locationId): SuiteNoticesModel
    {
        return SuiteNoticesModel::create([
            'title' => 'Notice ' . uniqid(),
            'location_id' => $locationId,
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
    }

    private function visit(SuiteSitesModel $site): SuiteSiteVisitsModel
    {
        return SuiteSiteVisitsModel::create([
            'site_id' => $site->id,
            'visited_on' => now()->format('Y-m-d'),
            'summary' => 'Visit ' . uniqid(),
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
    }

    /** @return list<string> */
    private function listedUuids(string $url): array
    {
        $response = $this->getJson($url)->assertStatus(200);

        return array_column($response->json('data.data') ?? [], 'uuid');
    }

    // ─── Lists ───────────────────────────────────────────────────────────────

    public function test_the_list_shows_only_rows_in_the_callers_locations(): void
    {
        $in = $this->site($this->devLocationId());
        $foreign = $this->site($this->foreignLocationId());

        $uuids = $this->listedUuids('/api/suite-sites/list?per_page=100');

        $this->assertContains($in->uuid, $uuids);
        $this->assertNotContains($foreign->uuid, $uuids);
    }

    public function test_a_descendant_locations_rows_are_in_scope(): void
    {
        $child = LocationsModel::factory()->create(['parent_id' => $this->devLocationId()]);
        $atChild = $this->site($child->id);

        $this->assertContains($atChild->uuid, $this->listedUuids('/api/suite-sites/list?per_page=100'));
        $this->getJson("/api/suite-sites/{$atChild->uuid}/view")->assertStatus(200);
    }

    // ─── Single-record endpoints ─────────────────────────────────────────────

    public function test_every_single_record_endpoint_refuses_a_foreign_row(): void
    {
        $foreign = $this->site($this->foreignLocationId());
        $in = $this->site($this->devLocationId());

        $this->getJson("/api/suite-sites/{$in->uuid}/view")->assertStatus(200);

        $this->getJson("/api/suite-sites/{$foreign->uuid}/view")->assertStatus(404);
        $this->getJson("/api/suite-sites/{$foreign->uuid}/delete/check")->assertStatus(404);
        $this->getJson("/api/suite-sites/{$foreign->uuid}/activity")->assertStatus(404);

        // edit/delete answer 422, not 404: the uuid's own `exists:` rule already proved the row is
        // there, so "not found" could only mean "exists but out of scope" -- 422 matches a missing
        // uuid and does not leak that difference.
        $this->putJson("/api/suite-sites/{$foreign->uuid}/edit", [
            'name' => 'Renamed', 'code' => $foreign->code, 'location_id' => $foreign->location_id,
        ])->assertStatus(422);
        $this->deleteJson("/api/suite-sites/{$foreign->uuid}/delete")->assertStatus(422);

        $this->assertSame($foreign->name, SuiteSitesModel::find($foreign->id)->name, 'the refused edit changed nothing');
        $this->assertNotSoftDeleted('suite_sites', ['uuid' => $foreign->uuid]);
    }

    // ─── Writes ──────────────────────────────────────────────────────────────

    public function test_a_write_naming_a_foreign_location_is_rejected_on_the_location_field(): void
    {
        $foreignId = $this->foreignLocationId();

        $this->postJson('/api/suite-sites/create', [
            'name' => 'Rejected', 'code' => 'R' . substr(uniqid(), -10), 'location_id' => $foreignId,
        ])->assertStatus(422)->assertJsonValidationErrors(['location_id']);

        $this->assertDatabaseMissing('suite_sites', ['name' => 'Rejected']);

        $this->postJson('/api/suite-sites/create', [
            'name' => 'Accepted', 'code' => 'A' . substr(uniqid(), -10), 'location_id' => $this->devLocationId(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('suite_sites', ['name' => 'Accepted']);
    }

    public function test_moving_a_row_to_a_foreign_location_is_rejected(): void
    {
        $in = $this->site($this->devLocationId());

        $this->putJson("/api/suite-sites/{$in->uuid}/edit", [
            'name' => $in->name, 'code' => $in->code, 'location_id' => $this->foreignLocationId(),
        ])->assertStatus(422)->assertJsonValidationErrors(['location_id']);

        $this->assertSame($this->devLocationId(), (int) SuiteSitesModel::find($in->id)->location_id);
    }

    // ─── Actions ─────────────────────────────────────────────────────────────

    public function test_an_action_on_a_foreign_row_is_refused(): void
    {
        $foreign = $this->site($this->foreignLocationId());
        $in = $this->site($this->devLocationId());

        $this->postJson("/api/suite-sites/check-in/{$in->uuid}/create", [])->assertSuccessful();
        $this->assertContains(
            $this->postJson("/api/suite-sites/check-in/{$foreign->uuid}/create", [])->status(),
            [404, 422],
            'an action must not run against a record the caller cannot see'
        );
    }

    // ─── Delegation whose parent is location-bearing ─────────────────────────

    public function test_a_delegation_refuses_a_foreign_parent_and_never_mixes_parents(): void
    {
        $in = $this->site($this->devLocationId());
        $foreign = $this->site($this->foreignLocationId());
        $visitIn = $this->visit($in);
        $visitForeign = $this->visit($foreign);

        $this->getJson("/api/suite-sites/{$foreign->uuid}/site-visits/list")->assertStatus(404);
        $this->postJson("/api/suite-sites/{$foreign->uuid}/site-visits/create", [
            'visited_on' => now()->format('Y-m-d'),
        ])->assertStatus(404);

        $uuids = $this->listedUuids("/api/suite-sites/{$in->uuid}/site-visits/list?per_page=100");
        $this->assertContains($visitIn->uuid, $uuids);
        $this->assertNotContains($visitForeign->uuid, $uuids);

        // A visit uuid from another parent, addressed through an in-scope parent, is not found.
        $this->getJson("/api/suite-sites/{$in->uuid}/site-visits/{$visitForeign->uuid}/view")->assertStatus(404);
    }

    // ─── Pickers ─────────────────────────────────────────────────────────────

    public function test_the_picker_offers_only_rows_in_scope(): void
    {
        $in = $this->site($this->devLocationId());
        $foreign = $this->site($this->foreignLocationId());

        $response = $this->getJson('/api/select/SuiteSites?per_page=100&label=name')->assertStatus(200);
        $ids = array_column($response->json('data.data'), 'id');

        $this->assertContains($in->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    // ─── A NULLABLE location column: NULL means "visible everywhere" ──────────

    public function test_a_null_location_row_is_visible_and_a_foreign_one_is_not(): void
    {
        $everywhere = $this->notice(null);
        $mine = $this->notice($this->devLocationId());
        $foreign = $this->notice($this->foreignLocationId());

        $uuids = $this->listedUuids('/api/suite-notices/list?per_page=100');
        $this->assertContains($everywhere->uuid, $uuids);
        $this->assertContains($mine->uuid, $uuids);
        $this->assertNotContains($foreign->uuid, $uuids);

        // A by-uuid fetch must agree with the list about NULL.
        $this->getJson("/api/suite-notices/{$everywhere->uuid}/view")->assertStatus(200);
        $this->getJson("/api/suite-notices/{$foreign->uuid}/view")->assertStatus(404);
    }

    // ─── location_bearing:false beats the column ─────────────────────────────

    public function test_a_module_that_opts_out_is_not_scoped_despite_its_location_column(): void
    {
        $ping = SuitePingsModel::create([
            'label' => 'Ping ' . uniqid(),
            'location_id' => $this->foreignLocationId(),
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();

        $this->getJson("/api/suite-pings/{$ping->uuid}/view")->assertStatus(200);
        $this->assertContains($ping->uuid, $this->listedUuids('/api/suite-pings/list?per_page=100'));
    }

    // ─── The location switcher's contract (X-Location-Id, location_filter) ───

    public function test_location_filter_narrows_a_list_to_one_location_and_optionally_its_descendants(): void
    {
        $dev = $this->devLocationId();
        $child = LocationsModel::factory()->create(['parent_id' => $dev]);
        $atDev = $this->site($dev);
        $atChild = $this->site($child->id);

        $both = $this->listedUuids("/api/suite-sites/list?per_page=100&location_filter={$dev}");
        $this->assertContains($atDev->uuid, $both);
        $this->assertContains($atChild->uuid, $both, 'descendants are included by default');

        $only = $this->listedUuids("/api/suite-sites/list?per_page=100&location_filter={$dev}&include_descendants=0");
        $this->assertContains($atDev->uuid, $only);
        $this->assertNotContains($atChild->uuid, $only);

        $childOnly = $this->listedUuids("/api/suite-sites/list?per_page=100&location_filter={$child->id}");
        $this->assertContains($atChild->uuid, $childOnly);
        $this->assertNotContains($atDev->uuid, $childOnly);
    }

    public function test_the_permission_gate_resolves_at_the_header_location_and_refuses_one_the_caller_cannot_reach(): void
    {
        $this->withHeaders(['X-Location-Id' => (string) $this->devLocationId()])
            ->getJson('/api/suite-sites/list')->assertStatus(200);

        $this->withHeaders(['X-Location-Id' => (string) $this->foreignLocationId()])
            ->getJson('/api/suite-sites/list')->assertStatus(403);
    }
}
