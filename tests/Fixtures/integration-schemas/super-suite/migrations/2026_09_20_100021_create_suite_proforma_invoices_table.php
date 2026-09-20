<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module a CREATE WIZARD with an INLINE-ITEMS STEP hangs off.
 *
 * `features.frontend.create.wizard` partitions a form into steps, and a step may name an `inline_items` key
 * in its `field_keys` -- the items table is then a wizard step of its own, ahead of the Review & Confirm
 * step (which summarises it as "N item(s)"). Nothing else in the suite combines the two: `suite_tickets` is a
 * wizard with plain fields only, and `suite_orders`/`suite_invoices` have inline items on a flat form.
 *
 *  - `total` is kept equal to the sum of the rows' `line_total` by the form (`totals[].sync_to`), so it sits in
 *    the items step beside the table: a required field named by no step is never rendered and the create 422s.
 *  - The child has no soft deletes: an edit that drops a row deletes it for real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('proforma_no', 32)->unique();
            $table->string('customer_name', 160);
            $table->string('currency', 3)->default('USD');
            $table->date('valid_until');
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suite_proforma_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('proforma_id')->constrained('suite_proforma_invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_proforma_invoice_items');
        Schema::dropIfExists('suite_proforma_invoices');
    }
};
