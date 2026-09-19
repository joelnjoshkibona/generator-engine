<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The child of a delegation whose PARENT is location-bearing. It has no `location_id` of its own:
 * a visit is scoped through its site. The delegation's parent fetch is the only seam that keeps a
 * user from listing another location's visits by putting a foreign site's uuid in the URL, and
 * until this fixture no shipped delegation had a location-bearing parent, so that seam had only
 * ever been asserted as a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_site_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_id')->constrained('suite_sites');
            $table->date('visited_on');
            $table->string('summary', 200)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_site_visits');
    }
};
