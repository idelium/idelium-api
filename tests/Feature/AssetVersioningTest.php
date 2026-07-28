<?php

namespace Tests\Feature;

use App\Models\AssetVersion;
use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class AssetVersioningTest extends TestCase
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
            'email' => 'asset-version@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]));
    }

    public function test_step_create_and_update_records_immutable_versions(): void
    {
        $this->postJson('/api/admin/steps', [
            'name' => 'Open browser',
            'description' => 'Open browser',
            'config' => json_encode(['type' => 'selenium']),
            'idProject' => $this->project->id,
        ])->assertOk();

        $step = Step::firstOrFail();
        $this->putJson('/api/admin/steps/'.$this->project->id.'/'.$step->id, [
            'name' => 'Open browser updated',
            'description' => 'Open browser updated',
            'config' => json_encode(['type' => 'selenium', 'updated' => true]),
        ])->assertOk();

        $versions = AssetVersion::where('assetType', 'step')
            ->where('assetId', $step->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame('asset.created', $versions[0]->reason);
        $this->assertSame('Open browser', $versions[0]->snapshot['name']);
        $this->assertSame(2, $versions[1]->version);
        $this->assertSame('asset.updated', $versions[1]->reason);
        $this->assertSame('Open browser updated', $versions[1]->snapshot['name']);
    }

    public function test_asset_versions_are_immutable(): void
    {
        $version = AssetVersion::forceCreate([
            'idCostumer' => $this->customer->id,
            'idProject' => $this->project->id,
            'assetType' => 'step',
            'assetId' => 1,
            'version' => 1,
            'actorUserId' => null,
            'reason' => 'asset.created',
            'snapshot' => ['name' => 'Immutable'],
        ]);

        $this->expectException(LogicException::class);
        $version->update(['reason' => 'changed']);
    }
}
