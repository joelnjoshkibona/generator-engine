<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteDocuments\Services\SuiteDocumentsMarkerProcessor;
use App\Project\Modules\System\Suite\SuiteDocuments\SuiteDocumentsModel;
use App\Project\Modules\System\Suite\SuitePolicies\SuitePoliciesModel;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The declarations on a module that no column expresses, against GENERATED code.
 *
 * Each is a config key the engine's unit tests assert as an emitted string and nothing had ever
 * executed: `json_rules` (a JSON column's shape), string `constants`, a file column, and `processors`
 * on every stage x operation. The generated specs cannot reach them either -- a JSON column gets no
 * form field, so a generated spec never sends it; a processor changes nothing a generated assertion
 * looks at.
 *
 *   suite_policies   json_rules on `params`, STRING constants, drafts off
 *   suite_documents  a `file_media_id` file column, processors on all six stage x operation pairs
 *
 * Acts as the seeded DEVELOPER; neither module has a location column.
 */
class FormMechanismsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
        SuiteDocumentsMarkerProcessor::reset();
    }

    // ─── json_rules ──────────────────────────────────────────────────────────

    private function policy(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/suite-policies/create', [
            'name' => 'Policy ' . uniqid(),
            'state' => SuitePoliciesModel::STATE_ACTIVE,
            'params' => $params,
        ]);
    }

    public function test_a_json_column_that_meets_its_declared_shape_is_saved_as_sent(): void
    {
        $params = ['cap' => 50, 'mode' => 'soft', 'windows' => [['start' => '08:00', 'end' => '17:00']]];

        $response = $this->policy($params)->assertStatus(201);

        $row = SuitePoliciesModel::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertEquals($params, $row->params, 'JSON storage does not preserve key order, so compare by value');
    }

    public function test_a_nested_value_that_breaks_a_rule_is_a_422_naming_the_nested_key(): void
    {
        $this->policy(['cap' => 0])->assertStatus(422)->assertJsonValidationErrors(['params.cap']);
        $this->policy(['cap' => 5000])->assertStatus(422)->assertJsonValidationErrors(['params.cap']);
        $this->policy(['mode' => 'medium'])->assertStatus(422)->assertJsonValidationErrors(['params.mode']);

        // The wildcard reaches every element and names which one failed.
        $this->policy(['windows' => [['start' => '08:00', 'end' => '17:00'], ['start' => '09:00']]])
            ->assertStatus(422)->assertJsonValidationErrors(['params.windows.1.end']);
        $this->policy(['windows' => [['start' => 'morning', 'end' => '17:00']]])
            ->assertStatus(422)->assertJsonValidationErrors(['params.windows.0.start']);
    }

    public function test_a_nested_key_no_rule_names_is_dropped_not_stored(): void
    {
        // Laravel prunes any nested key that has no rule: that is why the config's `sample` must be the
        // COMPLETE accepted shape. Silent loss is the behaviour being pinned, not a defect.
        $response = $this->policy(['cap' => 5, 'secret' => 'should not be stored'])->assertStatus(201);

        $stored = SuitePoliciesModel::where('uuid', $response->json('data.uuid'))->firstOrFail()->params;
        $this->assertSame(5, $stored['cap']);
        $this->assertArrayNotHasKey('secret', $stored);
    }

    public function test_an_absent_json_column_is_valid_because_its_nested_rules_are_sometimes(): void
    {
        // What every generated spec does: it never sends `params`.
        $this->postJson('/api/suite-policies/create', ['name' => 'No params ' . uniqid(), 'state' => SuitePoliciesModel::STATE_ACTIVE])
            ->assertStatus(201);
    }

    public function test_the_same_rules_apply_on_edit(): void
    {
        $policy = SuitePoliciesModel::create(['name' => 'Edit me ' . uniqid(), 'created_by_id' => UsersModel::DEVELOPER])->fresh();

        $this->putJson("/api/suite-policies/{$policy->uuid}/edit", ['name' => $policy->name, 'state' => $policy->state, 'params' => ['cap' => 5000]])
            ->assertStatus(422)->assertJsonValidationErrors(['params.cap']);

        $this->putJson("/api/suite-policies/{$policy->uuid}/edit", ['name' => $policy->name, 'state' => $policy->state, 'params' => ['cap' => 10]])
            ->assertStatus(200);
        $this->assertSame(10, SuitePoliciesModel::find($policy->id)->params['cap']);
    }

    // ─── constants ───────────────────────────────────────────────────────────

    public function test_string_constants_are_emitted_as_strings(): void
    {
        $this->assertSame('ACTIVE', SuitePoliciesModel::STATE_ACTIVE);
        $this->assertSame('PAUSED', SuitePoliciesModel::STATE_PAUSED);
    }

    // ─── file_media_id ───────────────────────────────────────────────────────

    private function document(string $title = 'Doc'): SuiteDocumentsModel
    {
        $response = $this->post('/api/suite-documents/create', [
            'title' => "{$title} " . uniqid(),
            'file_media_id' => UploadedFile::fake()->image('first.png'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        return SuiteDocumentsModel::where('uuid', $response->json('data.uuid'))->firstOrFail();
    }

    public function test_a_file_is_required_on_create_and_becomes_a_media_reference(): void
    {
        $this->post('/api/suite-documents/create', ['title' => 'No file'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file_media_id']);

        $doc = $this->document();
        $this->assertGreaterThan(0, (int) $doc->file_media_id, 'the upload was stored and its id kept');
    }

    public function test_an_edit_without_a_file_keeps_the_stored_one_and_an_edit_with_one_replaces_it(): void
    {
        $doc = $this->document();
        $original = (int) $doc->file_media_id;

        $this->post("/api/suite-documents/{$doc->uuid}/edit", ['_method' => 'PUT', 'title' => 'Renamed'], ['Accept' => 'application/json'])
            ->assertStatus(200);
        $kept = SuiteDocumentsModel::find($doc->id);
        $this->assertSame('Renamed', $kept->title);
        $this->assertSame($original, (int) $kept->file_media_id, 'no file sent: the reference is untouched');

        $this->post("/api/suite-documents/{$doc->uuid}/edit", [
            '_method' => 'PUT', 'title' => 'Renamed', 'file_media_id' => UploadedFile::fake()->image('second.png'),
        ], ['Accept' => 'application/json'])->assertStatus(200);
        $this->assertNotSame($original, (int) SuiteDocumentsModel::find($doc->id)->file_media_id, 'a new file replaces it');
    }

    // ─── processors on every stage x operation ───────────────────────────────

    /** @return list<string> */
    private function stages(): array
    {
        return array_column(SuiteDocumentsMarkerProcessor::$calls, 'stage');
    }

    public function test_create_runs_before_save_without_a_row_and_then_after_save_with_it(): void
    {
        SuiteDocumentsMarkerProcessor::reset();
        $doc = $this->document('Created');

        $this->assertSame(['before_save', 'after_save'], $this->stages());
        [$before, $after] = SuiteDocumentsMarkerProcessor::$calls;
        $this->assertFalse($before['has_model'], 'before_save on a create has no stored row yet');
        $this->assertStringStartsWith('Created', (string) $before['title'], 'it is handed the validated input');
        $this->assertTrue($after['has_model']);
        $this->assertSame($doc->id, $after['model_id']);
        $this->assertSame(['tag' => 'marker'], $before['config'], 'the entry\'s config reaches the processor');
    }

    public function test_edit_runs_before_save_with_the_stored_row_then_after_save(): void
    {
        $doc = $this->document('Original');
        SuiteDocumentsMarkerProcessor::reset();

        $this->post("/api/suite-documents/{$doc->uuid}/edit", ['_method' => 'PUT', 'title' => 'Changed'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $this->assertSame(['before_save', 'after_save'], $this->stages());
        [$before] = SuiteDocumentsMarkerProcessor::$calls;
        $this->assertTrue($before['has_model'], 'on an edit the stored row IS in scope (docs/processors.md says null; the source passes it)');
        $this->assertSame($doc->title, $before['stored_title'], 'it sees the persisted value...');
        $this->assertSame('Changed', $before['title'], '...alongside the submitted one');
    }

    public function test_delete_runs_before_delete_then_after_delete(): void
    {
        $doc = $this->document('Doomed');
        SuiteDocumentsMarkerProcessor::reset();

        $this->deleteJson("/api/suite-documents/{$doc->uuid}/delete")->assertStatus(200);

        $this->assertSame(['before_delete', 'after_delete'], $this->stages());
        $this->assertTrue(SuiteDocumentsMarkerProcessor::$calls[0]['has_model']);
    }
}
