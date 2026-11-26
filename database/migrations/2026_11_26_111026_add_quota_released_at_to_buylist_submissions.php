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
        Schema::table('buylist_submissions', function (Blueprint $table) {
            // Set when the submission gave its quantity back to the weekly quota, so that happens once.
            $table->timestamp('quota_released_at')->nullable()->after('returned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buylist_submissions', function (Blueprint $table) {
            $table->dropColumn('quota_released_at');
        });
    }
};
