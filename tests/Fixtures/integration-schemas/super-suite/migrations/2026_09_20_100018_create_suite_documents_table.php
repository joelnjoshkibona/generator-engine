<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module the FILE and PROCESSOR mechanisms hang off:
 *
 *  - `file_media_id` -- any `*_media_id` integer column is inferred as a file column: `required|file` on
 *    create, `nullable|file` on edit, a raw upload becomes a Media row, and an edit that sends no file
 *    keeps the existing one. It is an indexed plain integer with NO foreign key on purpose (the
 *    shell's convention for media references).
 *  - `processors` on every stage x operation (blueprint `module_overrides`): create and edit each get
 *    before_save and after_save, delete gets before_delete and after_delete. The processor class is
 *    hand-written (module-overlays/), so a test can see WHEN each one ran and WITH WHAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 160);
            $table->unsignedBigInteger('file_media_id')->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_documents');
    }
};
