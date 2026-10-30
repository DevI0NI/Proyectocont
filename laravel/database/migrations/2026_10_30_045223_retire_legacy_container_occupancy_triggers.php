<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $overfilled = DB::table('bloques as b')->whereRaw('(SELECT COUNT(*) FROM contenedores c WHERE c.bloque_id = b.id AND c.estado_id = 1) > b.capacidad_maxima')->exists();
        if ($overfilled) {
            throw new RuntimeException('Hay bloques con mas contenedores activos que capacidad. Revisa sus datos antes de retirar los triggers.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS contenedores_ai');
        DB::unprepared('DROP TRIGGER IF EXISTS contenedores_ad');
        DB::statement('UPDATE bloques b SET capacidad_actual = (SELECT COUNT(*) FROM contenedores c WHERE c.bloque_id = b.id AND c.estado_id = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('Esta correccion no reinstala los triggers defectuosos. Para volver al estado anterior usa el respaldo previo.');
        }
    }
};
