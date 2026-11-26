<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One row per sealed product taken on pre-order; `reserved_count` is kept in step with the
     * orders by the transaction that places or returns one, never counted on a page load.
     */
    public function up(): void
    {
        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sealed_product_id')->unique()->constrained();
            $table->boolean('is_open')->default(false);
            $table->timestamp('opens_at')->nullable();
            $table->date('release_on');
            $table->date('cancel_until');
            $table->unsignedInteger('deposit_satang');
            $table->unsignedInteger('full_satang');
            $table->unsignedSmallInteger('full_discount_bp')->default(0);
            $table->unsignedInteger('quota');
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedSmallInteger('per_account_limit')->default(1);
            $table->timestamps();

            $table->index(['is_open', 'release_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preorders');
    }
};
