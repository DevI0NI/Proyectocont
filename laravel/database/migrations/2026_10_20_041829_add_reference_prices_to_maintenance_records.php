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
            $table->decimal('service_quantity', 9, 3)->default(1);
            $table->string('service_unit', 30)->nullable();
            $table->decimal('service_unit_cost', 10, 2)->nullable();
            $table->string('service_currency', 3)->nullable();
        });
        Schema::table('maintenance_materials', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_materials', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'currency']);
        });
        Schema::table('maintenance_jobs', function (Blueprint $table) {
            $table->dropColumn(['service_quantity', 'service_unit', 'service_unit_cost', 'service_currency']);
        });
    }
};
