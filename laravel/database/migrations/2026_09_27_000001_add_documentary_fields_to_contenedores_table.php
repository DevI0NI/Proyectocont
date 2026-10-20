<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contenedores', function (Blueprint $table): void {
            if (! Schema::hasColumn('contenedores', 'iso_code')) {
                $table->string('iso_code', 10)->nullable()->after('modelo_id');
            }

            if (! Schema::hasColumn('contenedores', 'marca')) {
                $table->string('marca', 80)->nullable()->after('iso_code');
            }

            if (! Schema::hasColumn('contenedores', 'naviera')) {
                $table->string('naviera', 120)->nullable()->after('fecha_ingreso');
            }

            if (! Schema::hasColumn('contenedores', 'transportista')) {
                $table->string('transportista', 120)->nullable()->after('naviera');
            }

            if (! Schema::hasColumn('contenedores', 'conductor')) {
                $table->string('conductor', 120)->nullable()->after('transportista');
            }

            if (! Schema::hasColumn('contenedores', 'placa_anio')) {
                $table->unsignedSmallInteger('placa_anio')->nullable()->after('conductor');
            }

            if (! Schema::hasColumn('contenedores', 'placa_cabezal')) {
                $table->string('placa_cabezal', 15)->nullable()->after('placa_anio');
            }

            if (! Schema::hasColumn('contenedores', 'placa_chasis')) {
                $table->string('placa_chasis', 15)->nullable()->after('placa_cabezal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contenedores', function (Blueprint $table): void {
            $columns = [
                'iso_code',
                'marca',
                'naviera',
                'transportista',
                'conductor',
                'placa_anio',
                'placa_cabezal',
                'placa_chasis',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('contenedores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
