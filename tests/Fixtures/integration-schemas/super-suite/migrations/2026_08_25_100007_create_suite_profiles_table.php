<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A true 1:1 relation, plus a field whose real constraint is not in its rules.
 *
 *  owner_id       a required FK whose OWN column is UNIQUE. An ordinary many-to-one FK can be
 *                 filled with `option[0]` forever — any number of rows may reference the same
 *                 related record. A unique one may be referenced at most ONCE, so option[0] is
 *                 free only on the very first run against a given database and every run after
 *                 that 422s against the column's own unique index (v3.4.23). Only a unique FK
 *                 reaches that path, which is why an ordinary FK here would delete the coverage.
 *
 *  contact_phone  `nullable|string|max:255` is everything the rules say, while a
 *                 `processing_service` normalizer rejects anything that is not a parseable phone
 *                 number. Nothing derivable from the schema can satisfy it — this is the column
 *                 that justifies `sample_value` existing at all, and the negative control for the
 *                 rule-derived paths (`in:`, defaults, `enum_values`) that handle everything else.
 *
 *  short_code     `regex:/^[A-Za-z0-9]+$/` with a tight max. The generator's default bounded-string
 *                 literal contains SPACES, so it fails the regex — derivable in principle, and a
 *                 standing reminder that `sample_value` is not the only answer here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->unique()->constrained('suite_order_types');
            $table->string('contact_phone', 255)->nullable();
            $table->string('short_code', 10)->nullable()->unique();
            $table->string('region', 120)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_profiles');
    }
};
