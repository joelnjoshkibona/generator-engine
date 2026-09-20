<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module the ITEM-PICKER hangs off: a JSON column edited by picking rows from a catalog that the
 * create/edit splash pre-loads.
 *
 *  - `items` is nullable JSON. Introspection gives a JSON column no form field, so the blueprint's
 *    `module_overrides` APPENDS the picker field (`$merge` ... `$append`) and declares `json_rules` for
 *    the whole stored row -- the picker stores the picked catalog item AND its own config fields, and
 *    Laravel prunes any nested key no rule names.
 *  - The catalog is a `type: custom` splash source (static data), and the splash route needs a
 *    non-empty top-level `constants` as well as `createSplash`/`editSplash`.
 *  - One catalog name carries an apostrophe on purpose: custom splash data is emitted as PHP literals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_kits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->json('items')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_kits');
    }
};
