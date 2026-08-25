<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A polymorphic owner, plus the two morph targets it can point at.
 *
 * `payable_type` / `payable_id` are auto-detected as a morph. What this pins beyond detection is the
 * REVERSE relation and the morph-FILTERED delegation: a target module gets a real reverse relation
 * and a delegation tab listing only the settlements that point at it, which is config-driven rather
 * than spliced into a generated file (v3.x).
 *
 * Two targets, not one: with a single target a morph is indistinguishable from an ordinary FK, and
 * the filtering has nothing to filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suite_customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suite_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 64)->unique();
            $table->decimal('amount', 15, 2);
            $table->string('payable_type', 191)->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();
            $table->date('settled_on');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payable_type', 'payable_id'], 'suite_settlements_payable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_settlements');
        Schema::dropIfExists('suite_customers');
        Schema::dropIfExists('suite_suppliers');
    }
};
