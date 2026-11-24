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
        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained();
            $table->string('code');
            $table->string('name_en');
            $table->string('name_th')->nullable();
            $table->date('released_on')->nullable();
            $table->smallInteger('card_count')->default(0);
            $table->smallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sets');
    }
};
