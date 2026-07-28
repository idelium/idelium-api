<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CapabilityAuthorizationTest extends TestCase
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

    public function test_capability_endpoint_returns_versioned_catalog_for_user(): void
    {
        $user = $this->createUser(3);

        $this->actingAs($user)
            ->withHeader('Origin', 'https://localhost')
            ->getJson('/api/me/capabilities')
            ->assertOk()
            ->assertJsonPath('version', config('capabilities.version'))
            ->assertJsonPath('capabilities.0', 'artifacts.read');
    }

    public function test_user_without_capability_receives_standard_forbidden_envelope(): void
    {
        $user = $this->createUser(3);

        $this->actingAs($user)
            ->withHeader('Origin', 'https://localhost')
            ->withSession([])
            ->putJson('/api/menu/header/'.$this->otherCustomer->id)
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'The requested action is not authorized.',
                'error' => [
                    'code' => 'AUTHORIZATION_FORBIDDEN',
                ],
            ]);
    }

    public function test_tenant_admin_cannot_manage_customers(): void
    {
        $admin = $this->createUser(2);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/costumers', [
                'costumer' => 'blocked',
                'description' => 'blocked',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTHORIZATION_FORBIDDEN');
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

    private function createUser(int $role): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => uniqid('capability-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
    }
}
