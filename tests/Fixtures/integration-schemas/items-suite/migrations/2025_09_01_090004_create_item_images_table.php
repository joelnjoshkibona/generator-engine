<?php

/**
 * Part of the "items-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * item_images: child of items. image_path is the file-upload column --
 * pair with `--file-columns=image_path` when running make:module for this
 * table (see README.md).
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
        Schema::create('item_images', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->foreignId('item_id');
            $table->string('image_path', 255);
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);

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
            $table->index(['uuid'], 'idx_item_images_uuid');
            $table->index(['deleted_at'], 'idx_item_images_deleted_at');
            $table->index(['created_by_id'], 'idx_item_images_created_by_id');
            $table->index(['updated_by_id'], 'idx_item_images_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['item_id'], 'idx_item_images_item_id');
            $table->index(['is_primary'], 'idx_item_images_is_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_images');
    }
};
