<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining `inline_items` variants, beside the `suite_orders` -> `suite_order_lines` pair (a `table`
 * variant with `totals`/`sync_to` and three kinds of select):
 *
 *  - `invoice_lines` is the default CARD variant, has `can_delete: false`, and copies the parent's
 *    `currency` onto every child row with `inject_from_parent` -- `currency` is NOT one of the inline
 *    fields, so a client cannot choose it.
 *  - `invoice_notes` is a second inline entry on the same parent, so the Create/Edit services carry two
 *    extract/sync blocks side by side.
 *
 * Neither child has soft deletes: an inline sync that drops a row deletes it for real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_no', 32)->unique();
            $table->string('currency', 3)->default('USD');
            $table->date('issued_on');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suite_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('suite_invoices')->cascadeOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->string('description', 255);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('suite_invoice_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('suite_invoices')->cascadeOnDelete();
            $table->string('body', 255);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_invoice_notes');
        Schema::dropIfExists('suite_invoice_lines');
        Schema::dropIfExists('suite_invoices');
    }
};
