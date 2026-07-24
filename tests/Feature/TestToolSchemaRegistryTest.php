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
use App\Services\TestToolSchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TestToolSchemaRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = $this->createCustomer('Schema customer');
        $this->project = Project::forceCreate([
            'name' => 'SCHEMA',
            'description' => 'Schema project',
            'idCostumer' => $this->customer->id,
        ]);

        Sanctum::actingAs($this->createUser($this->customer));
    }

    public function test_registry_contains_supported_runtime_schema_ids(): void
    {
        $registry = app(TestToolSchemaRegistry::class);

        $this->assertSame([
            'selenium.v1',
            'selenium.webdriver.v2',
            'selenium.bidi.diagnostics.v1',
            'appium.v2',
            'postman.safe.v1',
            'postman.newman.v1',
            'dsl.source.v1',
            'dsl.ast.v1',
        ], $registry->supportedSchemaIds());
    }

    public function test_customer_can_store_versioned_step_payload(): void
    {
        $payload = [
            'runtime' => 'selenium',
            'schemaVersion' => 'selenium.webdriver.v2',
            'command' => 'click',
            'target' => ['strategy' => 'css', 'value' => '#submit'],
        ];

        $this->postJson('/api/admin/steps', [
            'name' => 'Click submit',
            'description' => 'Click submit',
            'config' => json_encode($payload),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['name' => 'Click submit']);

        $this->assertDatabaseHas('steps', [
            'name' => 'Click submit',
            'config' => json_encode($payload),
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_unknown_step_schema_is_rejected(): void
    {
        $this->postJson('/api/admin/steps', [
            'name' => 'Unsupported command',
            'description' => 'Unsupported command',
            'config' => json_encode([
                'runtime' => 'selenium',
                'schemaVersion' => 'selenium.experimental.v9',
            ]),
            'idProject' => $this->project->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['config']);

        $this->assertDatabaseMissing('steps', [
            'name' => 'Unsupported command',
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_versioned_payload_rejects_fields_outside_declared_schema(): void
    {
        $hierarchy = $this->createResultParents();

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $hierarchy['performedCycle']->id,
                'testId' => $hierarchy['performedTest']->id,
                'stepId' => $hierarchy['step']->id,
                'name' => 'BiDi diagnostics',
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode([
                    'runtime' => 'selenium',
                    'schemaVersion' => 'selenium.bidi.diagnostics.v1',
                    'artifacts' => [],
                    'rawSessionDump' => ['unexpected' => true],
                ]),
                'type' => 'selenium',
            ])->assertUnprocessable()->assertJsonValidationErrors(['data']);
    }

    public function test_versioned_appium_environment_payload_requires_runtime_shape(): void
    {
        $this->postJson('/api/admin/environments', [
            'code' => 'mobile',
            'description' => 'Mobile environment',
            'config' => json_encode([
                'runtime' => 'appium',
                'schemaVersion' => 'appium.v2',
                'irrelevant' => true,
            ]),
            'idProject' => $this->project->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['config']);

        $this->postJson('/api/admin/environments', [
            'code' => 'mobile-valid',
            'description' => 'Mobile environment',
            'config' => json_encode([
                'runtime' => 'appium',
                'schemaVersion' => 'appium.v2',
                'appiumServer' => 'http://127.0.0.1:4723',
                'appiumDesiredCaps' => ['platformName' => 'iOS'],
            ]),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['code' => 'mobile-valid']);
    }

    public function test_versioned_postman_newman_step_payload_is_accepted(): void
    {
        $payload = [
            'runtime' => 'postman',
            'schemaVersion' => 'postman.newman.v1',
            'collectionPath' => 'collections/customer-api.postman_collection.json',
        ];

        $this->postJson('/api/admin/steps', [
            'name' => 'Newman collection',
            'description' => 'Newman collection',
            'config' => json_encode($payload),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['name' => 'Newman collection']);
    }

    public function test_versioned_dsl_source_step_payload_is_accepted(): void
    {
        $payload = [
            'runtime' => 'dsl',
            'schemaVersion' => 'dsl.source.v1',
            'languageVersion' => '1.0',
            'source' => "idelium 1.0\n\ntest \"Login\" {\n    open \"https://example.invalid\"\n}\n",
        ];

        $this->postJson('/api/admin/steps', [
            'name' => 'DSL source',
            'description' => 'DSL source',
            'config' => json_encode($payload),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['name' => 'DSL source']);

        $this->assertDatabaseHas('steps', [
            'name' => 'DSL source',
            'config' => json_encode($payload),
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_versioned_dsl_ast_step_payload_is_accepted(): void
    {
        $payload = [
            'runtime' => 'dsl',
            'schemaVersion' => 'dsl.ast.v1',
            'languageVersion' => '1.0',
            'ast' => [
                'kind' => 'document',
                'schemaVersion' => '1.0',
                'languageVersion' => '1.0',
                'tests' => [[
                    'kind' => 'test',
                    'name' => 'Login',
                    'statements' => [[
                        'kind' => 'open',
                        'url' => 'https://example.invalid',
                    ]],
                ]],
            ],
        ];

        $this->postJson('/api/admin/steps', [
            'name' => 'DSL AST',
            'description' => 'DSL AST',
            'config' => json_encode($payload),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['name' => 'DSL AST']);
    }

    public function test_malformed_or_unsupported_dsl_step_payloads_are_rejected(): void
    {
        $payloads = [
            'missing source' => [
                'runtime' => 'dsl',
                'schemaVersion' => 'dsl.source.v1',
                'languageVersion' => '1.0',
                'source' => '',
            ],
            'future source language' => [
                'runtime' => 'dsl',
                'schemaVersion' => 'dsl.source.v1',
                'languageVersion' => '2.0',
                'source' => 'idelium 2.0',
            ],
            'invalid ast document' => [
                'runtime' => 'dsl',
                'schemaVersion' => 'dsl.ast.v1',
                'languageVersion' => '1.0',
                'ast' => [
                    'kind' => 'document',
                    'schemaVersion' => '1.0',
                    'languageVersion' => '1.0',
                    'tests' => [],
                ],
            ],
            'unsupported top-level field' => [
                'runtime' => 'dsl',
                'schemaVersion' => 'dsl.ast.v1',
                'languageVersion' => '1.0',
                'source' => 'idelium 1.0',
                'ast' => [
                    'kind' => 'document',
                    'schemaVersion' => '1.0',
                    'languageVersion' => '1.0',
                    'tests' => [['kind' => 'test', 'name' => 'Login', 'statements' => []]],
                ],
            ],
        ];

        foreach ($payloads as $case => $payload) {
            $this->postJson('/api/admin/steps', [
                'name' => 'Invalid DSL '.$case,
                'description' => 'Invalid DSL',
                'config' => json_encode($payload),
                'idProject' => $this->project->id,
            ])->assertUnprocessable()->assertJsonValidationErrors(['config']);
        }
    }

    public function test_customer_cannot_store_dsl_step_payload_under_another_tenant_project(): void
    {
        $otherCustomer = $this->createCustomer('Other customer');
        $otherProject = Project::forceCreate([
            'name' => 'OTHER',
            'description' => 'Other project',
            'idCostumer' => $otherCustomer->id,
        ]);

        $this->postJson('/api/admin/steps', [
            'name' => 'Cross tenant DSL',
            'description' => 'Cross tenant DSL',
            'config' => json_encode([
                'runtime' => 'dsl',
                'schemaVersion' => 'dsl.source.v1',
                'languageVersion' => '1.0',
                'source' => "idelium 1.0\n\ntest \"Login\" {}\n",
            ]),
            'idProject' => $otherProject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['idProject']);
    }

    public function test_legacy_environment_payload_without_schema_metadata_is_accepted(): void
    {
        $this->postJson('/api/admin/environments', [
            'code' => 'local',
            'description' => 'Local environment',
            'config' => json_encode(['baseUrl' => 'https://example.test']),
            'idProject' => $this->project->id,
        ])->assertOk()->assertJsonFragment(['code' => 'local']);
    }

    public function test_cli_result_payload_rejects_unknown_schema(): void
    {
        $hierarchy = $this->createResultParents();

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $hierarchy['performedCycle']->id,
                'testId' => $hierarchy['performedTest']->id,
                'stepId' => $hierarchy['step']->id,
                'name' => 'Newman collection',
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode([
                    'runtime' => 'postman',
                    'schemaVersion' => 'postman.future.v9',
                    'executions' => [],
                ]),
                'type' => 'postman',
            ])->assertUnprocessable()->assertJsonValidationErrors(['data']);
    }

    private function createCustomer(string $name): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => $name,
            'description' => $name,
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => (string) str($name)->slug().'-api-key',
        ]);
    }

    private function createUser(Costumer $customer): User
    {
        return User::forceCreate([
            'name' => 'Schema user',
            'role' => 3,
            'email' => 'schema-'.$customer->id.'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }

    private function createResultParents(): array
    {
        $step = Step::forceCreate([
            'name' => 'Newman collection',
            'description' => 'Newman collection',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'order' => 1,
            'idCostumer' => $this->customer->id,
        ]);
        $test = Test::forceCreate([
            'name' => 'API test',
            'description' => 'API test',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'API cycle',
            'description' => 'API cycle',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
        $performedCycle = PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 0,
            'idCostumer' => $this->customer->id,
        ]);
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testId' => $test->id,
            'status' => 0,
            'name' => 'API test',
            'idCostumer' => $this->customer->id,
        ]);

        return compact('step', 'performedCycle', 'performedTest');
    }
}
