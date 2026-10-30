<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_login_has_enforced_browser_protection_without_blocking_application_scripts(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'")
            ->assertHeader('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    }

    public function test_security_headers_preserve_json_response_status_body_and_private_cache_control(): void
    {
        $response = (new SecurityHeaders)->handle(Request::create('/example'), fn (Request $request): Response => new Response('{"error":"invalid"}', 422, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('{"error":"invalid"}', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
