<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plain lookup table with a real `name` column.
 *
 * Deliberately boring: it exists so that FK columns pointing at it have something a list cell can
 * resolve THROUGH. A filterable FK whose target module has no literal `name` renders a raw id in
 * the list while the picker offers resolved labels, and the two can never match — one of the three
 * conditions v3.4.25's filter diagnostics report. Half the value of this table is being the
 * negative control for `suite_edge_cases`, which deliberately lacks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_order_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('code', 12)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->unsignedBigInteger('updated_by_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_order_types');
    }
};
