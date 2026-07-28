<?php

namespace Tests\Feature;

use App\Jobs\DispatchIntegrationDeliveryJob;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationEndpoint;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\IntegrationEndpointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Costumer $otherCustomer;

    private Project $project;

    private Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->customer = $this->createCustomer('first');
        $this->otherCustomer = $this->createCustomer('second');
        $this->project = $this->createProject($this->customer, 'Primary project');
        $this->otherProject = $this->createProject($this->otherCustomer, 'Foreign project');
    }

    public function test_admin_creates_signed_webhook_endpoint_without_returning_secret(): void
    {
        $admin = $this->createUser(2, $this->customer);

        $response = $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/projects/'.$this->project->id.'/integrations', [
                'name' => 'Release events',
                'adapter' => 'webhook',
                'url' => 'https://93.184.216.34/hooks/idelium',
                'secret' => 'super-secret-value',
                'events' => ['test.completed'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Release events')
            ->assertJsonPath('data.secretConfigured', true)
            ->assertJsonMissingPath('data.secret')
            ->assertJsonMissingPath('data.secretEncrypted');

        $endpoint = IntegrationEndpoint::firstOrFail();
        $this->assertSame('super-secret-value', Crypt::decryptString($endpoint->secretEncrypted));
        $this->assertStringNotContainsString('super-secret-value', json_encode($response->json()));

        $event = AuditEvent::where('action', 'integration_endpoint.create')->firstOrFail();
        $this->assertSame('[REDACTED]', $event->afterValues['secret']);
    }

    public function test_ssrf_destinations_are_rejected_before_persistence(): void
    {
        $admin = $this->createUser(2, $this->customer);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/projects/'.$this->project->id.'/integrations', [
                'name' => 'Metadata service',
                'adapter' => 'webhook',
                'url' => 'http://127.0.0.1/latest/meta-data',
                'secret' => 'super-secret-value',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, IntegrationEndpoint::count());
    }

    public function test_tenant_scoped_test_delivery_is_queued_and_audited(): void
    {
        Queue::fake();
        $admin = $this->createUser(2, $this->customer);
        $endpoint = $this->endpoint('slack');

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/projects/'.$this->project->id.'/integrations/'.$endpoint->id.'/test')
            ->assertAccepted()
            ->assertJsonPath('data.status', IntegrationDelivery::STATUS_PENDING);

        Queue::assertPushed(
            DispatchIntegrationDeliveryJob::class,
            fn (DispatchIntegrationDeliveryJob $job): bool => $job->integrationDeliveryId() === IntegrationDelivery::firstOrFail()->id
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'integration_delivery.test',
            'targetType' => 'integration_delivery',
        ]);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/projects/'.$this->otherProject->id.'/integrations/'.$endpoint->id.'/test')
            ->assertNotFound();
    }

    public function test_disablement_secret_rotation_and_dead_letter_listing_are_tenant_scoped_and_audited(): void
    {
        $admin = $this->createUser(2, $this->customer);
        $endpoint = $this->endpoint('teams');
        $delivery = IntegrationDelivery::forceCreate([
            'idCostumer' => $this->customer->id,
            'idProject' => $this->project->id,
            'integrationEndpointId' => $endpoint->id,
            'event' => 'test.failed',
            'deliveryId' => 'idwh_deadletter',
            'idempotencyKey' => 'deadletter:1',
            'schemaVersion' => config('integrations.schema_version'),
            'payloadDigest' => str_repeat('a', 64),
            'status' => IntegrationDelivery::STATUS_DEAD_LETTER,
            'attempts' => 3,
            'payload' => ['status' => 'failed'],
        ]);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/projects/'.$this->project->id.'/integrations/'.$endpoint->id.'/status', [
                'status' => IntegrationEndpoint::STATUS_DISABLED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', IntegrationEndpoint::STATUS_DISABLED);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/projects/'.$this->project->id.'/integrations/'.$endpoint->id.'/rotate-secret', [
                'secret' => 'new-super-secret-value',
            ])
            ->assertOk()
            ->assertJsonPath('data.secretConfigured', true)
            ->assertJsonMissingPath('data.secret');

        $endpoint->refresh();
        $this->assertSame('new-super-secret-value', Crypt::decryptString($endpoint->secretEncrypted));
        $this->assertSame(
            '[REDACTED]',
            AuditEvent::where('action', 'integration_endpoint.rotate_secret')->firstOrFail()->afterValues['secret']
        );

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson('/api/admin/projects/'.$this->project->id.'/integration-deliveries?status=dead_letter')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deliveryId', $delivery->deliveryId);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson('/api/admin/projects/'.$this->otherProject->id.'/integration-deliveries?status=dead_letter')
            ->assertNotFound();
    }

    public function test_delivery_job_signs_payload_and_marks_delivery_sent(): void
    {
        $endpoint = $this->endpoint('webhook');
        $delivery = app(IntegrationEndpointService::class)->createDelivery(
            $endpoint,
            'test.completed',
            ['result' => 'passed'],
            'test.completed:1',
            false,
        );

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        (new DispatchIntegrationDeliveryJob($delivery->id))->handle(app(IntegrationEndpointService::class));

        $delivery->refresh();
        $this->assertSame(IntegrationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->responseStatus);
        Http::assertSent(function (HttpClientRequest $request) use ($delivery): bool {
            $signature = $request->header('Idelium-Signature')[0] ?? '';
            $this->assertMatchesRegularExpression('/^t=\d+,v1=[a-f0-9]{64}$/', $signature);
            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $matches);
            $expected = hash_hmac('sha256', $matches[1].'.'.$request->body(), 'super-secret-value');

            $this->assertSame($expected, $matches[2]);
            $this->assertSame($delivery->deliveryId, $request->header('Idelium-Delivery-Id')[0] ?? null);
            $this->assertSame('test.completed', $request->header('Idelium-Event')[0] ?? null);

            return true;
        });
        $this->assertDatabaseHas('audit_events', [
            'action' => 'integration_delivery.dispatch',
            'targetId' => (string) $delivery->id,
            'result' => AuditEvent::RESULT_SUCCESS,
        ]);
    }

    public function test_failed_delivery_enters_dead_letter_after_bounded_attempts(): void
    {
        config(['integrations.max_attempts' => 1]);
        $endpoint = $this->endpoint('jira');
        $delivery = app(IntegrationEndpointService::class)->createDelivery(
            $endpoint,
            'test.failed',
            ['error' => 'Timeout'],
            'test.failed:1',
            false,
        );

        Http::fake([
            '*' => Http::response(['error' => 'blocked'], 500),
        ]);

        (new DispatchIntegrationDeliveryJob($delivery->id))->handle(app(IntegrationEndpointService::class));

        $delivery->refresh();
        $this->assertSame(IntegrationDelivery::STATUS_DEAD_LETTER, $delivery->status);
        $this->assertSame(500, $delivery->responseStatus);
        $this->assertNull($delivery->nextAttemptAt);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'integration_delivery.dispatch',
            'targetId' => (string) $delivery->id,
            'result' => AuditEvent::RESULT_FAILURE,
        ]);
    }

    public function test_slack_adapter_wraps_the_versioned_canonical_payload(): void
    {
        $endpoint = $this->endpoint('slack');
        $delivery = app(IntegrationEndpointService::class)->createDelivery(
            $endpoint,
            'test.completed',
            ['status' => 'passed'],
            'test.completed:slack',
            false,
        );

        $payload = app(IntegrationEndpointService::class)->adapterPayload($endpoint, $delivery, $delivery->payload);

        $this->assertSame('[Idelium] test.completed', $payload['text']);
        $this->assertSame('test.completed', $payload['idelium']['event']);
        $this->assertSame(config('integrations.schema_version'), $payload['idelium']['schemaVersion']);
        $this->assertSame($this->project->id, $payload['idelium']['projectId']);
    }

    private function endpoint(string $adapter): IntegrationEndpoint
    {
        return app(IntegrationEndpointService::class)->create($this->customer->id, $this->project->id, [
            'name' => ucfirst($adapter).' endpoint',
            'adapter' => $adapter,
            'url' => 'https://93.184.216.34/hooks/'.$adapter,
            'secret' => 'super-secret-value',
            'events' => ['*'],
        ]);
    }

    private function createCustomer(string $prefix): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => ucfirst($prefix).' customer',
            'description' => ucfirst($prefix).' customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $prefix.'-api-key',
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

    private function createUser(int $role, Costumer $customer): User
    {
        return User::forceCreate([
            'name' => 'Integration admin',
            'role' => $role,
            'email' => uniqid('integration-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
