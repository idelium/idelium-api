<?php

namespace Tests\Feature;

use App\Http\Middleware\CorrelateRequests;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditEventService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->firstCustomer = $this->createCustomer('first');
        $this->secondCustomer = $this->createCustomer('second');
    }

    public function test_tenant_switch_records_redacted_audit_event(): void
    {
        $superadmin = $this->createUser($this->firstCustomer, 1);
        $correlationId = '018fb9d0-1f16-7abc-9f2f-4e1d8457f001';

        $this->actingAs($superadmin)
            ->withHeader('Origin', 'https://localhost')
            ->withHeader(CorrelateRequests::HEADER, $correlationId)
            ->withSession([])
            ->putJson('/api/menu/header/'.$this->secondCustomer->id)
            ->assertOk()
            ->assertHeader(CorrelateRequests::HEADER, $correlationId);

        $event = AuditEvent::firstOrFail();

        $this->assertSame('tenant.switch', $event->action);
        $this->assertSame('costumer', $event->targetType);
        $this->assertSame((string) $this->secondCustomer->id, $event->targetId);
        $this->assertSame($superadmin->id, $event->actorUserId);
        $this->assertSame($this->firstCustomer->id, $event->actorTenantId);
        $this->assertSame($this->secondCustomer->id, $event->activeTenantId);
        $this->assertSame($correlationId, $event->correlationId);
        $this->assertSame('[REDACTED]', $event->afterValues['sessionToken']);
    }

    public function test_audit_events_are_append_only(): void
    {
        $event = AuditEvent::forceCreate([
            'actorUserId' => null,
            'actorTenantId' => $this->firstCustomer->id,
            'activeTenantId' => $this->firstCustomer->id,
            'action' => 'test.event',
            'targetType' => 'test',
            'targetId' => '1',
            'result' => AuditEvent::RESULT_SUCCESS,
            'sourceIp' => '127.0.0.1',
            'correlationId' => '018fb9d0-1f16-7abc-9f2f-4e1d8457f002',
        ]);

        $this->expectException(LogicException::class);
        $event->update(['result' => AuditEvent::RESULT_FAILURE]);
    }

    public function test_audit_events_cannot_be_deleted(): void
    {
        $event = AuditEvent::forceCreate([
            'actorUserId' => null,
            'actorTenantId' => $this->firstCustomer->id,
            'activeTenantId' => $this->firstCustomer->id,
            'action' => 'test.event',
            'targetType' => 'test',
            'targetId' => '1',
            'result' => AuditEvent::RESULT_SUCCESS,
            'sourceIp' => '127.0.0.1',
            'correlationId' => '018fb9d0-1f16-7abc-9f2f-4e1d8457f004',
        ]);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_audit_event_search_is_tenant_scoped_and_redacted(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $this->actingAs($admin);
        request()->attributes->set(
            ResolveTenantContext::ATTRIBUTE,
            TenantContext::forUser($admin)
        );

        app(AuditEventService::class)->record(
            request(),
            'secret.changed',
            'environment',
            '1',
            afterValues: [
                'name' => 'demo',
                'apiKey' => 'must-not-leak',
                'nested' => [
                    'password' => 'must-not-leak',
                ],
            ],
        );

        AuditEvent::forceCreate([
            'actorUserId' => null,
            'actorTenantId' => $this->secondCustomer->id,
            'activeTenantId' => $this->secondCustomer->id,
            'action' => 'secret.changed',
            'targetType' => 'environment',
            'targetId' => '2',
            'result' => AuditEvent::RESULT_SUCCESS,
            'sourceIp' => '127.0.0.1',
            'correlationId' => '018fb9d0-1f16-7abc-9f2f-4e1d8457f003',
        ]);

        $response = $this->withHeader('Origin', 'https://localhost')
            ->getJson('/api/audit-events?action=secret.changed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.afterValues.apiKey', '[REDACTED]')
            ->assertJsonPath('data.0.afterValues.nested.password', '[REDACTED]');

        $this->assertSame('1', $response->json('data.0.targetId'));
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

    private function createUser(Costumer $customer, int $role): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => uniqid('audit-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
