<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A location-bearing module: `location_id` is NOT NULL, so every row belongs to exactly one
 * location and is visible only to users assigned to that location or an ancestor of it.
 *
 * Nothing in the blueprint says so -- a `location_id` column IS the declaration
 * (ModuleConfigContract::isLocationBearing), and everything downstream follows from it: the
 * model's `$locationBearing`, the record scope on view/edit/delete/deleteCheck/action fetches, the
 * AccessibleLocation rule on the write, the location filter on the list, the picker's scope, and
 * the delegation parent fetch for `suite_site_visits`.
 *
 * The isolation itself is asserted by backend-tests/LocationScopeIsolationTest.php, which needs a
 * second location this fixture cannot seed: nothing generated can express "a row the caller may
 * not see".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('code', 32)->unique();
            $table->foreignId('location_id')->constrained('locations');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_sites');
    }
};
