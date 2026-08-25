<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Column-type edge cases, each one a shipped bug.
 *
 *  long_label      varchar(64). A generated CRUD spec could never pass on a bounded string column
 *                  longer than 39 characters — the fill was clamped in one place and asserted
 *                  unclamped in another. Anything <= 39 misses it entirely.
 *
 *  tier + currency an `enum` inside a COMPOSITE unique. A single-column unique and a plain enum are
 *                  each handled by other code paths; only the combination made every generated
 *                  fixture call die on `Data truncated for column`.
 *
 *  price           decimal(10,4) — six integer digits, max 999999.9999. Deliberately NOT (10,2),
 *                  which is `MigrationGenerator`'s own fallback and therefore matches correct output
 *                  by coincidence even when precision is never introspected. The default numeric
 *                  fill is seven digits, so this column overflows unless `constrainNumericExpr()`
 *                  clamps it — SQLSTATE 22003 in a real project.
 *
 *  internal_ref    scaffolded with `defaultVisible: false`. A generated edit step could pick a
 *                  column hidden from the list and then time out waiting to read its value out of a
 *                  <td> that is never rendered.
 *
 *  This table deliberately has NO `name` column: it is the negative control for a filterable FK,
 *  whose list cell can only resolve through one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_edge_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('long_label', 64);
            $table->enum('tier', ['BRONZE', 'SILVER', 'GOLD'])->default('BRONZE');
            $table->string('currency', 3)->default('TZS');
            $table->decimal('price', 10, 4)->default(0);
            $table->string('internal_ref', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tier', 'currency'], 'suite_edge_cases_tier_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_edge_cases');
    }
};
