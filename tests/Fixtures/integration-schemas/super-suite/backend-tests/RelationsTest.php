<?php

namespace App\Project\Modules\System\Suite\FixtureTests;

use App\Project\Modules\Core\Users\Users\UsersModel;
use App\Project\Modules\System\Suite\SuiteCustomers\SuiteCustomersModel;
use App\Project\Modules\System\Suite\SuiteOrderLines\SuiteOrderLinesModel;
use App\Project\Modules\System\Suite\SuiteOrders\SuiteOrdersModel;
use App\Project\Modules\System\Suite\SuiteOrderTypes\SuiteOrderTypesModel;
use App\Project\Modules\System\Suite\SuiteSettlements\SuiteSettlementsModel;
use App\Project\Modules\System\Suite\SuiteSuppliers\SuiteSuppliersModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relations between generated modules that a generated spec never crosses.
 *
 *  - inline_items on EDIT: the sync deletes rows missing from the payload, updates rows that carry a
 *    uuid, creates rows that do not -- and must never touch a row that belongs to a DIFFERENT parent.
 *  - `relations.morphMany` on a morph target: the reverse side of a `morphs[]` declaration gets no
 *    relation automatically, so the blueprint declares it.
 *
 * Acts as the seeded DEVELOPER; none of these modules has a location column.
 */
class RelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    // ─── inline_items ────────────────────────────────────────────────────────

    private function order(): SuiteOrdersModel
    {
        return SuiteOrdersModel::create([
            'order_no' => 'ORD-' . strtoupper(substr(uniqid(), -8)),
            'order_type_id' => SuiteOrderTypesModel::query()->value('id'),
            'ordered_on' => now()->format('Y-m-d'),
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
    }

    private function line(SuiteOrdersModel $order, string $description): SuiteOrderLinesModel
    {
        return SuiteOrderLinesModel::create([
            'order_id' => $order->id,
            'description' => $description,
            'line_kind' => 'GOODS',
            'status_key' => 'PENDING',
            'quantity' => 1,
            'line_total' => 1,
            'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
    }

    private function row(string $description, ?string $uuid = null): array
    {
        return array_filter([
            'uuid' => $uuid,
            'description' => $description,
            'line_kind' => 'GOODS',
            'status_key' => 'PENDING',
            'quantity' => 2,
            'line_total' => 3,
        ], fn ($v) => $v !== null);
    }

    private function editOrder(SuiteOrdersModel $order, array $lines)
    {
        return $this->putJson("/api/suite-orders/{$order->uuid}/edit", [
            'order_no' => $order->order_no,
            'order_type_id' => $order->order_type_id,
            'total' => 0,
            'ordered_on' => $order->ordered_on instanceof \DateTimeInterface ? $order->ordered_on->format('Y-m-d') : (string) $order->ordered_on,
            'order_lines' => $lines,
        ]);
    }

    public function test_the_inline_sync_updates_by_uuid_creates_without_one_and_deletes_the_rest(): void
    {
        $order = $this->order();
        $keep = $this->line($order, 'keep');
        $drop = $this->line($order, 'drop');

        $this->editOrder($order, [$this->row('kept, renamed', $keep->uuid), $this->row('brand new')])->assertStatus(200);

        $descriptions = SuiteOrderLinesModel::where('order_id', $order->id)->pluck('description')->sort()->values()->all();
        $this->assertSame(['brand new', 'kept, renamed'], $descriptions);
        $this->assertNull(SuiteOrderLinesModel::find($drop->id), 'a row missing from the payload is deleted');
        $this->assertSame($keep->id, SuiteOrderLinesModel::where('uuid', $keep->uuid)->value('id'), 'a row with a uuid is updated in place, not recreated');
    }

    public function test_an_inline_edit_can_never_adopt_or_overwrite_another_orders_line(): void
    {
        $mine = $this->order();
        $theirs = $this->order();
        $foreign = $this->line($theirs, 'belongs to the other order');

        $this->editOrder($mine, [$this->row('hijacked', $foreign->uuid)])->assertStatus(200);

        $stillTheirs = SuiteOrderLinesModel::find($foreign->id);
        $this->assertNotNull($stillTheirs, 'the other order\'s line survives');
        $this->assertSame($theirs->id, (int) $stillTheirs->order_id, 'it was not re-parented to the order being edited');
        $this->assertSame('belongs to the other order', $stillTheirs->description, 'and its content was not overwritten');
    }

    // ─── morphMany ───────────────────────────────────────────────────────────

    public function test_a_morph_target_reaches_only_its_own_rows_through_the_declared_relation(): void
    {
        $supplier = SuiteSuppliersModel::create(['name' => 'Supplier ' . uniqid(), 'created_by_id' => UsersModel::DEVELOPER])->fresh();
        $customer = SuiteCustomersModel::create(['name' => 'Customer ' . uniqid(), 'created_by_id' => UsersModel::DEVELOPER])->fresh();

        $mine = SuiteSettlementsModel::create([
            'reference' => 'S-' . uniqid(), 'amount' => 10, 'settled_on' => now()->format('Y-m-d'),
            'payable_type' => 'suite_supplier', 'payable_id' => $supplier->id, 'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
        $customersOwn = SuiteSettlementsModel::create([
            'reference' => 'C-' . uniqid(), 'amount' => 20, 'settled_on' => now()->format('Y-m-d'),
            'payable_type' => 'suite_customer', 'payable_id' => $customer->id, 'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();
        // The case a morph TYPE column exists for: a customer-typed row whose payable_id equals the
        // supplier's id. Matching on id alone would hand it to the supplier.
        $theirs = SuiteSettlementsModel::create([
            'reference' => 'X-' . uniqid(), 'amount' => 30, 'settled_on' => now()->format('Y-m-d'),
            'payable_type' => 'suite_customer', 'payable_id' => $supplier->id, 'created_by_id' => UsersModel::DEVELOPER,
        ])->fresh();

        $this->assertSame([$mine->id], $supplier->settlements()->pluck('id')->all());
        $this->assertContains($customersOwn->id, $customer->settlements()->pluck('id')->all());
        $this->assertNotContains($mine->id, $customer->settlements()->pluck('id')->all(), 'a supplier-typed row is not a customer\'s');

        // The morph-filtered delegation agrees with the relation.
        $uuids = array_column($this->getJson("/api/suite-suppliers/{$supplier->uuid}/settlements/list?per_page=100")->assertStatus(200)->json('data.data'), 'uuid');
        $this->assertContains($mine->uuid, $uuids);
        $this->assertNotContains($theirs->uuid, $uuids, 'a customer-typed row with the same payable_id does not leak into the supplier\'s tab');

        // ...and the reverse: the customer's tab holds customer-typed rows only.
        $customerUuids = array_column($this->getJson("/api/suite-customers/{$customer->uuid}/settlements/list?per_page=100")->assertStatus(200)->json('data.data'), 'uuid');
        $this->assertContains($customersOwn->uuid, $customerUuids);
        $this->assertNotContains($mine->uuid, $customerUuids, 'a supplier-typed row is not in a customer\'s tab');
    }
}
