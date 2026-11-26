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
        Schema::table('users', function (Blueprint $table) {
            $table->integer('store_credit_satang')->default(0);
        });

        Schema::create('store_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('delta_satang');
            $table->string('reason');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->integer('balance_after_satang');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('credit_returned_at')->nullable()->after('refunded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('credit_returned_at');
        });

        Schema::dropIfExists('store_credit_entries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('store_credit_satang');
        });
    }
};
