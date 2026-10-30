<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('ingress_cycle_id')->nullable()->change();
            $table->dateTime('legacy_ingress_at')->nullable();
            $table->index(['container_id', 'legacy_ingress_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('maintenance_jobs')->whereNull('ingress_cycle_id')->exists()) {
            throw new RuntimeException('Vincula los mantenimientos antiguos a su ingreso antes de revertir esta migracion.');
        }
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropIndex(['container_id', 'legacy_ingress_at']);
            $table->dropColumn('legacy_ingress_at');
            $table->unsignedBigInteger('ingress_cycle_id')->nullable(false)->change();
        });
    }
};
