<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\GridQuerySnapshot;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GridBulkOperationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_snapshots_and_export_jobs_are_bounded_and_tenant_scoped(): void
    {
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        [$firstCustomer, $firstUser] = $this->createTenant('first');
        [$secondCustomer] = $this->createTenant('second');
        $firstProject = $this->createProject($firstCustomer, 'Checkout', '=Protected formula');
        $this->createProject($firstCustomer, 'Login', 'Login flow');
        $this->createProject($secondCustomer, 'Protected', 'Other tenant');
        Sanctum::actingAs($firstUser);

        $snapshotResponse = $this->postJson('/api/admin/grid/query-snapshots', [
            'resourceType' => 'projects',
            'query' => ['q' => 'Checkout', 'sort' => 'name', 'direction' => 'asc'],
        ])->assertCreated()
            ->assertJsonPath('data.total', 1);
        $snapshotId = $snapshotResponse->json('data.id');

        $this->assertDatabaseHas('grid_query_snapshots', [
            'id' => $snapshotId,
            'idCostumer' => $firstCustomer->id,
            'actorUserId' => $firstUser->id,
            'total' => 1,
        ]);
        $this->assertSame(
            [$firstProject->id],
            GridQuerySnapshot::findOrFail($snapshotId)->entityIds,
        );

        $jobResponse = $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $snapshotId,
            'action' => 'export',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processedCount', 1)
            ->assertJsonPath('data.failedCount', 0);
        $jobId = $jobResponse->json('data.id');

        $this->getJson("/api/admin/grid/bulk-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonMissing(['entityIds', 'query']);
        $export = $this->get("/api/admin/grid/bulk-jobs/{$jobId}/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=Protected formula", $export->streamedContent());
    }

    public function test_archive_and_tag_jobs_recheck_ownership_and_record_outcomes(): void
    {
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        [$customer, $user] = $this->createTenant('first');
        $first = $this->createProject($customer, 'First', 'First');
        $second = $this->createProject($customer, 'Second', 'Second');
        Sanctum::actingAs($user);

        $tagSnapshot = $this->snapshot(['sort' => 'id']);
        $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $tagSnapshot,
            'action' => 'tag',
            'payload' => ['tags' => ['critical', 'release-1']],
        ])->assertAccepted()
            ->assertJsonPath('data.processedCount', 2);
        $this->assertSame(['critical', 'release-1'], $first->fresh()->tags);

        $archiveSnapshot = $this->snapshot(['f' => ['id' => $second->id]]);
        $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $archiveSnapshot,
            'action' => 'archive',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($second->fresh()->archivedAt);
        $this->getJson('/api/admin/projects?page=1&pageSize=25')
            ->assertOk()
            ->assertJsonMissing(['id' => $second->id]);
        $this->assertDatabaseHas('audit_events', [
            'activeTenantId' => $customer->id,
            'action' => 'grid.bulk.archive',
        ]);
    }

    public function test_snapshots_jobs_and_exports_cannot_cross_tenants_or_actors(): void
    {
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        [$firstCustomer, $firstUser] = $this->createTenant('first');
        [, $secondUser] = $this->createTenant('second');
        $this->createProject($firstCustomer, 'First', 'First');
        Sanctum::actingAs($firstUser);
        $snapshotId = $this->snapshot([]);
        $jobId = $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $snapshotId,
            'action' => 'export',
        ])->json('data.id');

        Sanctum::actingAs($secondUser);
        $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $snapshotId,
            'action' => 'export',
        ])->assertNotFound();
        $this->getJson("/api/admin/grid/bulk-jobs/{$jobId}")->assertNotFound();
        $this->get("/api/admin/grid/bulk-jobs/{$jobId}/export")->assertNotFound();
    }

    public function test_expired_or_oversized_snapshots_fail_safely(): void
    {
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        [$customer, $user] = $this->createTenant('first');
        for ($index = 1; $index <= 1001; $index++) {
            $this->createProject(
                $customer,
                "Project {$index}",
                "Bounded project {$index}",
            );
        }
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/grid/query-snapshots', [
            'resourceType' => 'projects',
            'query' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'GRID_SNAPSHOT_TOO_LARGE')
            ->assertJsonPath('error.details.limit', 1000);

        Project::query()->where('idCostumer', $customer->id)->limit(2)->delete();
        $snapshotId = $this->snapshot([]);
        GridQuerySnapshot::where('id', $snapshotId)
            ->update(['expiresAt' => now()->subMinute()]);
        $this->postJson('/api/admin/grid/bulk-jobs', [
            'querySnapshotId' => $snapshotId,
            'action' => 'export',
        ])->assertNotFound();
    }

    private function snapshot(array $query): string
    {
        return $this->postJson('/api/admin/grid/query-snapshots', [
            'resourceType' => 'projects',
            'query' => $query,
        ])->assertCreated()->json('data.id');
    }

    private function createTenant(string $prefix): array
    {
        $customer = Costumer::forceCreate([
            'costumer' => ucfirst($prefix).' customer',
            'description' => ucfirst($prefix).' customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $prefix.'-api-key',
        ]);
        $user = User::forceCreate([
            'name' => ucfirst($prefix).' user',
            'role' => 2,
            'email' => $prefix.'@example.test',
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);

        return [$customer, $user];
    }

    private function createProject(
        Costumer $customer,
        string $name,
        string $description,
    ): Project {
        return Project::forceCreate([
            'name' => $name,
            'description' => $description,
            'idCostumer' => $customer->id,
        ]);
    }
}
