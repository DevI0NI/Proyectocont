<?php

namespace Tests\Feature;

use App\Services\MaintenanceExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceExchangeRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::setDefaultDriver('array');
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Guatemala'));
        Http::preventStrayRequests();
    }

    private function response(string $rate = '7.63672', string $date = '19/10/2026'): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><TipoCambioDiaResponse xmlns="http://www.banguat.gob.gt/variables/ws/"><CambioDolar><VarDolar><fecha>'.$date.'</fecha><referencia>'.$rate.'</referencia></VarDolar></CambioDolar></TipoCambioDiaResponse></soap:Body></soap:Envelope>';
    }

    public function test_quotes_use_official_response_cache_and_signed_snapshot(): void
    {
        Http::fake([MaintenanceExchangeRate::SOURCE => Http::response($this->response())]);
        $service = app(MaintenanceExchangeRate::class);
        $quote = $service->quote();
        $this->assertSame('7.636720', $quote['rate']);
        $this->assertSame('2026-10-19', $quote['date']);
        $service->quote();
        Http::assertSentCount(1);
        $snapshot = $service->snapshot((object) ['usd_gtq_rate' => null], $quote['token']);
        $this->assertSame('7.636720', $snapshot['usd_gtq_rate']);
        $this->assertSame(MaintenanceExchangeRate::SOURCE, $snapshot['exchange_rate_source']);
    }

    public function test_stored_rate_is_not_refreshed_or_replaced(): void
    {
        $job = (object) ['usd_gtq_rate' => '7.500000', 'exchange_rate_date' => '2025-01-01', 'exchange_rate_source' => MaintenanceExchangeRate::SOURCE];
        $service = app(MaintenanceExchangeRate::class);
        $this->assertSame('7.500000', $service->quote($job)['rate']);
        $this->assertSame([], $service->snapshot($job, 'ignored'));
        Http::assertNothingSent();
    }

    public function test_modified_or_expired_quote_is_rejected(): void
    {
        Http::fake([MaintenanceExchangeRate::SOURCE => Http::response($this->response())]);
        $service = app(MaintenanceExchangeRate::class);
        $token = $service->quote()['token'];
        foreach (['malformed', $token.'tampered'] as $invalid) {
            try {
                $service->snapshot((object) ['usd_gtq_rate' => null], $invalid);
                $this->fail('A modified quote must not be accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('exchange_quote', $exception->errors());
            }
        }
        $this->travel(25)->hours();
        $this->expectException(ValidationException::class);
        $service->snapshot((object) ['usd_gtq_rate' => null], $token);
    }

    public function test_invalid_responses_do_not_generate_invented_exchange_rates(): void
    {
        foreach ([$this->response('0'), $this->response('-7'), $this->response('7', '01/01/2020'),
            $this->response('7', '20/10/2026'), '<broken', '<root/>'] as $body) {
            Cache::flush();
            Http::fake([MaintenanceExchangeRate::SOURCE => Http::response($body)]);
            $this->assertNull(app(MaintenanceExchangeRate::class)->quote()['rate']);
        }
        Cache::flush();
        Http::fake([MaintenanceExchangeRate::SOURCE => Http::response('Unavailable', 503)]);
        $this->assertNull(app(MaintenanceExchangeRate::class)->quote()['rate']);
        $this->assertNull(app(MaintenanceExchangeRate::class)->quote()['token']);
    }
}
