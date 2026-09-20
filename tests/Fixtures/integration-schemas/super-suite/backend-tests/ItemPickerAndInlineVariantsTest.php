<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteInvoiceLines\SuiteInvoiceLinesModel;
use App\Project\Modules\System\Suite\SuiteInvoiceNotes\SuiteInvoiceNotesModel;
use App\Project\Modules\System\Suite\SuiteInvoices\SuiteInvoicesModel;
use App\Project\Modules\System\Suite\SuiteKits\SuiteKitsModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The picker and the remaining inline-items variants, against GENERATED code.
 *
 *   suite_kits      an item-picker over a JSON column: a `type: custom` splash catalog, `json_rules` naming
 *                   every key of the stored row, numeric `constants` the splash route needs
 *   suite_invoices  two inline entries on one parent; `invoice_lines` is a card variant with
 *                   `inject_from_parent` (currency), `invoice_notes` is a table variant
 *
 * A generated spec cannot reach any of it: a JSON column has no form field, so the picker is invisible to
 * the specs, and the injected column is not an inline field a spec could fill. Acts as the seeded DEVELOPER.
 */
class ItemPickerAndInlineVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    // ─── item-picker ─────────────────────────────────────────────────────────

    private function kit(array $items): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/suite-kits/create', ['name' => 'Kit ' . uniqid(), 'items' => $items]);
    }

    public function test_the_create_and_edit_splash_serve_the_catalog_including_a_name_with_an_apostrophe(): void
    {
        foreach (['/api/suite-kits/create/splash', '/api/suite-kits/edit/splash'] as $url) {
            $catalog = $this->getJson($url)->assertStatus(200)->json('data.catalog');

            $this->assertCount(3, $catalog, $url);
            $this->assertContains("Washer 'M8'", array_column($catalog, 'name'), 'custom splash data is emitted as PHP literals; a quote must survive');
        }
    }

    public function test_the_numeric_constant_the_splash_route_needs_is_emitted(): void
    {
        $this->assertSame(20, SuiteKitsModel::MAX_ITEMS);
    }

    public function test_a_picked_row_is_stored_whole_and_a_bad_one_is_named(): void
    {
        $row = ['id' => 2, 'name' => 'Nut', 'sku' => 'N-1', 'quantity' => 4];

        $response = $this->kit([$row])->assertStatus(201);
        $this->assertEquals([$row], SuiteKitsModel::where('uuid', $response->json('data.uuid'))->firstOrFail()->items);

        $this->kit([['id' => 2, 'name' => 'Nut', 'sku' => 'N-1']])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
        $this->kit([['id' => 2, 'name' => 'Nut', 'sku' => 'N-1', 'quantity' => 0]])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
        $this->kit([$row, ['id' => 'x', 'name' => 'Nut', 'sku' => 'N-1', 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors(['items.1.id']);
    }

    public function test_a_key_no_rule_names_is_pruned_from_a_picked_row(): void
    {
        $response = $this->kit([['id' => 1, 'name' => 'Bolt', 'sku' => 'B-1', 'quantity' => 1, 'secret' => 'x']])->assertStatus(201);

        $stored = SuiteKitsModel::where('uuid', $response->json('data.uuid'))->firstOrFail()->items;
        $this->assertArrayNotHasKey('secret', $stored[0]);
    }

    public function test_a_kit_with_no_items_is_valid_and_edit_applies_the_same_rules(): void
    {
        $this->postJson('/api/suite-kits/create', ['name' => 'Empty ' . uniqid()])->assertStatus(201);

        $kit = SuiteKitsModel::create(['name' => 'Edit me ' . uniqid(), 'created_by_id' => UsersModel::DEVELOPER])->fresh();
        $this->putJson("/api/suite-kits/{$kit->uuid}/edit", ['name' => $kit->name, 'items' => [['id' => 1, 'name' => 'Bolt', 'sku' => 'B-1', 'quantity' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    }

    // ─── inline_items variants ───────────────────────────────────────────────

    private function invoicePayload(string $currency, array $lines = [], array $notes = []): array
    {
        return [
            'invoice_no' => 'INV-' . strtoupper(substr(uniqid(), -8)),
            'currency' => $currency,
            'issued_on' => now()->format('Y-m-d'),
            'invoice_lines' => $lines,
            'invoice_notes' => $notes,
        ];
    }

    public function test_a_child_row_takes_its_parents_currency_and_the_client_cannot_choose_one(): void
    {
        $response = $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [
            ['description' => 'Consulting', 'amount' => 10, 'currency' => 'JPY'],
        ], [['body' => 'Paid in full']]))->assertStatus(201);

        $invoice = SuiteInvoicesModel::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $line = SuiteInvoiceLinesModel::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('EUR', $line->currency, 'inject_from_parent wins over anything the payload says');
        $this->assertSame(1, SuiteInvoiceNotesModel::where('invoice_id', $invoice->id)->count(), 'the second inline entry is saved too');
    }

    public function test_an_inline_field_marked_required_is_validated_per_row(): void
    {
        $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [['amount' => 5]]))
            ->assertStatus(422);
        $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [], [['body' => '']]))
            ->assertStatus(422);
    }

    public function test_an_inline_row_can_only_set_the_columns_it_declares(): void
    {
        // BaseModel is `guarded = []`: what the service passes to create() is all that stands between a
        // client and every column of the child. A row used to go through unvalidated, so its id and
        // timestamps (and, in a real project, a status or approval flag) were the client's to choose.
        $response = $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [
            ['description' => 'Sneaky', 'amount' => 1, 'id' => 987654, 'created_at' => '2001-01-01 00:00:00'],
        ]))->assertStatus(201);

        $invoice = SuiteInvoicesModel::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $line = SuiteInvoiceLinesModel::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertNotSame(987654, (int) $line->id, 'the client does not choose the primary key');
        $this->assertNotSame('2001', $line->created_at->format('Y'), 'nor the timestamps');
    }

    public function test_a_malformed_inline_payload_is_a_422_not_a_500(): void
    {
        $this->postJson('/api/suite-invoices/create', ['invoice_lines' => 'not a list'] + $this->invoicePayload('EUR'))->assertStatus(422);
        $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', ['not a row']))->assertStatus(422);
    }

    public function test_an_edit_syncs_both_entries_and_re_injects_the_parents_current_currency(): void
    {
        $created = $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [
            ['description' => 'Keep', 'amount' => 1],
        ], [['body' => 'first'], ['body' => 'second']]))->assertStatus(201);
        $invoice = SuiteInvoicesModel::where('uuid', $created->json('data.uuid'))->firstOrFail();
        $keepLine = SuiteInvoiceLinesModel::where('invoice_id', $invoice->id)->firstOrFail();
        $keepNote = SuiteInvoiceNotesModel::where('invoice_id', $invoice->id)->where('body', 'first')->firstOrFail();

        $this->putJson("/api/suite-invoices/{$invoice->uuid}/edit", [
            'invoice_no' => $invoice->invoice_no,
            'currency' => 'GBP',
            'issued_on' => now()->format('Y-m-d'),
            'invoice_lines' => [
                ['uuid' => $keepLine->uuid, 'description' => 'Keep, edited', 'amount' => 2],
                ['description' => 'Added', 'amount' => 3],
            ],
            'invoice_notes' => [['uuid' => $keepNote->uuid, 'body' => 'first, edited']],
        ])->assertStatus(200);

        $lines = SuiteInvoiceLinesModel::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertSame(['Keep, edited', 'Added'], $lines->pluck('description')->all());
        $this->assertSame(['GBP', 'GBP'], $lines->pluck('currency')->all(), 'updated AND new rows carry the parent currency as of this edit');
        $this->assertSame(['first, edited'], SuiteInvoiceNotesModel::where('invoice_id', $invoice->id)->pluck('body')->all(), 'the dropped note is gone');
    }

    public function test_neither_inline_entry_can_adopt_another_invoices_row(): void
    {
        $a = SuiteInvoicesModel::where('uuid', $this->postJson('/api/suite-invoices/create', $this->invoicePayload('EUR', [['description' => 'A line', 'amount' => 1]], [['body' => 'A note']]))->json('data.uuid'))->firstOrFail();
        $b = SuiteInvoicesModel::where('uuid', $this->postJson('/api/suite-invoices/create', $this->invoicePayload('USD', [['description' => 'B line', 'amount' => 1]], [['body' => 'B note']]))->json('data.uuid'))->firstOrFail();
        $bLine = SuiteInvoiceLinesModel::where('invoice_id', $b->id)->firstOrFail();
        $bNote = SuiteInvoiceNotesModel::where('invoice_id', $b->id)->firstOrFail();

        $this->putJson("/api/suite-invoices/{$a->uuid}/edit", [
            'invoice_no' => $a->invoice_no,
            'currency' => 'EUR',
            'issued_on' => now()->format('Y-m-d'),
            'invoice_lines' => [['uuid' => $bLine->uuid, 'description' => 'hijacked', 'amount' => 9]],
            'invoice_notes' => [['uuid' => $bNote->uuid, 'body' => 'hijacked']],
        ])->assertStatus(200);

        $this->assertSame($b->id, (int) SuiteInvoiceLinesModel::find($bLine->id)->invoice_id);
        $this->assertSame('B line', SuiteInvoiceLinesModel::find($bLine->id)->description);
        $this->assertSame($b->id, (int) SuiteInvoiceNotesModel::find($bNote->id)->invoice_id);
        $this->assertSame('B note', SuiteInvoiceNotesModel::find($bNote->id)->body);
    }
}
