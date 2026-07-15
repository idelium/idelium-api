<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostmanIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_postman_result_is_available_to_its_tenant_only(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$firstCustomer, $firstUser] = $this->createTenant('first');
        [$secondCustomer, $secondUser] = $this->createTenant('second');
        $firstHierarchy = $this->createResultParents($firstCustomer);
        $secondHierarchy = $this->createResultParents($secondCustomer);

        $results = [[
            'name' => 'Echo request',
            'method' => 'POST',
            'url' => 'https://example.test/echo',
            'status' => '200',
            'time' => 0.12,
            'response' => '{"message":"ok"}',
            'passed' => true,
            'assertions' => [
                ['name' => 'status', 'passed' => true, 'message' => 'Status matched.'],
                ['name' => 'body', 'passed' => true, 'message' => 'Body matched.'],
            ],
        ]];

        $response = $this->withHeader('Idelium-Key', $firstCustomer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $firstHierarchy['performedCycle']->id,
                'testId' => $firstHierarchy['performedTest']->id,
                'stepId' => $firstHierarchy['step']->id,
                'name' => 'Postman collection',
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode($results),
                'type' => 'postman',
            ])->assertOk();

        Sanctum::actingAs($firstUser);
        $performedStep = $this->getJson(
            '/api/admin/stepsperfomed/'.$firstHierarchy['performedTest']->id
        )->assertOk()->assertJsonCount(1)->json('0');
        $this->assertSame('postman', $performedStep['type']);
        $this->assertSame($results, json_decode($performedStep['data'], true));
        $this->assertArrayNotHasKey('idCostumer', $performedStep);

        Sanctum::actingAs($secondUser);
        $this->getJson(
            '/api/admin/stepsperfomed/'.$firstHierarchy['performedTest']->id
        )->assertOk()->assertExactJson([]);
        $this->getJson(
            '/api/admin/testsperfomed/'.$firstHierarchy['test']->id
        )->assertOk()->assertExactJson([]);
        $this->getJson(
            '/api/admin/testcyclesperfomed/'.$firstHierarchy['testCycle']->id
        )->assertOk()->assertExactJson([]);

        $this->assertNotSame(
            $firstHierarchy['performedTest']->id,
            $secondHierarchy['performedTest']->id
        );
    }

    public function test_cli_rejects_invalid_postman_result_payloads(): void
    {
        [$customer] = $this->createTenant('first');
        $hierarchy = $this->createResultParents($customer);

        $this->withHeader('Idelium-Key', $customer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $hierarchy['performedCycle']->id,
                'testId' => $hierarchy['performedTest']->id,
                'stepId' => $hierarchy['step']->id,
                'name' => 'Postman collection',
                'status' => 1,
                'screenshots' => 'not-json',
                'data' => 'not-json',
                'type' => 'unknown',
            ])->assertUnprocessable()->assertJsonValidationErrors([
                'screenshots',
                'data',
                'type',
            ]);
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

    private function createResultParents(Costumer $customer): array
    {
        $project = Project::forceCreate([
            'name' => 'PROJECT '.$customer->id,
            'description' => 'Project',
            'idCostumer' => $customer->id,
        ]);
        $step = Step::forceCreate([
            'name' => 'Postman collection',
            'description' => 'Postman collection',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'order' => 1,
            'idCostumer' => $customer->id,
        ]);
        $test = Test::forceCreate([
            'name' => 'API test',
            'description' => 'API test',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'API cycle',
            'description' => 'API cycle',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $performedCycle = PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 0,
            'idCostumer' => $customer->id,
        ]);
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testId' => $test->id,
            'status' => 0,
            'name' => 'API test',
            'idCostumer' => $customer->id,
        ]);

        return compact(
            'step',
            'test',
            'testCycle',
            'performedCycle',
            'performedTest'
        );
    }
}
