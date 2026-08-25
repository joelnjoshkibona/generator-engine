<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inline-items parent, and the home of the `*_by_id` business-column trap.
 *
 * `approved_by_id` is a genuine foreign key to `users`, NOT an audit column. `inferFkByConvention()`
 * used to treat every `*_by_id` name as audit metadata and skip it, so a real business relation
 * silently lost its relationship, its picker and its resolved list cell. It sits here alongside the
 * real audit columns (`created_by_id`, `updated_by_id`) precisely so the two cannot be told apart by
 * name alone — which is the whole difficulty.
 *
 * `order_type_id` is an ordinary many-to-one FK whose target has a `name` column, so it is the
 * POSITIVE control for a filterable FK: it is the one FK in this fixture that a filter step should
 * accept without complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_no', 32)->unique();
            $table->foreignId('order_type_id')->constrained('suite_order_types');
            $table->unsignedBigInteger('approved_by_id')->nullable()->index();
            $table->decimal('total', 15, 2)->default(0);
            $table->date('ordered_on');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_orders');
    }
};
