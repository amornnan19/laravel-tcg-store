<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A generic accessory has no game, and a product need not belong to a set, so both are nullable.
     */
    public function up(): void
    {
        Schema::create('sealed_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->nullable()->constrained();
            $table->foreignId('set_id')->nullable()->constrained('sets');
            $table->string('kind');
            $table->string('slug')->unique();
            $table->string('name_th')->nullable();
            $table->string('name_en');
            $table->string('sku')->unique();
            $table->unsignedInteger('price_satang')->nullable();
            $table->unsignedInteger('qty')->default(0);
            $table->string('image_path')->nullable();
            $table->text('description_th')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_preorder')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sealed_products');
    }
};
