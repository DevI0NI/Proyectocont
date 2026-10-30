<?php

namespace Database\Seeders;

use App\Services\IsoCodeCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IsoCodeCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (IsoCodeCatalog::records() as $record) {
                if (! DB::table('container_iso_codes')->where('code', $record['code'])->exists()) {
                    DB::table('container_iso_codes')->insert($record + ['status' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
