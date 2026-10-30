<?php

namespace App\Services;

final class IsoCodeCatalog
{
    public const SOURCE = 'https://www.hapag-lloyd.com/en/services-information/cargo-fleet/container/container-type-groups.html';

    public static function records(): array
    {
        $groups = [
            '20' => [20, 6058, 2438, ['P1', 'T5']],
            '22' => [20, 6058, 2591, ['G0', 'G1', 'V0', 'R1', 'R9', 'U1', 'U6', 'P1', 'P3', 'P8', 'T0', 'T5', 'T6']],
            '42' => [40, 12192, 2591, ['G0', 'G1', 'R1', 'R9', 'U1', 'U6', 'P1', 'P3', 'P8']],
            '45' => [40, 12192, 2896, ['G0', 'G1', 'R1', 'R9', 'U1', 'U6', 'P3', 'P8']],
            'L5' => [45, 13716, 2896, ['G1']],
        ];
        $types = [
            'G0' => 'Uso general sin ventilacion', 'G1' => 'Uso general',
            'R1' => 'Refrigerado', 'U1' => 'Open Top',
            'P1' => 'Flat Rack de extremos fijos', 'P3' => 'Flat Rack de extremos plegables',
            'P8' => 'Flat Rack plegable a nivel de plataforma', 'V0' => 'Ventilado',
            'U6' => 'Hardtop', 'R9' => 'Refrigerado para carga no alimentaria',
            'T0' => 'Tanque para liquidos no peligrosos',
            'T5' => 'Tanque para liquidos peligrosos (4 bar)',
            'T6' => 'Tanque para liquidos peligrosos (6 bar)',
        ];
        $records = [];
        foreach ($groups as $size => [$feet, $length, $height, $codes]) {
            foreach ($codes as $type) {
                $code = $size.$type;
                $records[$code] = [
                    'code' => $code,
                    'name' => $types[$type].' de '.$feet.' pies'.($height === 2896 ? ' High Cube' : ''),
                    'type_name' => $types[$type], 'length_mm' => $length, 'width_mm' => 2438,
                    'height_mm' => $height, 'source_url' => self::SOURCE,
                ];
            }
        }

        return $records;
    }
}
