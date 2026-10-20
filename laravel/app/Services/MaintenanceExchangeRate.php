<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MaintenanceExchangeRate
{
    public const SOURCE = 'https://banguat.gob.gt/variables/ws/TipoCambio.asmx';

    public function quote(?object $job = null): array
    {
        if ($job?->usd_gtq_rate !== null) {
            return ['rate' => $job->usd_gtq_rate, 'date' => $job->exchange_rate_date, 'source' => $job->exchange_rate_source, 'token' => null];
        }
        $key = 'maintenance.usd_gtq.'.now('America/Guatemala')->toDateString();
        $quote = Cache::get($key);
        if ($quote === null) {
            $quote = $this->fetch();
            Cache::put($key, $quote, $quote['rate'] === null ? 120 : 3600);
        }
        $quote['token'] = $quote['rate'] === null ? null : Crypt::encryptString(json_encode($quote + ['issued_at' => now()->timestamp], JSON_THROW_ON_ERROR));

        return $quote;
    }

    public function snapshot(object $job, ?string $token): array
    {
        if ($job->usd_gtq_rate !== null) {
            return [];
        }
        if (! $token) {
            return [];
        }
        try {
            $quote = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($quote) || ! preg_match('/^\d{1,3}\.\d{6}$/', $quote['rate'] ?? '') || (float) $quote['rate'] <= 0
                || ($quote['source'] ?? '') !== self::SOURCE || ! is_int($quote['issued_at'] ?? null)
                || $quote['issued_at'] > now()->timestamp || $quote['issued_at'] < now()->subDay()->timestamp) {
                throw new \UnexpectedValueException;
            }
            $this->validateDate($quote['date']);

            return ['usd_gtq_rate' => $quote['rate'], 'exchange_rate_date' => $quote['date'],
                'exchange_rate_source' => $quote['source'], 'exchange_rate_captured_at' => now()];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['exchange_quote' => 'El tipo de cambio venció o no es válido. Recarga la página antes de guardar.']);
        }
    }

    private function validateDate(string $date): void
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date, 'America/Guatemala');
        $today = now('America/Guatemala')->startOfDay();
        if ($day->format('Y-m-d') !== $date || $day->greaterThan($today) || $day->lessThan($today->copy()->subDays(7))) {
            throw new \UnexpectedValueException;
        }
    }

    private function fetch(): array
    {
        try {
            $response = Http::connectTimeout(2)->timeout(4)->withHeaders([
                'SOAPAction' => '"http://www.banguat.gob.gt/variables/ws/TipoCambioDia"',
            ])->withBody('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><TipoCambioDia xmlns="http://www.banguat.gob.gt/variables/ws/" /></soap:Body></soap:Envelope>', 'text/xml; charset=utf-8')->post(self::SOURCE)->throw();
            $document = new \DOMDocument;
            if (! @$document->loadXML($response->body(), LIBXML_NONET)) {
                throw new \UnexpectedValueException;
            }
            $xpath = new \DOMXPath($document);
            $rate = trim($xpath->evaluate('string((//*[local-name()="VarDolar"]/*[local-name()="referencia"])[1])'));
            $date = trim($xpath->evaluate('string((//*[local-name()="VarDolar"]/*[local-name()="fecha"])[1])'));
            if (! preg_match('/^\d{1,3}(?:\.\d{1,6})?$/', $rate) || (float) $rate <= 0) {
                throw new \UnexpectedValueException;
            }
            $date = Carbon::createFromFormat('!d/m/Y', $date, 'America/Guatemala')->toDateString();
            $this->validateDate($date);

            return ['rate' => number_format((float) $rate, 6, '.', ''), 'date' => $date, 'source' => self::SOURCE];
        } catch (\Throwable) {
            return ['rate' => null, 'date' => null, 'source' => self::SOURCE];
        }
    }
}
