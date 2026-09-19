<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module the ACTION and LIST-SIDE mechanisms hang off, because they need a table that has
 * several rows to act on and a status to change:
 *
 *  - bulk actions (`archive`, and `close` with `status_target: DONE`) plus batch mode and
 *    select-all-matching, which the generated spec skips unless a list has at least two rows;
 *  - export and import;
 *  - a multi-step Create form (`features.frontend.create.wizard`);
 *  - a wizard action with a Review & Confirm step (`escalate`), an action with a splash endpoint and
 *    a `serviceMethod`/`serviceArgs` binding (`assign`), and an action with TWO url params
 *    (`archiveByYear`).
 *
 * `status_id` is a plain integer with no foreign key on purpose: `status_target` writes a numeric
 * constant into a column literally named `status_id`, and a real FK to the shell's statuses table
 * would make the fixture depend on rows it does not own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 160);
            $table->unsignedTinyInteger('status_id')->default(1)->index();
            $table->string('priority', 16)->default('normal');
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_tickets');
    }
};
