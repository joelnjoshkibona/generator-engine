<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delegation child. Its own generated spec must create and clean up its own fixture parent rather
 * than depending on the warehouses spec having run first — the property `split.e2e.stub` exists to
 * guarantee, and the reason a delegation needs a real parent table rather than a self-reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('suite_warehouses')->cascadeOnDelete();
            $table->string('reference', 64);
            $table->integer('quantity')->default(0);
            $table->date('moved_on');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_movements');
    }
};
