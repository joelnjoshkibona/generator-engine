<?php

/**
 * Part of the "ux-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * quotes: parent entity for the UX Builder scenario -- the module.json-driven
 * scaffold this suite's README requires to run FIRST, before the UX
 * blueprint. Deliberately plain (no inline_items/delegations/morphs/actions
 * of its own) so this suite tests the UX Builder pathway in isolation from
 * the other 5 suites' mechanisms.
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
        Schema::create('quotes', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('quote_number', 255)->unique();
            $table->string('customer_name', 255);
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined'])->default('draft');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();

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
            $table->index(['uuid'], 'idx_quotes_uuid');
            $table->index(['deleted_at'], 'idx_quotes_deleted_at');
            $table->index(['created_by_id'], 'idx_quotes_created_by_id');
            $table->index(['updated_by_id'], 'idx_quotes_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['quote_number'], 'idx_quotes_quote_number');
            $table->index(['status'], 'idx_quotes_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
