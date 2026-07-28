<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SsoAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'browser-sso-secret';

    private Costumer $customer;

    private IdentityProvider $oidcProvider;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = Costumer::forceCreate([
            'costumer' => 'SSO customer',
            'description' => 'SSO customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'sso-api-key',
        ]);
        $this->user = User::forceCreate([
            'name' => 'SSO user',
            'role' => 3,
            'email' => 'sso@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
        $this->oidcProvider = IdentityProvider::forceCreate([
            'idCostumer' => $this->customer->id,
            'type' => IdentityProvider::TYPE_OIDC,
            'name' => 'OIDC',
            'issuer' => 'https://idp.example.test',
            'audience' => 'idelium-web',
            'redirectUris' => ['https://localhost/auth/sso/callback'],
            'groupRoleMap' => [],
            'status' => IdentityProvider::STATUS_ACTIVE,
            'metadata' => [
                'authorizationEndpoint' => 'https://idp.example.test/oauth2/authorize',
                'algorithms' => ['HS256'],
                'hmacSecret' => self::SECRET,
            ],
        ]);
    }

    public function test_oidc_sso_start_enforces_redirect_allow_list_and_redacts_provider_metadata(): void
    {
        $admin = User::forceCreate([
            'name' => 'Admin',
            'role' => 2,
            'email' => 'admin@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);

        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://evil.example.test/callback',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('redirectUri');

        $response = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/sso/callback',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorizationUrl', fn (string $url): bool => str_contains($url, 'state='))
            ->assertJsonPath('data.nonce', fn (string $nonce): bool => strlen($nonce) === 48);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson('/api/admin/identity/providers')
            ->assertOk()
            ->assertJsonPath('data.0.metadata.hmacSecret', '[REDACTED]');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sso.start',
            'targetType' => 'identity_provider',
            'targetId' => (string) $this->oidcProvider->id,
        ]);
        $this->assertStringNotContainsString($response->json('data.state'), json_encode(AuditEvent::all()->toArray()));
    }

    public function test_sso_start_requires_stateful_browser_session(): void
    {
        $this->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/sso/callback',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session');
    }

    public function test_oidc_callback_validates_state_nonce_signature_and_logs_in_existing_user(): void
    {
        $started = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/sso/callback',
        ])->assertOk()->json('data');

        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/oidc/callback', [
            'state' => $started['state'],
            'idToken' => $this->idToken([
                'nonce' => $started['nonce'],
                'recipient' => 'https://localhost/auth/sso/callback',
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', 'sso@example.test')
            ->assertJsonMissingPath('access_token');

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame($this->oidcProvider->id, $this->user->fresh()->identityProviderId);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'sso.complete',
            'targetType' => 'user',
            'targetId' => (string) $this->user->id,
        ]);
    }

    public function test_oidc_callback_rejects_replay_wrong_issuer_and_unknown_users(): void
    {
        $started = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/sso/callback',
        ])->assertOk()->json('data');

        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/oidc/callback', [
            'state' => $started['state'],
            'idToken' => $this->idToken([
                'iss' => 'https://attacker.example.test',
                'nonce' => $started['nonce'],
            ]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assertion');

        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/oidc/callback', [
            'state' => $started['state'],
            'idToken' => $this->idToken(['nonce' => $started['nonce']]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');

        $started = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/sso/callback',
        ])->assertOk()->json('data');
        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$this->oidcProvider->id.'/oidc/callback', [
            'state' => $started['state'],
            'idToken' => $this->idToken([
                'nonce' => $started['nonce'],
                'email' => 'not-provisioned@example.test',
            ]),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertGuest();
        $this->assertSame(0, User::where('idCostumer', $this->customer->id)->where('email', 'not-provisioned@example.test')->count());
    }

    public function test_saml_callback_validates_signature_recipient_and_logs_in_existing_user(): void
    {
        $provider = IdentityProvider::forceCreate([
            'idCostumer' => $this->customer->id,
            'type' => IdentityProvider::TYPE_SAML,
            'name' => 'SAML',
            'issuer' => 'https://saml.example.test',
            'audience' => 'idelium-web',
            'redirectUris' => ['https://localhost/auth/saml/callback'],
            'status' => IdentityProvider::STATUS_ACTIVE,
            'metadata' => [
                'ssoUrl' => 'https://saml.example.test/sso',
                'samlSigningSecret' => self::SECRET,
            ],
        ]);
        $started = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$provider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/saml/callback',
        ])->assertOk()->json('data');
        $samlResponse = $this->samlResponse([
            'recipient' => 'https://localhost/auth/saml/callback',
        ]);

        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$provider->id.'/saml/callback', [
            'state' => $started['state'],
            'SAMLResponse' => $samlResponse,
            'Signature' => hash_hmac('sha256', $samlResponse, self::SECRET),
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', 'sso@example.test');

        $started = $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$provider->id.'/start', [
            'redirectUri' => 'https://localhost/auth/saml/callback',
        ])->assertOk()->json('data');
        $this->withHeader('Origin', 'https://localhost')->postJson('/api/sso/'.$provider->id.'/saml/callback', [
            'state' => $started['state'],
            'SAMLResponse' => $samlResponse,
            'Signature' => hash_hmac('sha256', $samlResponse, 'wrong-secret'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('SAMLResponse');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function idToken(array $overrides = [], string $secret = self::SECRET): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = array_merge([
            'iss' => 'https://idp.example.test',
            'aud' => 'idelium-web',
            'sub' => 'idp-user-1',
            'email' => 'sso@example.test',
            'email_verified' => true,
            'iat' => now()->timestamp,
            'nbf' => now()->subSecond()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
        ], $overrides);
        $signed = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        return $signed.'.'.$this->base64Url(hash_hmac('sha256', $signed, $secret, true));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function samlResponse(array $overrides = []): string
    {
        return base64_encode(json_encode(array_merge([
            'iss' => 'https://saml.example.test',
            'aud' => 'idelium-web',
            'sub' => 'saml-user-1',
            'email' => 'sso@example.test',
            'email_verified' => true,
            'iat' => now()->timestamp,
            'nbf' => now()->subSecond()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
        ], $overrides), JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
