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
            $table->json('audit_photos')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_corrections', function (Blueprint $table) {
            $table->dropColumn('audit_photos');
        });
    }
};
