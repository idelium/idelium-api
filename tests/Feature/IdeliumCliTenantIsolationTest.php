<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Environment;
use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Plugin;
use App\Models\Project;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IdeliumCliTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCostumer;
    private Costumer $secondCostumer;
    private Project $firstProject;
    private Project $secondProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firstCostumer = $this->createCostumer('first-api-key', 'First customer');
        $this->secondCostumer = $this->createCostumer('second-api-key', 'Second customer');
        $this->firstProject = $this->createProject($this->firstCostumer, 'FIRST');
        $this->secondProject = $this->createProject($this->secondCostumer, 'SECOND');
    }

    public function test_cli_routes_reject_a_missing_or_invalid_api_key(): void
    {
        $this->getJson('/api/ideliumcl/testcycle/1')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid key']);

        $this->withHeader('Idelium-Key', 'invalid-api-key')
            ->postJson('/api/ideliumcl/testcycle', ['testCycleId' => 1])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid key']);
    }

    public function test_customer_cannot_read_another_customer_test_cycle(): void
    {
        $testCycle = TestCycle::forceCreate([
            'name' => 'Second customer cycle',
            'description' => 'A protected test cycle',
            'config' => json_encode([]),
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCostumer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/testcycle/'.$testCycle->id);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_can_read_own_test_cycle(): void
    {
        $testCycle = TestCycle::forceCreate([
            'name' => 'First customer cycle',
            'description' => 'An authorized test cycle',
            'config' => json_encode([]),
            'idProject' => $this->firstProject->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/testcycle/'.$testCycle->id);

        $response
            ->assertOk()
            ->assertJsonPath('id', $testCycle->id)
            ->assertJsonPath('idCostumer', $this->firstCostumer->id);
    }

    public function test_customer_cannot_read_another_customer_plugin(): void
    {
        $plugin = Plugin::forceCreate([
            'name' => 'Second customer plugin',
            'code' => json_encode([]),
            'description' => 'A protected plugin',
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCostumer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/plugin/'.$plugin->id);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_cannot_read_another_customer_test(): void
    {
        $test = $this->createTest($this->secondCostumer, 'Protected test');

        $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/test/'.$test->id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_cannot_read_another_customer_step(): void
    {
        $step = $this->createStep($this->secondCostumer, 'Protected step');

        $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/step/'.$step->id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_cannot_read_another_customer_environment(): void
    {
        $environment = $this->createEnvironment(
            $this->secondCostumer,
            'protected-environment'
        );

        $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/environment/'.$environment->id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_cannot_update_another_customer_performed_test(): void
    {
        $performedCycle = $this->createPerformedTestCycle($this->secondCostumer);
        $performedTest = $this->createPerformedTest(
            $this->secondCostumer,
            $performedCycle->id
        );

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->putJson('/api/ideliumcl/test', [
                'testId' => $performedTest->id,
                'status' => 1,
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseHas('performed_tests', [
            'id' => $performedTest->id,
            'status' => 0,
            'idCostumer' => $this->secondCostumer->id,
        ]);
    }

    public function test_customer_can_update_own_performed_test(): void
    {
        $performedCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $performedTest = $this->createPerformedTest(
            $this->firstCostumer,
            $performedCycle->id
        );

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->putJson('/api/ideliumcl/test', [
                'testId' => $performedTest->id,
                'status' => 1,
            ]);

        $response
            ->assertOk()
            ->assertExactJson(['idTest' => $performedTest->id]);
        $this->assertDatabaseHas('performed_tests', [
            'id' => $performedTest->id,
            'status' => 1,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_update_another_customer_performed_step(): void
    {
        $performedCycle = $this->createPerformedTestCycle($this->secondCostumer);
        $performedTest = $this->createPerformedTest(
            $this->secondCostumer,
            $performedCycle->id
        );
        $step = $this->createStep($this->secondCostumer, 'Second customer step');
        $performedStep = PerformedStep::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testDoneId' => $performedTest->id,
            'stepId' => $step->id,
            'status' => 0,
            'name' => 'Second customer step',
            'screenshots' => json_encode([]),
            'type' => 'selenium',
            'data' => json_encode([]),
            'idCostumer' => $this->secondCostumer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->putJson('/api/ideliumcl/step', [
                'stepId' => $performedStep->id,
                'screenshots' => ['unauthorized-change.png'],
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseHas('performed_steps', [
            'id' => $performedStep->id,
            'screenshots' => json_encode([]),
            'idCostumer' => $this->secondCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_performed_cycle_from_another_customer_cycle(): void
    {
        $testCycle = $this->createTestCycle($this->secondCostumer, 'Protected cycle');

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->postJson('/api/ideliumcl/testcycle', [
                'testCycleId' => $testCycle->id,
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_test_cycles', [
            'testCycleId' => $testCycle->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_test_under_another_customer_performed_cycle(): void
    {
        $performedTestCycle = $this->createPerformedTestCycle($this->secondCostumer);
        $test = $this->createTest($this->firstCostumer, 'Authorized test');

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->postJson('/api/ideliumcl/test', [
                'testCycleId' => $performedTestCycle->id,
                'testId' => $test->id,
                'name' => $test->name,
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_tests', [
            'testCycleDoneId' => $performedTestCycle->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_performed_test_from_another_customer_test(): void
    {
        $performedTestCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $test = $this->createTest($this->secondCostumer, 'Protected test');

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->postJson('/api/ideliumcl/test', [
                'testCycleId' => $performedTestCycle->id,
                'testId' => $test->id,
                'name' => $test->name,
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_tests', [
            'testId' => $test->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_step_under_another_customer_performed_test(): void
    {
        $performedTestCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $performedTest = $this->createPerformedTest(
            $this->secondCostumer,
            $performedTestCycle->id
        );
        $step = $this->createStep($this->firstCostumer, 'Authorized step');

        $response = $this->createPerformedStepRequest(
            $performedTestCycle,
            $performedTest,
            $step
        );

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_steps', [
            'testDoneId' => $performedTest->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_performed_step_from_another_customer_step(): void
    {
        $performedTestCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $performedTest = $this->createPerformedTest(
            $this->firstCostumer,
            $performedTestCycle->id
        );
        $step = $this->createStep($this->secondCostumer, 'Protected step');

        $response = $this->createPerformedStepRequest(
            $performedTestCycle,
            $performedTest,
            $step
        );

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_steps', [
            'stepId' => $step->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    public function test_customer_cannot_create_step_with_mismatched_performed_parents(): void
    {
        $firstPerformedCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $secondPerformedCycle = $this->createPerformedTestCycle($this->firstCostumer);
        $performedTest = $this->createPerformedTest(
            $this->firstCostumer,
            $firstPerformedCycle->id
        );
        $step = $this->createStep($this->firstCostumer, 'Authorized step');

        $response = $this->createPerformedStepRequest(
            $secondPerformedCycle,
            $performedTest,
            $step
        );

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid details']);
        $this->assertDatabaseMissing('performed_steps', [
            'testCycleDoneId' => $secondPerformedCycle->id,
            'testDoneId' => $performedTest->id,
        ]);
    }

    public function test_customer_can_create_a_complete_performed_result_hierarchy(): void
    {
        $testCycle = $this->createTestCycle($this->firstCostumer, 'Authorized cycle');
        $test = $this->createTest($this->firstCostumer, 'Authorized test');
        $step = $this->createStep($this->firstCostumer, 'Authorized step');

        $cycleResponse = $this->withHeader(
            'Idelium-Key',
            $this->firstCostumer->apiKey
        )->postJson('/api/ideliumcl/testcycle', [
            'testCycleId' => $testCycle->id,
        ])->assertOk();
        $performedCycleId = $cycleResponse->json('idCycle');

        $testResponse = $this->withHeader(
            'Idelium-Key',
            $this->firstCostumer->apiKey
        )->postJson('/api/ideliumcl/test', [
            'testCycleId' => $performedCycleId,
            'testId' => $test->id,
            'name' => $test->name,
        ])->assertOk();
        $performedTestId = $testResponse->json('idTest');

        $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $performedCycleId,
                'testId' => $performedTestId,
                'stepId' => $step->id,
                'name' => $step->name,
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode([]),
                'type' => 'selenium',
            ])->assertOk();

        $this->assertDatabaseHas('performed_test_cycles', [
            'id' => $performedCycleId,
            'testCycleId' => $testCycle->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
        $this->assertDatabaseHas('performed_tests', [
            'id' => $performedTestId,
            'testCycleDoneId' => $performedCycleId,
            'testId' => $test->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
        $this->assertDatabaseHas('performed_steps', [
            'testCycleDoneId' => $performedCycleId,
            'testDoneId' => $performedTestId,
            'stepId' => $step->id,
            'idCostumer' => $this->firstCostumer->id,
        ]);
    }

    private function createCostumer(string $apiKey, string $name): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => $name,
            'description' => $name,
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $apiKey,
        ]);
    }

    private function createTestCycle(Costumer $customer, string $name): TestCycle
    {
        return TestCycle::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $this->projectFor($customer)->id,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createTest(Costumer $customer, string $name): Test
    {
        return Test::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $this->projectFor($customer)->id,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createStep(Costumer $customer, string $name): Step
    {
        return Step::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $this->projectFor($customer)->id,
            'order' => 1,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createEnvironment(
        Costumer $customer,
        string $code
    ): Environment {
        return Environment::forceCreate([
            'code' => $code,
            'description' => $code,
            'config' => json_encode([]),
            'idProject' => $this->projectFor($customer)->id,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createPerformedTestCycle(Costumer $customer): PerformedTestCycle
    {
        $testCycle = $this->createTestCycle($customer, 'Performed cycle source');

        return PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 0,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createPerformedTest(
        Costumer $customer,
        int $performedTestCycleId
    ): PerformedTest {
        $test = $this->createTest($customer, 'Performed test source');

        return PerformedTest::forceCreate([
            'testCycleDoneId' => $performedTestCycleId,
            'testId' => $test->id,
            'status' => 0,
            'name' => 'Performed test',
            'idCostumer' => $customer->id,
        ]);
    }

    private function createProject(Costumer $customer, string $name): Project
    {
        return Project::forceCreate([
            'name' => $name,
            'description' => $name,
            'idCostumer' => $customer->id,
        ]);
    }

    private function projectFor(Costumer $customer): Project
    {
        return $customer->is($this->firstCostumer)
            ? $this->firstProject
            : $this->secondProject;
    }

    private function createPerformedStepRequest(
        PerformedTestCycle $performedTestCycle,
        PerformedTest $performedTest,
        Step $step
    ): TestResponse {
        return $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $performedTestCycle->id,
                'testId' => $performedTest->id,
                'stepId' => $step->id,
                'name' => $step->name,
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode([]),
                'type' => 'selenium',
            ]);
    }
}
