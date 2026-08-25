<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inline child rows carrying THREE differently-backed selects at once — the point of this table.
 *
 *   line_kind      a `select` with a literal `options` array, and no related model whatsoever
 *   status_key     a `select` resolved through a `splash_key`, likewise not a relation
 *   order_type_id  a real FK, which IS a relation
 *
 * Until v3.4.25 `extractInlineItemFkFields()` keyed off `type === 'select'` alone: it stripped a
 * trailing `_id`, camelCased whatever was left into a relation name and handed it to ::with(), so
 * the first two 500'd the parent's View endpoint with "Call to undefined relationship". The
 * downstream workaround was to redeclare such a field as a plain `input`, losing the dropdown.
 *
 * Keeping all three side by side is deliberate: the fix must skip two of them and still eager-load
 * the third. A fixture with only the broken shape would pass a generator that skipped ALL selects.
 *
 * `line_total` is `decimal(10,4)` — narrow enough that the generator's default 7-digit numeric fill
 * overflows it, which is how the `constrainNumericExpr()` path gets exercised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('suite_orders')->cascadeOnDelete();
            $table->foreignId('order_type_id')->nullable()->constrained('suite_order_types');
            $table->string('line_kind', 24)->default('GOODS');
            $table->string('status_key', 24)->default('PENDING');
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('line_total', 10, 4)->default(0);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_order_lines');
    }
};
