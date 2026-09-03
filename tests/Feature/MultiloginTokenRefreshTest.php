<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiloginTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function jwt(): string
    {
        // Three dot-separated parts, comfortably longer than 40 chars.
        return 'hdr.'.str_repeat('a', 60).'.sig';
    }

    public function test_refresh_signs_in_with_credentials_and_persists_token(): void
    {
        $company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'enabled' => true, 'multilogin_base_url' => 'https://api.multilogin.com']);
        $company->setMultiloginEmail('ops@acme.com');
        $company->setMultiloginPassword('s3cret');
        $company->save();

        $jwt = $this->jwt();
        Http::fake([
            'https://api.multilogin.com/user/signin' => Http::response(['data' => ['token' => $jwt]]),
            'https://api.multilogin.com/profile/search' => Http::response(['data' => []], 200),
        ]);

        $this->post(route('companies.multilogin.refresh', $company))->assertRedirect();

        $this->assertSame($jwt, $company->fresh()->getMultiloginToken());
        $this->assertSame('up', $company->fresh()->serviceState('multilogin'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/user/signin') && ($r->data()['email'] ?? '') === 'ops@acme.com'
            && ($r->data()['password'] ?? '') === md5('s3cret'));
    }

    public function test_refresh_without_credentials_reports_guidance(): void
    {
        $company = Company::create(['name' => 'NoCreds', 'slug' => 'nocreds', 'enabled' => true]);

        $this->post(route('companies.multilogin.refresh', $company))->assertRedirect();
        $this->assertSame('down', $company->fresh()->serviceState('multilogin'));
    }
}
