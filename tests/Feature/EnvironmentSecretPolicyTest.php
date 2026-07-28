<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnvironmentSecretPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = Costumer::forceCreate([
            'costumer' => 'Demo customer',
            'description' => 'Demo customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'demo-api-key',
        ]);
        $this->project = Project::forceCreate([
            'name' => 'Demo project',
            'description' => 'Demo project',
            'idCostumer' => $this->customer->id,
        ]);

        $this->actingAs(User::forceCreate([
            'name' => 'Test user',
            'role' => 3,
            'email' => 'environment@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]));
    }

    public function test_environment_writes_reject_inline_secrets(): void
    {
        $this->postJson('/api/admin/environments', [
            'code' => 'demo',
            'description' => 'Demo environment',
            'idProject' => $this->project->id,
            'config' => json_encode([
                'baseUrl' => 'https://example.test',
                'apiKey' => 'must-not-be-persisted',
            ]),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('config');

        $this->assertDatabaseCount('environments', 0);
    }

    public function test_environment_reads_redact_secret_refs(): void
    {
        $environment = Environment::forceCreate([
            'code' => 'demo',
            'description' => 'Demo environment',
            'config' => json_encode([
                'baseUrl' => 'https://example.test',
                'apiKey' => [
                    'secretRef' => 'tenant/demo/projects/web/secrets/api-key',
                ],
            ]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);

        $this->getJson('/api/admin/environments/'.$this->project->id.'/'.$environment->id)
            ->assertOk()
            ->assertJsonPath('config.apiKey', '[REDACTED]')
            ->assertJsonPath('config.baseUrl', 'https://example.test')
            ->assertJsonMissing(['secretRef' => 'tenant/demo/projects/web/secrets/api-key']);
    }

    public function test_environment_writes_accept_secret_refs(): void
    {
        $this->postJson('/api/admin/environments', [
            'code' => 'demo',
            'description' => 'Demo environment',
            'idProject' => $this->project->id,
            'config' => json_encode([
                'apiKey' => [
                    'secretRef' => 'tenant/demo/projects/web/secrets/api-key',
                ],
            ]),
        ])->assertOk();

        $environment = Environment::firstOrFail();
        $this->assertSame(
            'tenant/demo/projects/web/secrets/api-key',
            json_decode($environment->config, true)['apiKey']['secretRef']
        );
    }
}
