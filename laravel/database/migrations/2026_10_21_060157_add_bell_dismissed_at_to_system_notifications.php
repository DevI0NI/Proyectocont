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
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->timestamp('bell_dismissed_at')->nullable();
            $table->index(['user_id', 'branch_id', 'bell_dismissed_at'], 'notifications_bell_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_bell_index');
            $table->dropColumn('bell_dismissed_at');
        });
    }
};
