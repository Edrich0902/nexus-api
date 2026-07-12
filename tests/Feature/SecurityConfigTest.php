<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityConfigTest extends TestCase
{
    public function test_cors_allowlist_includes_expected_origins(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('http://nexus.test', $origins);
        $this->assertContains('http://localhost:5173', $origins);
        $this->assertContains('https://nexus.barforge.co.za', $origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_force_https_updates_generated_urls(): void
    {
        URL::forceScheme('https');

        $this->assertStringStartsWith('https://', url('/api/v1/auth/me'));
    }
}
