<?php

/**
 * Part of the "items-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * items: main entity, with two required (non-nullable) FKs.
 *
 * Depends on: item_types (item_type_id), item_categories (item_category_id).
 * Both must already exist -- run this migration AFTER
 * create_item_types_table and create_item_categories_table.
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
        Schema::create('items', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('sku', 255)->unique();
            $table->text('description')->nullable();
            $table->foreignId('item_type_id');
            $table->foreignId('item_category_id');
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
            $table->index(['uuid'], 'idx_items_uuid');
            $table->index(['deleted_at'], 'idx_items_deleted_at');
            $table->index(['created_by_id'], 'idx_items_created_by_id');
            $table->index(['updated_by_id'], 'idx_items_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['sku'], 'idx_items_sku');
            $table->index(['item_type_id'], 'idx_items_item_type_id');
            $table->index(['item_category_id'], 'idx_items_item_category_id');
            $table->index(['is_active'], 'idx_items_is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
