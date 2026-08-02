<?php

/**
 * Part of the "orders-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * order_items: child of orders (order_id, required). Managed inline from
 * Orders' own create/edit form via the `inline_items` config -- see
 * inline_items_config.php in this same directory -- but scaffolded as its
 * own full module too (same convention as items-suite's ItemImages/
 * ItemPrices), since generateInlineItemsSave()/Sync()/Load() instantiate
 * its real Eloquent model class directly and need it to exist.
 *
 * Depends on: orders (order_id). Must already exist -- run this migration
 * AFTER create_orders_table.
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
        Schema::create('order_items', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->foreignId('order_id');
            $table->string('product_name', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 4);
            $table->decimal('line_total', 12, 2);
            // Populated via inline_items' inject_from_parent (copied from
            // orders.currency at save time), not user-entered when managed
            // inline -- but still a real, independently-editable column when
            // OrderItems is used as its own standalone module.
            $table->string('currency', 3)->default('USD');

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
            $table->index(['uuid'], 'idx_order_items_uuid');
            $table->index(['deleted_at'], 'idx_order_items_deleted_at');
            $table->index(['created_by_id'], 'idx_order_items_created_by_id');
            $table->index(['updated_by_id'], 'idx_order_items_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['order_id'], 'idx_order_items_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
