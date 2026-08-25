<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A status machine with a paired date range — four separate v3.4.25 defects meet here.
 *
 *  status                 varchar with a schema DEFAULT, and an `in:` rule supplied via the
 *                         blueprint. Deliberately NOT an enum: an enum is covered by a different
 *                         code path (`enum_values`), and the defect being pinned is precisely a
 *                         plain string column whose domain lives only in a rule and whose sane
 *                         starting value lives only in the default. Without the default, every
 *                         generated factory row was born as two random words — a state no guard in
 *                         the application accepts. Without the rule being read, every create was
 *                         filled with a 7-digit number and 422'd.
 *
 *  billing_cycle_months   integer whose entire valid domain is an `in:` rule. Fits the column at
 *                         seven digits; refused by validation at seven digits.
 *
 *  start_date / end_date  `end_date` carries `after_or_equal:start_date`. The generated edit step
 *                         advanced only its anchor, breaking the rule the generator itself wrote,
 *                         so the update 422'd and the spec timed out on a row assertion that reads
 *                         exactly like a product bug.
 *
 *  grace_days             `nullable|integer` — legal at any value, absurd at seven digits. Nothing
 *                         derivable is wrong here, which is what makes it the `sample_value` case:
 *                         the constraint is domain sense, not legality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 32)->unique();
            $table->string('status', 32)->default('DRAFT');
            $table->string('deposit_status', 32)->default('NONE');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('billing_cycle_months')->default(1);
            $table->integer('grace_days')->nullable();
            $table->decimal('monthly_rent', 15, 2);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_contracts');
    }
};
