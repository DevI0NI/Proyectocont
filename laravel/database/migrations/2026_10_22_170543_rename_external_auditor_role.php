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
        DB::table('roles')->where('id', 5)->update(['nombre' => 'Auditor externo']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('id', 5)->where('nombre', 'Auditor externo')->update(['nombre' => 'Empresa Externa']);
    }
};
