<?php

/**
 * Part of the "morphs-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * payments: the polymorphic side. `payable_type` + `payable_id` is the
 * exact column-naming convention IntrospectionToConfig::detectMorphPairs()
 * looks for -- a `{prefix}_type` string column paired with a `{prefix}_id`
 * integer column, same prefix. Written out explicitly (not via Laravel's
 * `$table->morphs('payable')` shorthand) to match every other fixture in
 * this suite's style and make the exact trigger columns unambiguous to a
 * reader -- MigrationGenerator collapses them back into a single
 * `$table->morphs('payable');` call when regenerating this migration from
 * the introspected config, so both forms round-trip to the same schema.
 *
 * `amount` is deliberately decimal(14,2), not (10,2) -- MigrationGenerator
 * falls back to (10,2) when precision/scale are absent, so a (10,2)
 * fixture value can't detect a precision-introspection regression (see
 * items-suite's README for the full rationale, first established there).
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
        Schema::create('payments', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('method', 32)->default('cash');
            $table->string('reference_number', 255)->nullable();

            // Polymorphic pair -- triggers morphs detection (prefix: 'payable')
            $table->string('payable_type');
            $table->unsignedBigInteger('payable_id');

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
            $table->index(['uuid'], 'idx_payments_uuid');
            $table->index(['deleted_at'], 'idx_payments_deleted_at');
            $table->index(['created_by_id'], 'idx_payments_created_by_id');
            $table->index(['updated_by_id'], 'idx_payments_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['payment_date'], 'idx_payments_payment_date');
            $table->index(['payable_type', 'payable_id'], 'idx_payments_payable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
