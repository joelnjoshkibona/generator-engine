<?php

/**
 * Part of the "delegations-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * warehouses: the parent module. Its view page embeds `stock_movements`
 * (see that migration) as a related-records sub-tab via the `delegations`
 * config -- a POS/inventory-flavored scenario: viewing a Warehouse shows
 * every stock movement recorded against it, without stock_movements being
 * inline-managed by the Warehouse form the way inline_items would (a
 * delegation is a real, independently-addressable related module rendered
 * as a tab, not child rows synced from the parent's own submit).
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
        Schema::create('warehouses', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('location', 255)->nullable();

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
            $table->index(['uuid'], 'idx_warehouses_uuid');
            $table->index(['deleted_at'], 'idx_warehouses_deleted_at');
            $table->index(['created_by_id'], 'idx_warehouses_created_by_id');
            $table->index(['updated_by_id'], 'idx_warehouses_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['name'], 'idx_warehouses_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
