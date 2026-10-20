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
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->foreignId('repair_service_id')->nullable()->constrained('repair_services')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('repair_service_id');
        });
    }
};
