<?php

/**
 * Part of the "items-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * item_types: lookup table (no FKs). Modeled on SYSTEM_SHELL's real
 * LocationTypes migration convention -- see
 * app/Project/Modules/Core/Locations/LocationTypes/Migrations/..._create_location_types_table.php
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
        Schema::create('item_types', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('code', 255)->unique();
            $table->text('description')->nullable();

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
            $table->index(['uuid'], 'idx_item_types_uuid');
            $table->index(['deleted_at'], 'idx_item_types_deleted_at');
            $table->index(['created_by_id'], 'idx_item_types_created_by_id');
            $table->index(['updated_by_id'], 'idx_item_types_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['code'], 'idx_item_types_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_types');
    }
};
