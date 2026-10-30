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
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('source_audit_event_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropColumn('source_audit_event_id');
        });
    }
};
