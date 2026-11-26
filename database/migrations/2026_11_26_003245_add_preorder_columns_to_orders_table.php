<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A pre-order is its own order: `preorder_id` says which pre-order it reserves a place in,
     * `cancel_until` and `deposit_satang` are the refund deadline and the deposit portion as they were when the order was placed (what is kept after the deadline),
     * and `balance_due_satang` is what the deposit plan still owes once the deposit is in.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('kind')->default('stock')->after('status');
            $table->foreignId('preorder_id')->nullable()->after('kind')->constrained();
            $table->string('preorder_plan')->nullable()->after('preorder_id');
            $table->date('cancel_until')->nullable()->after('preorder_plan');
            $table->unsignedInteger('deposit_satang')->default(0)->after('cancel_until');
            $table->unsignedInteger('balance_due_satang')->default(0)->after('deposit_satang');
            $table->timestamp('balance_requested_at')->nullable()->after('balance_due_satang');

            $table->index(['kind', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropConstrainedForeignId('preorder_id');
            $table->dropColumn(['kind', 'preorder_plan', 'cancel_until', 'deposit_satang', 'balance_due_satang', 'balance_requested_at']);
        });
    }
};
