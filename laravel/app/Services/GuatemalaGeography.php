<?php

namespace App\Services;

class GuatemalaGeography
{
    public static function departments(): array
    {
        static $departments;

        return $departments ??= json_decode(file_get_contents(config_path('guatemala-geography.json')), true, 512, JSON_THROW_ON_ERROR)['departments'];
    }

    public static function department(?string $code): ?array
    {
        return collect(self::departments())->firstWhere('code', $code);
    }

    public static function municipalities(?string $department): array
    {
        return self::department($department)['municipalities'] ?? [];
    }

    public static function summary(string $department, string $municipality): string
    {
        $record = self::department($department);
        $town = collect($record['municipalities'])->firstWhere('code', $municipality);

        return $town['name'].', '.$record['name'];
    }
}
