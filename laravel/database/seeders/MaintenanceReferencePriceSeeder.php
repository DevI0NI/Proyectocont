<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaintenanceReferencePriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $laborSource = 'https://snip.segeplan.gob.gt/share/SCHE%24SINIP/PLANOS_DISENOS/242708-SGXBFLBUKP.pdf';
        $hours = [2, 8, 6, 4, 2, 2, 1, 2, 3, 8, 3, 2];
        DB::transaction(function () use ($laborSource, $hours): void {
            foreach ($hours as $index => $duration) {
                $this->fill('repair_services', 'RPR-BASE-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'Servicio',
                    number_format($duration * 37.5, 2, '.', ''),
                    'SUPUESTO DE PLANIFICACION, no tarifa cotizada: '.$duration.' horas estimadas x Q37.50/h, solo mano de obra. Base orientativa Q300/jornal de herreria; jornada supuesta de 8 h. No incluye materiales, transporte ni recargos. Referencia consultada 19/10/2026: '.$laborSource);
            }
            $parts = [
                [4, 'Pieza', '300.00', 'Provision por tablon de pino de aprox. 2 x 12 pulgadas x 10 pies. No piso marino certificado.', 'https://lignum.com.gt/site/categoria-producto/madera/'],
                [5, 'Metro', '25.00', 'Provision EPDM: INR260/m x Q0.0801/INR, redondeada. Sin flete ni importacion.', 'https://www.orkafittings.com/rubber-gasket.html'],
                [2, 'Lamina', '1114.45', 'Lamina negra generica 4 x 8 pies x 3/16; no panel marino certificado.', 'https://elarenal.com.gt/products/lamina-negra-4-x-8-x-3-16'],
                [3, 'Metro', '15.00', 'Aproximacion de tubo galvanizado generico: Q90 por 6 m / 6; no perfil estructural marino.', 'https://www.mallatec.com.gt/'],
                [6, 'Unidad', '0.98', 'Tornillo plano generico 3/16 x 1 3/4: Q9.80 por 10 / 10.', 'https://gt.epaenlinea.com/torcon-plana-3-16-x-1-3-4-10-u.html'],
                [7, 'Unidad', '1.00', 'Tuerca hexagonal generica 3/8, por unidad.', 'https://www.kemik.gt/tuercas?page=0'],
                [8, 'Unidad', '0.17', 'Remache pop generico 1/8 x 1/4: Q8.50 por 50 / 50.', 'https://www.kemik.gt/remachadoras-y-remaches'],
                [9, 'Kilogramo', '41.67', 'Electrodo E6013 1/8: Q18.90/libra dividido entre 0.45359237 kg/libra; redondeado.', 'https://elarenal.com.gt/collections/all?page=61'],
                [10, 'Kilogramo', '120.00', 'Microalambre fundente .030: Q600 por caja de 5 kg / 5.', 'https://seir.com.gt/categoria-producto/equipo-de-taller-y-automotriz/soldadura-y-corte/'],
                [11, 'Unidad', '60.00', 'Cartucho de poliuretano Truper blanco 280 ml.', 'https://seir.com.gt/producto/sellador-de-poliuretano-de-280-ml-blanco/'],
                [12, 'Litro', '58.12', 'Esmalte generico: Q220/galon US dividido entre 3.785411784 L. No pintura marina certificada.', 'https://www.ryrpinturaferreteria.com/product-page/peninsular-esmalte-brillante-gal%C3%B3n'],
                [13, 'Litro', '65.00', 'Provision Q65/L; referencia de envase menor desde Q60. Confirmar presentacion y color.', 'https://colorshop.gt/producto/anticorrosivo-secado-rapido-base-solvente/'],
                [14, 'Metro', '5.84', 'Cable electrico THHN #12 por metro; confirmar calibre y aplicacion.', 'https://www.celasa.com.gt/shop/chw18-chw18-cable-thhn-12-metro-551'],
                [15, 'Unidad', '2.53', 'Fusible generico tipo clavija 10A/32V DC; confirmar tipo y capacidad del equipo.', 'https://chipcom.com.gt/producto/FUSIBLEAUTO10A-SYSCOM-PARTS-16632'],
                [16, 'Unidad', '400.00', 'Provision USD50 x Q7.65, redondeada. Sin importacion.', 'https://www.sourcingai.com/smart-products/longtengindustrial/galvanized-gear-lock-mechanism-system-parts-shipping-container-locking-bar-QrHfwvRdmZXl'],
            ];
            foreach ($parts as [$index, $unit, $price, $basis, $source]) {
                $this->fill('repair_parts', 'REP-BASE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), $unit, $price,
                    'Aprox. 19/10/2026, no cotizacion. '.$basis.' '.$source);
            }
        });
    }

    private function fill(string $table, string $code, string $unit, string $price, string $note): void
    {
        $record = DB::table($table)->where('code', $code)->where('unit', $unit)->where('currency', 'GTQ')->whereNull('reference_cost')->lockForUpdate()->first();
        if (! $record) {
            return;
        }
        DB::table($table)->where('id', $record->id)->update(['reference_cost' => $price,
            'description' => trim(($record->description ?? '')."\n".$note), 'updated_at' => now()]);
    }
}
