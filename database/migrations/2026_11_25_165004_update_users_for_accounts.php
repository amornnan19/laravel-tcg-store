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
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('line_user_id')->nullable()->unique()->after('is_staff');
            $table->string('line_display_name')->nullable()->after('line_user_id');
            $table->string('line_avatar_url', 500)->nullable()->after('line_display_name');
            $table->boolean('line_notify_opt_in')->default(false)->after('line_avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['line_user_id']);
            $table->dropColumn(['line_user_id', 'line_display_name', 'line_avatar_url', 'line_notify_opt_in']);
        });
    }
};
