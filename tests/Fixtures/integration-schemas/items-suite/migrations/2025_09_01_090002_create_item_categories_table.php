<?php

/**
 * Part of the "items-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * item_categories: self-referential FK (parent_id -> item_categories.id),
 * nullable. Modeled on SYSTEM_SHELL's real Locations migration's parent_id
 * convention -- see
 * app/Project/Modules/Core/Locations/Locations/Migrations/..._create_locations_table.php
 *
 * Depends on: none (parent_id is self-referential; the table is created
 * before any row referencing itself is inserted).
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
        Schema::create('item_categories', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('code', 255)->unique();
            $table->foreignId('parent_id')->nullable();

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
            $table->index(['uuid'], 'idx_item_categories_uuid');
            $table->index(['deleted_at'], 'idx_item_categories_deleted_at');
            $table->index(['created_by_id'], 'idx_item_categories_created_by_id');
            $table->index(['updated_by_id'], 'idx_item_categories_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['code'], 'idx_item_categories_code');
            $table->index(['parent_id'], 'idx_item_categories_parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
