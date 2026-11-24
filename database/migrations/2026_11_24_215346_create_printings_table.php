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
        Schema::create('printings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained();
            $table->foreignId('set_id')->constrained('sets');
            $table->string('number');
            $table->string('rarity_code');
            $table->string('language');
            $table->boolean('has_foil')->default(false);
            $table->string('image_path')->nullable();
            $table->date('released_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['set_id', 'number', 'language']);
            $table->index(['card_id', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printings');
    }
};
