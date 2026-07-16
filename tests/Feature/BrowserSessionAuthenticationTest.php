<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserSessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $customer = Costumer::forceCreate([
            'costumer' => 'First customer',
            'description' => 'First customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'first-customer-key',
        ]);
        $this->user = User::forceCreate([
            'name' => 'Browser user',
            'role' => 3,
            'email' => 'browser@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);

        config([
            'services.recaptcha.secret' => null,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
    }

    public function test_login_establishes_an_opaque_browser_session(): void
    {
        $response = $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/login', $this->credentials());

        $response
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $this->user->id)
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('session')
            ->assertCookie(config('session.cookie'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_login_rejects_invalid_credentials_without_issuing_a_token(): void
    {
        $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/login', [
                'email' => $this->user->email,
                'password' => 'incorrect-password',
            ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid login details']);

        $this->assertGuest();
    }

    public function test_authenticated_user_endpoint_returns_only_explicit_fields(): void
    {
        $this->actingAs($this->user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ]);
    }

    public function test_logout_requires_authentication_and_invalidates_the_session(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->getJson('/api/logout')->assertMethodNotAllowed();

        $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/login', $this->credentials())
            ->assertOk();
        $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/logout')->assertNoContent();
        $this->withHeader('Origin', 'https://localhost')
            ->getJson('/api/user')->assertUnauthorized();
    }

    public function test_recaptcha_is_verified_with_tls_enabled_http_client(): void
    {
        config(['services.recaptcha.secret' => 'configured-secret']);
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
            ]),
        ]);

        $this->withHeader('Origin', 'https://localhost')
            ->postJson('/api/login', [
                ...$this->credentials(),
                'token' => 'browser-recaptcha-token',
            ])->assertOk();

        Http::assertSent(fn ($request) => $request->isForm()
            && $request['response'] === 'browser-recaptcha-token');
    }

    private function credentials(): array
    {
        return [
            'email' => $this->user->email,
            'password' => 'SensitivePassword123!',
        ];
    }
}
