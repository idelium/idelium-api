<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServiceAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Costumer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->customer = $this->createCustomer('first');
        $this->otherCustomer = $this->createCustomer('second');
    }

    public function test_admin_creates_service_account_with_one_time_secret_reveal(): void
    {
        $admin = $this->createUser(2, $this->customer);

        $response = $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/service-accounts', [
                'name' => 'CI runner',
                'scopes' => ['runs.launch'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'CI runner')
            ->assertJsonMissingPath('data.secretHash');

        $secret = $response->json('secret');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('idsa_', $secret);
        $event = AuditEvent::where('action', 'service_account.create')->firstOrFail();
        $this->assertSame('[REDACTED]', $event->afterValues['secret']);
        $this->assertSame('CI runner', $event->afterValues['name']);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson('/api/admin/service-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['secret' => $secret])
            ->assertJsonMissingPath('data.0.secretHash');
    }

    public function test_user_without_capability_cannot_create_service_accounts(): void
    {
        $user = $this->createUser(3, $this->customer);

        $this->actingAs($user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/service-accounts', [
                'name' => 'blocked',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTHORIZATION_FORBIDDEN');
    }

    public function test_revoke_is_tenant_scoped(): void
    {
        $admin = $this->createUser(2, $this->customer);
        $foreign = app(ServiceAccountService::class)->create(
            $this->otherCustomer->id,
            'foreign'
        )['serviceAccount'];

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/service-accounts/'.$foreign->id.'/revoke')
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revokedAt);
    }

    public function test_admin_can_revoke_own_tenant_service_account(): void
    {
        $admin = $this->createUser(2, $this->customer);
        $serviceAccount = app(ServiceAccountService::class)->create(
            $this->customer->id,
            'local'
        )['serviceAccount'];

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/service-accounts/'.$serviceAccount->id.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.id', $serviceAccount->id)
            ->assertJsonMissingPath('data.secretHash');

        $this->assertNotNull($serviceAccount->fresh()->revokedAt);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'service_account.revoke',
            'targetType' => 'service_account',
            'targetId' => (string) $serviceAccount->id,
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

    private function createUser(int $role, Costumer $customer): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => uniqid('service-account-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
