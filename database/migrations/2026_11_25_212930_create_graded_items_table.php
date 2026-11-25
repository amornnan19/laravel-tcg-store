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
        Schema::create('graded_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printing_id')->constrained();
            $table->string('grader');
            $table->decimal('grade', 3, 1);
            $table->string('grade_label')->nullable();
            $table->string('cert_number')->nullable();
            $table->json('subgrades')->nullable();
            $table->unsignedInteger('price_satang');
            $table->string('status')->default('withdrawn');
            $table->string('front_path')->nullable();
            $table->string('back_path')->nullable();
            $table->string('label_path')->nullable();
            $table->string('note_th')->nullable();
            $table->timestamps();

            $table->index(['printing_id', 'status']);
            $table->index(['status', 'price_satang']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('graded_items');
    }
};
