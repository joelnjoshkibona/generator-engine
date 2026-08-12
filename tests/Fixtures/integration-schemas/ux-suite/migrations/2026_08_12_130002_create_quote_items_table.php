<?php

/**
 * Part of the "ux-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * quote_items: an ordinary, independently-scaffolded module -- quote_id is
 * just a normal FK, detected the same way any other FK is. Used as the
 * composite's "repeater" section and the wizard's "repeater" step; nothing
 * in the schema marks this relationship as special (unlike inline_items).
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
        Schema::create('quote_items', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->foreignId('quote_id');
            $table->string('product_name', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 4);

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
            $table->index(['uuid'], 'idx_quote_items_uuid');
            $table->index(['deleted_at'], 'idx_quote_items_deleted_at');
            $table->index(['created_by_id'], 'idx_quote_items_created_by_id');
            $table->index(['updated_by_id'], 'idx_quote_items_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['quote_id'], 'idx_quote_items_quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
