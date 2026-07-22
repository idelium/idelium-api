<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TestToolResultContractTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    private User $firstUser;

    private User $secondUser;

    private array $firstHierarchy;

    private array $secondHierarchy;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$this->firstCustomer, $this->firstUser] = $this->createTenant('first');
        [$this->secondCustomer, $this->secondUser] = $this->createTenant('second');
        $this->firstHierarchy = $this->createResultParents($this->firstCustomer);
        $this->secondHierarchy = $this->createResultParents($this->secondCustomer);
    }

    public function test_accepts_versioned_newman_result_contract_and_redacts_sensitive_values(): void
    {
        $payload = [
            'runtime' => 'postman',
            'schemaVersion' => 'postman.newman.v1',
            'executions' => [[
                'name' => 'Create token',
                'url' => 'https://api.example.test/token?access_token=secret-token&safe=yes',
                'headers' => ['Authorization' => 'Bearer secret-token'],
                'response' => '{"token":"secret-token"}',
                'assertions' => [['name' => 'status', 'passed' => true]],
            ]],
            'scriptFailures' => [],
        ];

        $idStep = $this->postPerformedStep($payload, 'postman')->assertOk()->json('idStep');

        $stored = PerformedStep::findOrFail($idStep);
        $storedPayload = json_decode($stored->data, true);
        $this->assertSame('[REDACTED]', $storedPayload['executions'][0]['headers']['Authorization']);
        $this->assertSame('[REDACTED BODY]', $storedPayload['executions'][0]['response']);
        $this->assertSame(
            'https://api.example.test/token?access_token=%5BREDACTED%5D&safe=yes',
            $storedPayload['executions'][0]['url']
        );
    }

    public function test_accepts_versioned_selenium_and_appium_artifact_contracts(): void
    {
        $seleniumPayload = [
            'runtime' => 'selenium',
            'schemaVersion' => 'selenium.webdriver.v2',
            'commandTrace' => [['command' => 'click', 'status' => 'passed']],
            'artifacts' => [[
                'type' => 'screenshot',
                'storage' => 'external',
                'uri' => 'artifact://runs/1/screenshot.png',
            ]],
        ];
        $appiumPayload = [
            'runtime' => 'appium',
            'schemaVersion' => 'appium.v2',
            'commandTrace' => [['command' => 'tap', 'status' => 'passed']],
            'videos' => [[
                'storage' => 'external',
                'uri' => 'artifact://runs/1/video.mp4',
            ]],
        ];

        $this->postPerformedStep($seleniumPayload, 'selenium')->assertOk();
        $this->postPerformedStep($appiumPayload, 'seleniumOrAppium')->assertOk();
    }

    public function test_rejects_oversized_inline_artifacts(): void
    {
        $payload = [
            'runtime' => 'selenium',
            'schemaVersion' => 'selenium.webdriver.v2',
            'artifacts' => [[
                'type' => 'screenshot',
                'content' => str_repeat('A', 262145),
            ]],
        ];

        $this->postPerformedStep($payload, 'selenium')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data']);
    }

    public function test_accepts_inline_artifact_at_configured_boundary(): void
    {
        $payload = [
            'runtime' => 'selenium',
            'schemaVersion' => 'selenium.webdriver.v2',
            'artifacts' => [[
                'type' => 'screenshot',
                'content' => str_repeat('A', 262144),
            ]],
        ];

        $this->postPerformedStep($payload, 'selenium')->assertOk();
    }

    public function test_rejects_too_many_artifact_references(): void
    {
        $payload = [
            'runtime' => 'selenium',
            'schemaVersion' => 'selenium.webdriver.v2',
            'artifacts' => array_fill(0, 51, [
                'type' => 'log',
                'storage' => 'external',
                'uri' => 'artifact://runs/1/log.txt',
            ]),
        ];

        $this->postPerformedStep($payload, 'selenium')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data']);
    }

    public function test_redacts_existing_legacy_result_payloads_during_tenant_scoped_reads(): void
    {
        $performedStep = PerformedStep::forceCreate([
            'testCycleDoneId' => $this->firstHierarchy['performedCycle']->id,
            'testDoneId' => $this->firstHierarchy['performedTest']->id,
            'stepId' => $this->firstHierarchy['step']->id,
            'status' => 1,
            'name' => 'Legacy result',
            'screenshots' => json_encode([]),
            'type' => 'postman',
            'data' => json_encode([[
                'url' => 'https://api.example.test?api_key=secret',
                'response' => '{"password":"secret"}',
                'headers' => ['Cookie' => 'session=secret'],
            ]]),
            'idCostumer' => $this->firstCustomer->id,
        ]);

        Sanctum::actingAs($this->firstUser);
        $payload = $this->getJson('/api/admin/stepsperfomed/'.$this->firstHierarchy['performedTest']->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->json('0.data');

        $decoded = json_decode($payload, true);
        $this->assertSame('[REDACTED BODY]', $decoded[0]['response']);
        $this->assertSame('[REDACTED]', $decoded[0]['headers']['Cookie']);
        $this->assertStringContainsString('api_key=%5BREDACTED%5D', $decoded[0]['url']);

        Sanctum::actingAs($this->secondUser);
        $this->getJson('/api/admin/stepsperfomed/'.$this->firstHierarchy['performedTest']->id)
            ->assertOk()
            ->assertExactJson([]);

        $this->assertDatabaseHas('performed_steps', [
            'id' => $performedStep->id,
            'idCostumer' => $this->firstCustomer->id,
        ]);
    }

    private function postPerformedStep(array $payload, string $type)
    {
        return $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $this->firstHierarchy['performedCycle']->id,
                'testId' => $this->firstHierarchy['performedTest']->id,
                'stepId' => $this->firstHierarchy['step']->id,
                'name' => 'Runtime result',
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode($payload),
                'type' => $type,
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
            'name' => 'Runtime step',
            'description' => 'Runtime step',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'order' => 1,
            'idCostumer' => $customer->id,
        ]);
        $test = Test::forceCreate([
            'name' => 'Runtime test',
            'description' => 'Runtime test',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'Runtime cycle',
            'description' => 'Runtime cycle',
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
            'name' => 'Runtime test',
            'idCostumer' => $customer->id,
        ]);

        return compact('step', 'performedCycle', 'performedTest');
    }
}
