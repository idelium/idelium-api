<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionalMutationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = Costumer::forceCreate([
            'costumer' => 'First customer',
            'description' => 'First customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'first-customer-key',
        ]);
        $user = User::forceCreate([
            'name' => 'Test user',
            'role' => 3,
            'email' => 'user@example.test',
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
        $this->project = Project::forceCreate([
            'name' => 'PROJECT',
            'description' => 'Project',
            'idCostumer' => $this->customer->id,
        ]);
        Sanctum::actingAs($user);
    }

    public function test_step_reordering_rolls_back_after_a_failed_record_update(): void
    {
        $first = $this->createStep('First', 10);
        $second = $this->createStep('Second', 20);

        $this->postJson('/api/admin/steps/'.$this->project->id.'/updateorder', [
            'order' => [
                ['id' => $first->id],
                ['id' => 999999],
            ],
        ])->assertNotFound();

        $this->assertSame(10, $first->fresh()->order);
        $this->assertSame(20, $second->fresh()->order);
    }

    public function test_selenium_import_creates_all_records_together(): void
    {
        $this->postJson('/api/admin/importtest', [
            'name' => 'Imported test',
            'description' => 'Imported test',
            'idProject' => $this->project->id,
            'import' => json_encode([
                ['name' => 'Open page', 'command' => 'open'],
                ['name' => 'Check title', 'command' => 'assertTitle'],
            ]),
        ])->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseCount('steps', 2);
        $this->assertDatabaseHas('tests', [
            'name' => 'Imported test',
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_selenium_import_rejects_another_tenants_project(): void
    {
        $otherCustomer = Costumer::forceCreate([
            'costumer' => 'Other customer',
            'description' => 'Other customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'other-customer-key',
        ]);
        $otherProject = Project::forceCreate([
            'name' => 'OTHER',
            'description' => 'Other project',
            'idCostumer' => $otherCustomer->id,
        ]);

        $this->postJson('/api/admin/importtest', [
            'name' => 'Imported test',
            'description' => 'Imported test',
            'idProject' => $otherProject->id,
            'import' => json_encode([['name' => 'Open page']]),
        ])->assertNotFound();

        $this->assertDatabaseCount('steps', 0);
        $this->assertDatabaseCount('tests', 0);
    }

    public function test_project_deletion_removes_the_complete_result_hierarchy(): void
    {
        $step = $this->createStep('Recorded step', 1);
        $testId = DB::table('tests')->insertGetId([
            'name' => 'Recorded test',
            'description' => 'Recorded test',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
        $testCycleId = DB::table('test_cycles')->insertGetId([
            'name' => 'Recorded cycle',
            'description' => 'Recorded cycle',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
        $performedCycleId = DB::table('performed_test_cycles')->insertGetId([
            'testCycleId' => $testCycleId,
            'date' => now(),
            'status' => 0,
            'idCostumer' => $this->customer->id,
        ]);
        $performedTestId = DB::table('performed_tests')->insertGetId([
            'testCycleDoneId' => $performedCycleId,
            'testId' => $testId,
            'status' => 0,
            'name' => 'Recorded test',
            'idCostumer' => $this->customer->id,
        ]);
        DB::table('performed_steps')->insert([
            'testCycleDoneId' => $performedCycleId,
            'testDoneId' => $performedTestId,
            'stepId' => $step->id,
            'status' => 0,
            'name' => 'Recorded step',
            'screenshots' => json_encode([]),
            'data' => json_encode([]),
            'type' => 'selenium',
            'idCostumer' => $this->customer->id,
        ]);

        $this->deleteJson('/api/admin/projects/'.$this->project->id)->assertOk();

        foreach ([
            'projects',
            'steps',
            'tests',
            'test_cycles',
            'performed_test_cycles',
            'performed_tests',
            'performed_steps',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function createStep(string $name, int $order): Step
    {
        return Step::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
            'order' => $order,
        ]);
    }
}
