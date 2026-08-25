<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only ledger: scaffolded as **list + view only**, no create/edit/delete.
 *
 * This is the table that catches the worst defect in the v3.4.25 batch. `{Module}DetailsLayout.vue`
 * imported ./Components/{Module}{Edit,Delete}Form.vue unconditionally, and those files are never
 * written for a module without those operations — so the layout imported files that do not exist.
 * `vite dev` never notices (the module is only resolved when someone opens that route, which is why
 * a fully green e2e suite missed it for months); `vite build` resolves every import statically and
 * the entire production bundle fails. An application containing one such module could not be built.
 *
 * The same shape also pins v3.4.21 (a DeleteCheck test emitted for a route that does not exist) and
 * v3.4.22 (a missing-required-field test emitted for a module with no required field — note every
 * column below is either nullable or defaulted, deliberately).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_ledgers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 64)->nullable();
            $table->string('narration', 255)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('posted_on')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_ledgers');
    }
};
