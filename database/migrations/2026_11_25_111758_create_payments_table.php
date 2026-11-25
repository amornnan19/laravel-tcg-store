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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('status');
            $table->unsignedInteger('amount_satang');
            $table->text('qr_payload')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->string('slip_path')->nullable();
            $table->unsignedInteger('slip_amount_satang')->nullable();
            $table->string('slip_ref')->nullable();
            $table->timestamp('slip_paid_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->foreignId('shortfall_of_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
