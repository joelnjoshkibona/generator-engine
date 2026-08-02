<?php

/**
 * Part of the "morphs-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * suppliers: one of two possible `payable` targets for the polymorphic
 * `payments` table (the other being `customers`) -- a POS-flavored
 * scenario: money paid OUT to a supplier for inventory purchases, and
 * money paid IN by a customer for an order, recorded in one shared
 * Payments ledger instead of two near-duplicate tables.
 *
 * No dependency on any other table in this suite -- morphTo() resolves its
 * target at runtime from the `payable_type` column's stored value, not at
 * generation time, so unlike inline_items/FK-relationship fixtures, the
 * generation order across this suite's three tables does not matter.
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
        Schema::create('suppliers', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('contact_person', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();

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
            $table->index(['uuid'], 'idx_suppliers_uuid');
            $table->index(['deleted_at'], 'idx_suppliers_deleted_at');
            $table->index(['created_by_id'], 'idx_suppliers_created_by_id');
            $table->index(['updated_by_id'], 'idx_suppliers_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['name'], 'idx_suppliers_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
