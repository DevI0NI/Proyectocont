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
        Schema::table('maintenance_corrections', function (Blueprint $table) {
            $table->text('diagnosis')->nullable();
            $table->json('photos_before')->nullable();
            $table->unsignedBigInteger('repair_service_id')->nullable();
            $table->json('repair_service_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_corrections', function (Blueprint $table) {
            $table->dropColumn(['diagnosis', 'photos_before', 'repair_service_id', 'repair_service_snapshot']);
        });
    }
};
