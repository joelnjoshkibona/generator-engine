<?php

/**
 * Part of the "orders-suite" reusable integration-test schema fixture.
 * See ../README.md for the full scenario and usage instructions.
 *
 * orders: parent entity, displayed by `order_number` (deliberately NOT
 * `name` -- see README.md for why).
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
        Schema::create('orders', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Business fields
            $table->string('order_number', 255)->unique();
            $table->string('customer_name', 255);
            $table->enum('status', ['pending', 'paid', 'shipped', 'cancelled'])->default('pending');
            $table->string('currency', 3)->default('USD');
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
            $table->index(['uuid'], 'idx_orders_uuid');
            $table->index(['deleted_at'], 'idx_orders_deleted_at');
            $table->index(['created_by_id'], 'idx_orders_created_by_id');
            $table->index(['updated_by_id'], 'idx_orders_updated_by_id');

            // BUSINESS FIELD INDEXES
            $table->index(['order_number'], 'idx_orders_order_number');
            $table->index(['status'], 'idx_orders_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
