<?php

namespace Tests\Feature;

use App\Models\AssetVersion;
use App\Models\AssetVersionReviewEvent;
use App\Models\AuditEvent;
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

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 2, 'name' => 'admin']);
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
        $this->user = User::forceCreate([
            'name' => 'Test user',
            'role' => 3,
            'email' => 'asset-version@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
        $this->actingAs($this->user);
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

    public function test_asset_version_history_endpoint_lists_project_scoped_versions(): void
    {
        $step = $this->step([
            'name' => 'Open browser',
            'description' => 'Open browser',
        ]);
        $firstVersion = $this->version($step, 1, [
            'name' => 'Open browser',
            'description' => 'Open browser',
        ]);
        $secondVersion = $this->version($step, 2, [
            'name' => 'Open browser updated',
            'description' => 'Open browser',
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/step/'.$step->id
        )->assertOk()
            ->assertJsonPath('data.0.id', $secondVersion->id)
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.1.id', $firstVersion->id)
            ->assertJsonMissingPath('data.0.snapshot');
    }

    public function test_asset_version_detail_and_diff_are_tenant_scoped(): void
    {
        $step = $this->step(['name' => 'Create account']);
        $firstVersion = $this->version($step, 1, [
            'name' => 'Create account',
            'description' => 'Initial flow',
        ]);
        $secondVersion = $this->version($step, 2, [
            'name' => 'Create account',
            'description' => 'Validated account flow',
            'config' => json_encode(['type' => 'selenium']),
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$firstVersion->id
        )->assertOk()
            ->assertJsonPath('data.snapshot.name', 'Create account');

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'
            .$firstVersion->id.'/diff/'.$secondVersion->id
        )->assertOk()
            ->assertJsonPath('data.from.versionId', $firstVersion->id)
            ->assertJsonPath('data.to.versionId', $secondVersion->id)
            ->assertJsonPath('data.changes.added.config', json_encode(['type' => 'selenium']))
            ->assertJsonPath('data.changes.changed.description.from', 'Initial flow')
            ->assertJsonPath('data.changes.changed.description.to', 'Validated account flow');
    }

    public function test_asset_version_detail_rejects_cross_tenant_versions(): void
    {
        $otherCustomer = Costumer::forceCreate([
            'costumer' => 'Other customer',
            'description' => 'Other customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'other-api-key',
        ]);
        $otherProject = Project::forceCreate([
            'name' => 'Other project',
            'description' => 'Other project',
            'idCostumer' => $otherCustomer->id,
        ]);
        $foreignVersion = AssetVersion::forceCreate([
            'idCostumer' => $otherCustomer->id,
            'idProject' => $otherProject->id,
            'assetType' => 'step',
            'assetId' => 999,
            'version' => 1,
            'actorUserId' => null,
            'reason' => 'asset.created',
            'snapshot' => ['name' => 'Foreign step'],
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$foreignVersion->id
        )->assertNotFound();
    }

    public function test_asset_version_detail_rejects_cross_project_versions(): void
    {
        $otherProject = Project::forceCreate([
            'name' => 'Other project',
            'description' => 'Other project',
            'idCostumer' => $this->customer->id,
        ]);
        $otherProjectVersion = AssetVersion::forceCreate([
            'idCostumer' => $this->customer->id,
            'idProject' => $otherProject->id,
            'assetType' => 'step',
            'assetId' => 777,
            'version' => 1,
            'actorUserId' => null,
            'reason' => 'asset.created',
            'snapshot' => ['name' => 'Other project step'],
        ]);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$otherProjectVersion->id
        )->assertNotFound();
    }

    public function test_asset_version_review_transitions_are_recorded_and_audited(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        $step = $this->step(['name' => 'Reviewed step']);
        $version = $this->version($step, 1, [
            'name' => 'Reviewed step',
        ]);

        $this->postJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id.'/review-events',
            [
                'toStatus' => AssetVersionReviewEvent::STATUS_IN_REVIEW,
                'comment' => 'Ready for reviewer validation.',
            ]
        )->assertCreated()
            ->assertJsonPath('data.fromStatus', AssetVersionReviewEvent::STATUS_DRAFT)
            ->assertJsonPath('data.toStatus', AssetVersionReviewEvent::STATUS_IN_REVIEW)
            ->assertJsonPath('data.comment', 'Ready for reviewer validation.');

        $this->postJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id.'/review-events',
            [
                'toStatus' => AssetVersionReviewEvent::STATUS_APPROVED,
                'comment' => 'Approved for protected executions.',
            ]
        )->assertCreated()
            ->assertJsonPath('data.fromStatus', AssetVersionReviewEvent::STATUS_IN_REVIEW)
            ->assertJsonPath('data.toStatus', AssetVersionReviewEvent::STATUS_APPROVED);

        $this->getJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id
        )->assertOk()
            ->assertJsonPath('data.review.status', AssetVersionReviewEvent::STATUS_APPROVED)
            ->assertJsonPath('data.review.authorUserId', $this->user->id);

        $this->assertSame(2, AssetVersionReviewEvent::count());
        $this->assertSame(2, AuditEvent::where('action', 'asset_version.review_transitioned')->count());
    }

    public function test_asset_author_cannot_approve_own_version(): void
    {
        $author = $this->manager();
        $step = $this->step(['name' => 'Own version']);
        $version = $this->version($step, 1, [
            'name' => 'Own version',
        ], $author->id);

        $this->actingAs($author);
        $this->postJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id.'/review-events',
            ['toStatus' => AssetVersionReviewEvent::STATUS_IN_REVIEW]
        )->assertCreated();

        $this->postJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id.'/review-events',
            ['toStatus' => AssetVersionReviewEvent::STATUS_APPROVED]
        )->assertStatus(422)
            ->assertJsonPath('message', 'Asset authors cannot approve their own versions.');
    }

    public function test_read_only_role_cannot_transition_asset_review_state(): void
    {
        $step = $this->step(['name' => 'Read only']);
        $version = $this->version($step, 1, ['name' => 'Read only']);

        $this->postJson(
            '/api/admin/projects/'.$this->project->id.'/asset-versions/'.$version->id.'/review-events',
            ['toStatus' => AssetVersionReviewEvent::STATUS_IN_REVIEW]
        )->assertForbidden();
    }

    private function step(array $attributes = []): Step
    {
        return Step::forceCreate(array_merge([
            'name' => 'Step',
            'description' => 'Step',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
            'order' => 1,
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function version(
        Step $step,
        int $version,
        array $snapshot,
        ?int $actorUserId = null
    ): AssetVersion
    {
        return AssetVersion::forceCreate([
            'idCostumer' => $this->customer->id,
            'idProject' => $this->project->id,
            'assetType' => 'step',
            'assetId' => $step->id,
            'version' => $version,
            'actorUserId' => $actorUserId ?? $this->user->id,
            'reason' => $version === 1 ? 'asset.created' : 'asset.updated',
            'snapshot' => $snapshot,
        ]);
    }

    private function manager(): User
    {
        return User::forceCreate([
            'name' => 'Review manager',
            'role' => 2,
            'email' => 'review-manager-'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
    }
}
