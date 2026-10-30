<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });
        DB::table('system_notifications')->whereNotNull('deleted_at')->update([
            'archived_at' => DB::raw('COALESCE(archived_at, deleted_at)'), 'deleted_at' => null,
        ]);
        DB::table('system_notifications')->whereNotNull('archived_at')->orderBy('id')->eachById(function ($notification): void {
            DB::table('system_notifications')->where('id', $notification->id)->update([
                'expires_at' => Carbon::parse($notification->archived_at)->addMonthNoOverflow(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
