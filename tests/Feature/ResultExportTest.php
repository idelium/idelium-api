<?php

namespace Tests\Feature;

use App\Jobs\GenerateResultExportJob;
use App\Models\Costumer;
use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Models\User;
use App\Services\ResultExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResultExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_and_download_a_result_export_descriptor(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user] = $this->createTenant('first');
        $hierarchy = $this->createPerformedHierarchy($customer);

        Sanctum::actingAs($user);
        Queue::fake();

        $descriptor = $this->postJson('/api/admin/result-exports', [
            'performedTestCycleId' => $hierarchy['performedCycle']->id,
            'format' => 'json',
        ])->assertAccepted()
            ->assertJsonPath('format', 'json')
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('ready', false)
            ->json();

        Queue::assertPushed(
            GenerateResultExportJob::class,
            fn (GenerateResultExportJob $job): bool => $job->resultExportId() === $descriptor['id']
        );

        $this->getJson('/api/admin/result-exports/'.$descriptor['id'])
            ->assertOk()
            ->assertJsonPath('id', $descriptor['id'])
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('url', $descriptor['url']);

        $this->get($descriptor['url'])
            ->assertStatus(409);

        (new GenerateResultExportJob($descriptor['id']))->handle(app(ResultExportService::class));

        $this->getJson('/api/admin/result-exports/'.$descriptor['id'])
            ->assertOk()
            ->assertJsonPath('id', $descriptor['id'])
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('ready', true);

        $this->get($descriptor['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertSee('result-export.v1')
            ->assertSee('Exported API test');
    }

    public function test_customer_cannot_read_or_download_another_tenants_export(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$firstCustomer, $firstUser] = $this->createTenant('first');
        [, $secondUser] = $this->createTenant('second');
        $hierarchy = $this->createPerformedHierarchy($firstCustomer);

        Sanctum::actingAs($firstUser);
        Queue::fake();
        $descriptor = $this->postJson('/api/admin/result-exports', [
            'performedTestCycleId' => $hierarchy['performedCycle']->id,
            'format' => 'markdown',
        ])->assertAccepted()->json();

        Sanctum::actingAs($secondUser);
        $this->getJson('/api/admin/result-exports/'.$descriptor['id'])
            ->assertNotFound();
        $this->get($descriptor['url'])
            ->assertNotFound();
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
            'role' => 3,
            'email' => $prefix.'@example.test',
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);

        return [$customer, $user];
    }

    private function createPerformedHierarchy(Costumer $customer): array
    {
        $project = Project::forceCreate([
            'name' => 'PROJECT '.$customer->id,
            'description' => 'Project',
            'idCostumer' => $customer->id,
        ]);
        $step = Step::forceCreate([
            'name' => 'Exported step',
            'description' => 'Exported step',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'order' => 1,
            'idCostumer' => $customer->id,
        ]);
        $test = Test::forceCreate([
            'name' => 'Exported API test',
            'description' => 'Exported API test',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'Exported cycle',
            'description' => 'Exported cycle',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $performedCycle = PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 1,
            'idCostumer' => $customer->id,
        ]);
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testId' => $test->id,
            'status' => 1,
            'name' => 'Exported API test',
            'idCostumer' => $customer->id,
        ]);
        $performedStep = PerformedStep::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testDoneId' => $performedTest->id,
            'stepId' => $step->id,
            'name' => 'Exported step',
            'status' => 1,
            'screenshots' => json_encode([]),
            'data' => json_encode([]),
            'type' => 'postman',
            'idCostumer' => $customer->id,
        ]);

        return compact(
            'performedCycle',
            'performedStep',
            'performedTest',
            'project',
            'step',
            'test',
            'testCycle'
        );
    }
}
