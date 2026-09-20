<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module the FORM-SIDE declarations that no column expresses hang off:
 *
 *  - `params` is a nullable `json` column with `json_rules` (blueprint `module_overrides`): a JSON column
 *    is validated only as `array` unless its shape is declared, and Laravel prunes any nested key that no
 *    rule names. It is nullable on purpose -- introspection gives a JSON column NO form field, so the
 *    generated create/edit specs never send it, and a required nested rule must therefore be
 *    `sometimes|required|...`.
 *  - `state` carries STRING `constants` (`STATE_ACTIVE = 'ACTIVE'`); every other constant in the suite
 *    is numeric, and the emitter quotes the two differently.
 *  - `drafts: false` on create and edit, the one module whose form has no draft controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->json('params')->nullable();
            $table->string('state', 16)->default('ACTIVE');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_policies');
    }
};
