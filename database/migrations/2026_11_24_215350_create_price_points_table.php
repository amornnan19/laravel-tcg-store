<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->date('on_date');
            $table->integer('avg_satang');
            $table->integer('low_satang');
            $table->integer('high_satang');
            $table->integer('sold_qty')->default(0);
            $table->timestamps();

            $table->unique(['variant_id', 'on_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_points');
    }
};
