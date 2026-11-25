<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A graded item or a sealed product has no card number, condition or finish, so those
     * snapshot columns may be empty, and `kind` says which shape the line has.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('number')->nullable()->change();
            $table->string('language')->nullable()->change();
            $table->string('condition')->nullable()->change();
            $table->string('finish')->nullable()->change();
            $table->string('kind')->default('single')->after('purchasable_id');
            $table->string('detail')->nullable()->after('finish');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['kind', 'detail']);
        });
    }
};
