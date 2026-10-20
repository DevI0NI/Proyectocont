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
        Schema::table('sucursales', function (Blueprint $table): void {
            $table->char('departamento_codigo', 2)->nullable();
            $table->char('municipio_codigo', 4)->nullable();
            $table->string('direccion', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table): void {
            $table->dropColumn(['departamento_codigo', 'municipio_codigo', 'direccion']);
        });
    }
};
