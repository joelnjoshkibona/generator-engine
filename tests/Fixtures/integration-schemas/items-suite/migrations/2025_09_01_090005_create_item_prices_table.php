<?php

/**
 * Part of the "items-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * item_prices: child of items. Exercises decimal + date column types.
 *
 * Depends on: items (item_id). Must already exist -- run this migration
 * AFTER create_items_table.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Project\_Src\Helpers;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_prices', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->foreignId('item_id');
            $table->decimal('price', 10, 2);
            $table->string('currency', 255)->default('USD');
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);

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
            $table->index(['uuid'], 'idx_item_prices_uuid');
            $table->index(['deleted_at'], 'idx_item_prices_deleted_at');
            $table->index(['created_by_id'], 'idx_item_prices_created_by_id');
            $table->index(['updated_by_id'], 'idx_item_prices_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['item_id'], 'idx_item_prices_item_id');
            $table->index(['is_active'], 'idx_item_prices_is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
