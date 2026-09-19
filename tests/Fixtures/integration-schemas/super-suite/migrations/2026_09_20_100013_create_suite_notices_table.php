<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A location-bearing module whose `location_id` is NULLABLE: a NULL row is a company-wide notice,
 * visible everywhere. A nullable location column makes ListServiceGenerator emit
 * `$locationScopeIncludesNull = true`, and the record scope honours the same flag -- so the list
 * and a by-uuid fetch must agree. `suite_sites` is the negative control (NOT NULL, no such rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_notices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_notices');
    }
};
