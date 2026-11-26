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
        Schema::create('buylist_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printing_id')->constrained();
            $table->string('condition');
            $table->string('finish')->default('normal');
            $table->unsignedInteger('cash_satang');
            $table->unsignedInteger('credit_satang');
            $table->unsignedInteger('weekly_quota')->nullable();
            $table->unsignedInteger('accepted_this_week')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['printing_id', 'condition', 'finish']);
        });

        Schema::create('buylist_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('payout');
            $table->string('status');
            $table->unsignedInteger('quoted_total_satang');
            $table->unsignedInteger('final_total_satang')->nullable();
            $table->timestamp('quote_expires_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('revised_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('late_note')->nullable();
            $table->string('payout_reference')->nullable();
            $table->text('staff_note')->nullable();
            $table->string('return_tracking')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'id']);
        });

        Schema::create('buylist_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('buylist_submissions')->cascadeOnDelete();
            $table->foreignId('printing_id')->constrained();
            $table->foreignId('buylist_price_id')->nullable()->constrained('buylist_prices')->nullOnDelete();
            $table->string('card_name');
            $table->string('display_code');
            $table->string('language');
            $table->string('finish')->default('normal');
            $table->string('claimed_condition');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('quoted_unit_satang');
            $table->unsignedInteger('quoted_line_satang');
            $table->string('assessed_condition')->nullable();
            $table->unsignedInteger('assessed_unit_satang')->nullable();
            $table->unsignedInteger('assessed_line_satang')->nullable();
            $table->string('assessed_note')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->index('submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buylist_submission_items');
        Schema::dropIfExists('buylist_submissions');
        Schema::dropIfExists('buylist_prices');
    }
};
