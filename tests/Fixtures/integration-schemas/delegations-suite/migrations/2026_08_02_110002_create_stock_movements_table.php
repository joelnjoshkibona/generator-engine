<?php

/**
 * Part of the "delegations-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * stock_movements: an ordinary, independently-scaffolded module (own
 * routes, controller, CRUD services, delete form -- exactly like any other
 * module in this project). It becomes a "related records" tab on the
 * Warehouse view page purely via Warehouses' `delegations` config -- this
 * migration itself has nothing special in it, unlike inline_items'
 * parent_fk column, which is otherwise unremarkable too but is
 * hand-declared as such in that fixture's config. Here, `warehouse_id` is
 * just an ordinary FK column, detected the same naming-convention way any
 * other FK is.
 *
 * Depends on: warehouses (warehouse_id). Must already exist -- run this
 * migration AFTER create_warehouses_table.
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
        Schema::create('stock_movements', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->foreignId('warehouse_id');
            $table->string('item_name', 255);
            $table->integer('quantity');
            $table->string('movement_type', 16)->default('in');

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
            $table->index(['uuid'], 'idx_stock_movements_uuid');
            $table->index(['deleted_at'], 'idx_stock_movements_deleted_at');
            $table->index(['created_by_id'], 'idx_stock_movements_created_by_id');
            $table->index(['updated_by_id'], 'idx_stock_movements_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['warehouse_id'], 'idx_stock_movements_warehouse_id');
            $table->index(['movement_type'], 'idx_stock_movements_movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
