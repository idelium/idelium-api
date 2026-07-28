<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdeliumKey;
use App\Models\Costumer;
use App\Models\ServiceAccount;
use App\Services\ServiceAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServiceAccountCredentialTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Costumer::forceCreate([
            'costumer' => 'Demo customer',
            'description' => 'Demo customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'legacy-api-key',
        ]);
    }

    public function test_service_account_secret_is_revealed_once_and_stored_as_hash(): void
    {
        $created = app(ServiceAccountService::class)->create(
            $this->customer->id,
            'CI runner',
            scopes: ['runs.launch']
        );

        $serviceAccount = $created['serviceAccount']->fresh();
        $secret = $created['secret'];
        [, $plainSecret] = explode('.', $secret, 2);

        $this->assertStringStartsWith('idsa_', $secret);
        $this->assertArrayNotHasKey('secret', $serviceAccount->toArray());
        $this->assertArrayNotHasKey('secretHash', $serviceAccount->toArray());
        $this->assertTrue(Hash::check($plainSecret, $serviceAccount->secretHash));
        $this->assertStringNotContainsString($plainSecret, $serviceAccount->secretHash);
    }

    public function test_idelium_key_middleware_accepts_active_service_account_credentials(): void
    {
        $created = app(ServiceAccountService::class)->create(
            $this->customer->id,
            'CI runner'
        );

        $request = request();
        $request->headers->set('Idelium-Key', $created['secret']);

        $response = app(AuthenticateIdeliumKey::class)->handle($request, function ($request) {
            return response()->json([
                'customerId' => $request->attributes->get(
                    AuthenticateIdeliumKey::CUSTOMER_ATTRIBUTE
                )->id,
                'serviceAccountId' => $request->attributes->get(
                    AuthenticateIdeliumKey::SERVICE_ACCOUNT_ATTRIBUTE
                )->id,
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->customer->id, $response->getData(true)['customerId']);
        $this->assertNotNull(ServiceAccount::firstOrFail()->lastUsedAt);
    }

    public function test_revoked_service_account_credentials_are_rejected(): void
    {
        $created = app(ServiceAccountService::class)->create(
            $this->customer->id,
            'CI runner'
        );
        app(ServiceAccountService::class)->revoke($created['serviceAccount']);

        $request = request();
        $request->headers->set('Idelium-Key', $created['secret']);

        $response = app(AuthenticateIdeliumKey::class)->handle(
            $request,
            fn () => response()->json(['unexpected' => true])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_legacy_customer_api_key_still_works_during_migration(): void
    {
        $request = request();
        $request->headers->set('Idelium-Key', $this->customer->apiKey);

        $response = app(AuthenticateIdeliumKey::class)->handle(
            $request,
            fn ($request) => response()->json([
                'customerId' => $request->attributes->get(
                    AuthenticateIdeliumKey::CUSTOMER_ATTRIBUTE
                )->id,
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->customer->id, $response->getData(true)['customerId']);
    }
}
