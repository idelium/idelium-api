<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\Plugin;
use App\Models\TestCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeliumCliTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCostumer;
    private Costumer $secondCostumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firstCostumer = $this->createCostumer('first-api-key', 'First customer');
        $this->secondCostumer = $this->createCostumer('second-api-key', 'Second customer');
    }

    public function test_customer_cannot_read_another_customer_test_cycle(): void
    {
        $testCycle = TestCycle::forceCreate([
            'name' => 'Second customer cycle',
            'description' => 'A protected test cycle',
            'config' => json_encode([]),
            'idProject' => 1,
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
            'idProject' => 1,
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
            'idProject' => 1,
            'idCostumer' => $this->secondCostumer->id,
        ]);

        $response = $this->withHeader('Idelium-Key', $this->firstCostumer->apiKey)
            ->getJson('/api/ideliumcl/plugin/'.$plugin->id);

        $response
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid id']);
    }

    public function test_customer_cannot_update_another_customer_performed_test(): void
    {
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => 1,
            'testId' => 1,
            'status' => 0,
            'name' => 'Second customer test',
            'idCostumer' => $this->secondCostumer->id,
        ]);

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
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => 1,
            'testId' => 1,
            'status' => 0,
            'name' => 'First customer test',
            'idCostumer' => $this->firstCostumer->id,
        ]);

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
        $performedStep = PerformedStep::forceCreate([
            'testCycleDoneId' => 1,
            'testDoneId' => 1,
            'stepId' => 1,
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
}
