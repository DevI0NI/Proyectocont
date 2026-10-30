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
            $table->decimal('usd_gtq_rate', 12, 6)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 255)->nullable();
            $table->timestamp('exchange_rate_captured_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropColumn(['usd_gtq_rate', 'exchange_rate_date', 'exchange_rate_source', 'exchange_rate_captured_at']);
        });
    }
};
