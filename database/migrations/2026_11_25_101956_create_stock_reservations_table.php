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
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->morphs('purchasable');
            $table->unsignedInteger('qty');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['cart_id', 'purchasable_type', 'purchasable_id'], 'stock_reservations_line_unique');
            $table->index('expires_at');
            $table->index(['purchasable_type', 'purchasable_id', 'expires_at'], 'stock_reservations_availability_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
