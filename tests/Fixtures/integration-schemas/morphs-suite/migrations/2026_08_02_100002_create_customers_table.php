<?php

/**
 * Part of the "morphs-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * customers: the second of two possible `payable` targets for the
 * polymorphic `payments` table (the other being `suppliers`).
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
        Schema::create('customers', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('name', 255);
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();

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
            $table->index(['uuid'], 'idx_customers_uuid');
            $table->index(['deleted_at'], 'idx_customers_deleted_at');
            $table->index(['created_by_id'], 'idx_customers_created_by_id');
            $table->index(['updated_by_id'], 'idx_customers_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['name'], 'idx_customers_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
