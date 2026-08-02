<?php

/**
 * Part of the "actions-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * purchase_orders: exercises BOTH of this engine's custom-action mechanisms
 * in one module (a POS/inventory-flavored scenario -- a purchase order
 * sent to a supplier moves draft -> approved -> received/cancelled):
 *
 * - A custom `actions` entry ("approve": draft -> approved), which needs
 *   real hand-filled process() logic -- the generator only scaffolds the
 *   route/controller/service wrapper, not the transition itself.
 * - A generic `bulk_actions` entry ("archive", no `status_target`) on the
 *   list feature.
 *
 * Deliberately does NOT use `bulk_actions[].status_target` -- that
 * mechanism hardcodes `$model->update(['status_id' => ...])`, i.e. it
 * assumes an INTEGER `status_id` FK-style column against a real Statuses
 * lookup table (this project's convention elsewhere), not the plain string
 * `status` column used here. Kept out of this fixture to stay
 * self-contained (no dependency on a seeded Statuses table with known
 * numeric ids); the exact status_id + `constants` config combination that
 * *would* be needed is documented in docs/examples/actions.md instead.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Project\_Src\Helpers;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('po_number', 255)->unique();
            $table->string('supplier_name', 255);
            $table->string('status', 32)->default('draft');
            $table->decimal('total_amount', 14, 2);

            // Audit fields
            $table->unsignedBigInteger('created_by_id');
            $table->unsignedBigInteger('updated_by_id')->nullable();

            // Timestamps BEFORE softDeletes
            $table->timestamps();

            // Soft deletes BEFORE UUID
            $table->softDeletes();

            // UUID AFTER timestamps and softDeletes
            $table->uuid()->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();

            // Indexes
            // REQUIRED INDEXES
            $table->index(['uuid'], 'idx_purchase_orders_uuid');
            $table->index(['deleted_at'], 'idx_purchase_orders_deleted_at');
            $table->index(['created_by_id'], 'idx_purchase_orders_created_by_id');
            $table->index(['updated_by_id'], 'idx_purchase_orders_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['status'], 'idx_purchase_orders_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
