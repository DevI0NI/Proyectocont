<?php

namespace App\Services;

class MaintenanceCost
{
    /** @return array{labor: string, materials: string, total: string, incomplete: bool} */
    public function summary(object $job, iterable $materials): array
    {
        $amounts = $this->totals($job, $materials);

        return ['labor' => $amounts['labor'] === null ? 'Pendiente' : $this->format($amounts['labor']), 'materials' => $this->format($amounts['materials']),
            'total' => ($amounts['incomplete'] ? 'Parcial: ' : '').$this->format($amounts['total']), 'incomplete' => $amounts['incomplete']];
    }

    /** @return array{labor: ?int, materials: int, total: int, incomplete: bool} Amounts in GTQ cents. */
    public function totals(object $job, iterable $materials): array
    {
        $labor = $this->amount($job->service_unit_cost ?? null, '1', $job->service_currency ?? null, $job->usd_gtq_rate ?? null);
        $incomplete = $labor === null;
        $materialTotal = 0;
        $groupedMaterials = [];
        foreach ($materials as $material) {
            $amount = $this->amount($material->unit_cost ?? null, (string) $material->quantity, 'GTQ', null);
            $incomplete = $incomplete || $amount === null;
            if ($amount !== null) {
                $currency = $material->currency ?? '';
                $groupedMaterials[$currency] = ($groupedMaterials[$currency] ?? 0) + $amount;
            }
        }
        foreach ($groupedMaterials as $currency => $amount) {
            $converted = $this->convert($amount, $currency, $job->usd_gtq_rate ?? null);
            $incomplete = $incomplete || $converted === null;
            $materialTotal += $converted ?? 0;
        }

        return ['labor' => $labor, 'materials' => $materialTotal, 'total' => ($labor ?? 0) + $materialTotal, 'incomplete' => $incomplete];
    }

    private function amount(mixed $price, string $quantity, ?string $currency, ?string $rate): ?int
    {
        if ($price === null || ! in_array($currency, ['GTQ', 'USD'], true)) {
            return null;
        }
        $amount = intdiv((int) round((float) $price * 100) * (int) round((float) $quantity * 1000) + 500, 1000);

        return $this->convert($amount, $currency, $rate);
    }

    private function convert(int $amount, string $currency, ?string $rate): ?int
    {
        if (! in_array($currency, ['GTQ', 'USD'], true)) {
            return null;
        }
        if ($currency === 'USD') {
            if ($rate === null || (float) $rate <= 0) {
                return null;
            }
            $amount = intdiv($amount * (int) round((float) $rate * 1000000) + 500000, 1000000);
        }

        return $amount;
    }

    private function format(int $cents): string
    {
        return 'Q '.number_format($cents / 100, 2);
    }
}
