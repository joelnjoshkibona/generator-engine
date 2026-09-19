<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Has a `location_id` and OPTS OUT of scoping. `location_bearing: false` (set through the
 * blueprint's module_overrides) is the only way to say "this column records where something
 * happened, it does not restrict who may see it" -- an audit-style column on a shared table.
 * Without the override the column alone would make the module location-bearing, so this is the
 * test that the override wins in that direction; BACKEND's LocationBearingDeclarationTest exists
 * because an undeclared `location_id` used to be silently scoped or silently not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_pings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('label', 120);
            $table->foreignId('location_id')->nullable()->constrained('locations');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_pings');
    }
};
