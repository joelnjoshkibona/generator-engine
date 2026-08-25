<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipts table: scaffolded as **create + list + view**, no edit/delete.
 *
 * The second reduced-operation shape, and not redundant with `suite_ledgers`: that one has no
 * create path at all (so its e2e spec cannot make its own fixture and depends entirely on the
 * prerequisite seeder), while this one can create but never edit. The two exercise different
 * branches of both the details-layout emitter and the CRUD spec builder.
 *
 * Also pins v3.4.19: a read-only module's generated spec used to click its next step into a View
 * dialog that was still open, because nothing closed it for a module with no edit button to move on
 * to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('receipt_no', 32)->unique();
            $table->string('payer', 160);
            $table->decimal('amount', 15, 2);
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_receipts');
    }
};
