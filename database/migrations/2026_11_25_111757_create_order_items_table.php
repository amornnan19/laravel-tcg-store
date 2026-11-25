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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Nullable and not a foreign key: the variant may be deleted later, the snapshot below stays.
            $table->nullableMorphs('purchasable');
            $table->string('card_name');
            $table->string('set_code');
            $table->string('number');
            $table->string('language');
            $table->string('condition');
            $table->string('finish');
            $table->unsignedInteger('unit_price_satang');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('line_total_satang');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
