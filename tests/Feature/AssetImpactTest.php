<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssetImpactTest extends TestCase
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
            'name' => 'Impact user',
            'role' => 3,
            'email' => 'impact@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]));
    }

    public function test_step_impact_includes_dependent_tests_and_test_cycles(): void
    {
        $step = $this->step('Login step');
        $test = $this->testAsset('Login test', [
            'steps' => [['id' => $step->id]],
        ]);
        $directCycle = $this->testCycle('Direct cycle', [
            'steps' => [$step->id],
        ]);
        $indirectCycle = $this->testCycle('Indirect cycle', [
            'tests' => [['assetId' => $test->id]],
        ]);
        $this->testCycle('Unrelated cycle', [
            'tests' => [999],
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-impact/step/'.$step->id
        )->assertOk()
            ->assertJsonPath('data.asset.assetType', 'step')
            ->assertJsonPath('data.summary.tests', 1)
            ->assertJsonPath('data.summary.testCycles', 2)
            ->assertJsonPath('data.tests.0.id', $test->id)
            ->assertJsonFragment(['id' => $directCycle->id, 'name' => 'Direct cycle'])
            ->assertJsonFragment(['id' => $indirectCycle->id, 'name' => 'Indirect cycle'])
            ->assertJsonMissing(['name' => 'Unrelated cycle']);
    }

    public function test_asset_impact_is_project_and_tenant_scoped(): void
    {
        $step = $this->step('Private step');
        $otherProject = Project::forceCreate([
            'name' => 'Other project',
            'description' => 'Other project',
            'idCostumer' => $this->customer->id,
        ]);
        TestCycle::forceCreate([
            'name' => 'Other project cycle',
            'description' => 'Other project cycle',
            'config' => json_encode(['steps' => [$step->id]]),
            'idProject' => $otherProject->id,
            'idCostumer' => $this->customer->id,
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-impact/step/'.$step->id
        )->assertOk()
            ->assertJsonPath('data.summary.testCycles', 0)
            ->assertJsonMissing(['name' => 'Other project cycle']);
    }

    public function test_asset_impact_rejects_foreign_project(): void
    {
        $otherCustomer = Costumer::forceCreate([
            'costumer' => 'Other customer',
            'description' => 'Other customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'other-api-key',
        ]);
        $foreignProject = Project::forceCreate([
            'name' => 'Foreign project',
            'description' => 'Foreign project',
            'idCostumer' => $otherCustomer->id,
        ]);

        $this->getJson(
            '/api/admin/projects/'.$foreignProject->id.'/asset-impact/step/1'
        )->assertNotFound();
    }

    private function step(string $name): Step
    {
        return Step::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
            'order' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function test_asset(string $name, array $config): Test
    {
        return Test::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode($config),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function test_cycle(string $name, array $config): TestCycle
    {
        return TestCycle::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode($config),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
    }
}
