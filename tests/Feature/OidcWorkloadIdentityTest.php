<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\OidcWorkloadToken;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OidcWorkloadIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-oidc-secret';

    private Costumer $customer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Costumer::forceCreate([
            'costumer' => 'OIDC customer',
            'description' => 'OIDC customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'legacy-oidc-api-key',
        ]);
        $this->project = Project::forceCreate([
            'name' => 'OIDC project',
            'description' => 'OIDC project',
            'idCostumer' => $this->customer->id,
        ]);

        config([
            'oidc_workload_identity.token_ttl_seconds' => 300,
            'oidc_workload_identity.max_assertion_age_seconds' => 300,
            'oidc_workload_identity.providers.github-actions' => [
                'issuer' => 'https://token.actions.githubusercontent.com',
                'audience' => 'idelium-api',
                'algorithms' => ['HS256'],
                'hmacSecret' => self::SECRET,
                'publicKeys' => [],
                'policies' => [[
                    'idCostumer' => $this->customer->id,
                    'idProject' => $this->project->id,
                    'repository' => 'idelium/idelium-api',
                    'ref' => 'refs/heads/main',
                    'environment' => 'production',
                    'scopes' => ['runs.launch'],
                ]],
            ],
        ]);
    }

    public function test_oidc_assertion_exchange_issues_hashed_short_lived_project_token(): void
    {
        $assertion = $this->assertion();

        $response = $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $assertion,
        ])
            ->assertCreated()
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('projectId', $this->project->id)
            ->assertJsonPath('scopes.0', 'runs.launch');

        $tokenValue = $response->json('token');
        [, $plainSecret] = explode('.', $tokenValue, 2);
        $token = OidcWorkloadToken::firstOrFail();
        $event = AuditEvent::where('action', 'oidc_workload_token.exchange')->firstOrFail();

        $this->assertStringStartsWith('idwo_', $tokenValue);
        $this->assertSame($this->customer->id, $token->idCostumer);
        $this->assertSame($this->project->id, $token->idProject);
        $this->assertTrue(Hash::check($plainSecret, $token->tokenHash));
        $this->assertSame('[REDACTED]', $event->afterValues['token']);
        $this->assertStringNotContainsString($tokenValue, json_encode($event->toArray()));
        $this->assertStringNotContainsString($assertion, json_encode($event->toArray()));
    }

    public function test_wrong_audience_is_rejected_and_audited_without_assertion(): void
    {
        $assertion = $this->assertion(['aud' => 'other-api']);

        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $assertion,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assertion');

        $event = AuditEvent::where('action', 'oidc_workload_token.reject')->firstOrFail();

        $this->assertSame(AuditEvent::RESULT_FAILURE, $event->result);
        $this->assertSame('[REDACTED]', $event->afterValues['assertion']);
        $this->assertStringNotContainsString($assertion, json_encode($event->toArray()));
    }

    public function test_unauthorized_branch_is_rejected(): void
    {
        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $this->assertion(['ref' => 'refs/heads/feature']),
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.assertion.0',
                'The OIDC assertion is not authorized for this project.'
            );
    }

    public function test_expired_assertion_is_rejected(): void
    {
        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $this->assertion([
                'iat' => now()->subMinutes(10)->timestamp,
                'exp' => now()->subMinute()->timestamp,
            ]),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assertion.0', 'The OIDC assertion is expired.');
    }

    public function test_assertion_replay_is_rejected(): void
    {
        $assertion = $this->assertion(['jti' => 'replay-id']);

        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $assertion,
        ])->assertCreated();

        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $assertion,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assertion.0', 'The OIDC assertion has already been used.');
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postJson('/api/oidc/token-exchange', [
            'provider' => 'github-actions',
            'projectId' => $this->project->id,
            'assertion' => $this->assertion(secret: 'wrong-secret'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assertion.0', 'The OIDC assertion signature is invalid.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertion(array $overrides = [], string $secret = self::SECRET): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = array_merge([
            'iss' => 'https://token.actions.githubusercontent.com',
            'aud' => 'idelium-api',
            'sub' => 'repo:idelium/idelium-api:environment:production',
            'repository' => 'idelium/idelium-api',
            'ref' => 'refs/heads/main',
            'environment' => 'production',
            'iat' => now()->timestamp,
            'nbf' => now()->subSecond()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
            'jti' => uniqid('oidc-', true),
        ], $overrides);
        $signed = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        return $signed.'.'.$this->base64Url(hash_hmac('sha256', $signed, $secret, true));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
