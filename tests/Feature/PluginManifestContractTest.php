<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Plugin;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PluginManifestContractTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Project $project;

    private PluginManifestService $pluginManifests;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = Costumer::forceCreate([
            'costumer' => 'Plugin customer',
            'description' => 'Plugin customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'plugin-api-key',
        ]);
        $this->project = Project::forceCreate([
            'name' => 'PLUGINS',
            'description' => 'Plugin project',
            'idCostumer' => $this->customer->id,
        ]);
        $this->pluginManifests = app(PluginManifestService::class);

        Sanctum::actingAs(User::forceCreate([
            'name' => 'Plugin reviewer',
            'role' => 3,
            'email' => 'plugin-reviewer@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]));
    }

    public function test_legacy_web_plugin_source_is_wrapped_as_unapproved_manifest(): void
    {
        $source = 'def init(driver, json_config, param=None): return 1';

        $this->postJson('/api/admin/plugins', [
            'name' => 'legacy_step',
            'description' => 'Legacy step',
            'code' => [$source],
            'idProject' => $this->project->id,
        ])->assertOk();

        $plugin = Plugin::where('name', 'legacy_step')->firstOrFail();
        $manifest = json_decode($plugin->code, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(PluginManifestService::API_VERSION, $manifest['apiVersion']);
        $this->assertSame(PluginManifestService::UNAPPROVED_STATUS, $manifest['approvalStatus']);
        $this->assertSame(hash('sha256', $source), $manifest['sourceSha256']);
        $this->assertFalse($manifest['provenance']['reviewed']);

        $this->getJson('/api/admin/plugins/'.$this->project->id.'/'.$plugin->id)
            ->assertOk()
            ->assertJsonPath('code', json_encode([$source], JSON_THROW_ON_ERROR));
    }

    public function test_cli_plugin_endpoint_returns_enterprise_manifest(): void
    {
        $source = 'def init(driver, json_config, param=None): return 1';
        $plugin = Plugin::forceCreate([
            'name' => 'cli_step',
            'description' => 'CLI step',
            'code' => json_encode(
                $this->pluginManifests->normalizeForStorage([$source], 'cli_step'),
                JSON_THROW_ON_ERROR
            ),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->getJson('/api/ideliumcl/plugin/'.$plugin->id)
            ->assertOk();

        $manifest = json_decode($response->json('code'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(PluginManifestService::API_VERSION, $manifest['apiVersion']);
        $this->assertSame('cli_step', $manifest['name']);
        $this->assertSame(hash('sha256', $source), $manifest['sourceSha256']);
        $this->assertSame(['step'], $manifest['capabilities']);
        $this->assertSame('subprocess', $manifest['execution']['mode']);
    }

    public function test_approved_manifest_requires_integrity_and_reviewed_provenance(): void
    {
        $source = 'def init(driver, json_config, param=None): return 1';

        $this->postJson('/api/admin/plugins', [
            'name' => 'tampered_step',
            'description' => 'Tampered step',
            'idProject' => $this->project->id,
            'code' => [
                'apiVersion' => PluginManifestService::API_VERSION,
                'source' => $source,
                'sourceSha256' => str_repeat('0', 64),
                'approvalStatus' => PluginManifestService::APPROVED_STATUS,
                'provenance' => ['reviewed' => true, 'source' => 'security-review'],
                'execution' => ['mode' => 'subprocess', 'timeoutSeconds' => 30],
                'capabilities' => ['step'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseMissing('plugins', ['name' => 'tampered_step']);
    }

    public function test_approved_manifest_is_persisted_when_hash_and_provenance_match(): void
    {
        $source = 'def init(driver, json_config, param=None): return 1';

        $this->postJson('/api/admin/plugins', [
            'name' => 'approved_step',
            'description' => 'Approved step',
            'idProject' => $this->project->id,
            'code' => [
                'apiVersion' => PluginManifestService::API_VERSION,
                'source' => $source,
                'sourceSha256' => hash('sha256', $source),
                'approvalStatus' => PluginManifestService::APPROVED_STATUS,
                'provenance' => ['reviewed' => true, 'source' => 'security-review'],
                'execution' => ['mode' => 'subprocess', 'timeoutSeconds' => 30],
                'capabilities' => ['step'],
            ],
        ])->assertOk();

        $manifest = json_decode(
            Plugin::where('name', 'approved_step')->firstOrFail()->code,
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame(PluginManifestService::APPROVED_STATUS, $manifest['approvalStatus']);
        $this->assertTrue($manifest['provenance']['reviewed']);
    }
}
