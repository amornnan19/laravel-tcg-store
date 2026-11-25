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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->json('address');
            $table->string('courier');
            $table->unsignedInteger('subtotal_satang');
            $table->unsignedInteger('shipping_satang');
            $table->unsignedInteger('protection_satang')->default(0);
            $table->unsignedInteger('insurance_satang')->default(0);
            $table->unsignedInteger('credit_used_satang')->default(0);
            $table->unsignedInteger('total_satang');
            $table->string('tracking_number')->nullable();
            $table->text('staff_note')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('payment_due_at');
            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'placed_at']);
            $table->index(['status', 'payment_due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
