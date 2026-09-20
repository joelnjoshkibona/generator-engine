<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteProformaInvoiceItems\SuiteProformaInvoiceItemsModel;
use App\Project\Modules\System\Suite\SuiteProformaInvoices\SuiteProformaInvoicesModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The wizard's payload, as the API receives it.
 *
 * The create wizard is UI: it collects `proforma_no`, the customer, the items table and the notes across
 * steps and submits ONE request. The browser spec (proforma-wizard.e2e.js) drives the steps; this pins
 * what that single request must do on the server -- the parent and every item land together, and a bad
 * item rejects the whole thing (the transaction leaves no half-created proforma behind).
 */
class ProformaInvoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'proforma_no' => 'PF-' . strtoupper(substr(uniqid(), -8)),
            'customer_name' => 'Acme Ltd',
            'currency' => 'EUR',
            'valid_until' => now()->addDays(30)->format('Y-m-d'),
            'total' => 0,
            'proforma_items' => $items,
        ], $overrides);
    }

    public function test_the_parent_and_every_item_are_created_by_one_request(): void
    {
        $response = $this->postJson('/api/suite-proforma-invoices/create', $this->payload([
            ['description' => 'Widget', 'quantity' => 2, 'unit_price' => 5, 'line_total' => 10],
            ['description' => 'Gadget', 'quantity' => 1, 'unit_price' => 7.5, 'line_total' => 7.5],
        ], ['total' => 17.5, 'notes' => 'Net 30']))->assertStatus(201);

        $proforma = SuiteProformaInvoicesModel::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $items = SuiteProformaInvoiceItemsModel::where('proforma_id', $proforma->id)->orderBy('id')->get();

        $this->assertSame(['Widget', 'Gadget'], $items->pluck('description')->all());
        $this->assertSame([2, 1], $items->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertEquals(17.5, (float) $proforma->total, 'the total is what the form sent: the server does not recompute it');
        $this->assertSame((int) UsersModel::DEVELOPER, (int) $items[0]->created_by_id);
    }

    public function test_a_bad_item_rejects_the_whole_proforma_and_leaves_nothing_behind(): void
    {
        $payload = $this->payload([
            ['description' => 'Fine', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1],
            ['description' => 'No quantity', 'unit_price' => 1, 'line_total' => 1],
        ]);

        $this->postJson('/api/suite-proforma-invoices/create', $payload)->assertStatus(422);

        $this->assertDatabaseMissing('suite_proforma_invoices', ['proforma_no' => $payload['proforma_no']]);
        $this->assertSame(0, SuiteProformaInvoiceItemsModel::where('description', 'Fine')->count());
    }

    public function test_a_proforma_with_no_items_is_valid(): void
    {
        // What the generated create spec submits: it walks the wizard without touching the items step.
        $this->postJson('/api/suite-proforma-invoices/create', $this->payload([]))->assertStatus(201);
    }
}
