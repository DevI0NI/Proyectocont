<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class TrustedHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_only_the_application_and_configured_hosts_are_trusted(): void
    {
        config(['app.url' => 'https://13-184-227-176.sslip.io', 'app.trusted_hosts' => ['proportsa.com', 'www.proportsa.com']]);
        Request::setTrustedHosts(array_filter($this->app->make(TrustHosts::class)->hosts()));

        foreach (['proportsa.com', 'www.proportsa.com', '13-184-227-176.sslip.io'] as $host) {
            $this->assertSame($host, Request::create('https://'.$host.'/login')->getHost());
        }

        foreach (['evil.example', 'proportsa.com.evil.example', 'notproportsa.com'] as $host) {
            try {
                Request::create('https://'.$host.'/login')->getHost();
                $this->fail("{$host} should not be trusted.");
            } catch (SuspiciousOperationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
