<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\ScimIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IdentityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Costumer $otherCustomer;

    private IdentityProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->customer = $this->customer('first');
        $this->otherCustomer = $this->customer('second');
        $this->provider = IdentityProvider::forceCreate([
            'idCostumer' => $this->customer->id,
            'type' => IdentityProvider::TYPE_SCIM,
            'name' => 'Okta',
            'groupRoleMap' => [
                'idelium-admins' => 2,
            ],
            'status' => IdentityProvider::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_registers_identity_provider_policy(): void
    {
        $admin = $this->user(2, $this->customer);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers', [
                'type' => 'oidc',
                'name' => 'Azure AD',
                'issuer' => 'https://login.example.test/tenant',
                'audience' => 'idelium-web',
                'redirectUris' => ['https://localhost/auth/callback'],
                'groupRoleMap' => ['qa-admins' => 2],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'oidc')
            ->assertJsonPath('data.groupRoleMap.qa-admins', 2);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'identity_provider.upsert',
            'targetType' => 'identity_provider',
        ]);
    }

    public function test_scim_user_upsert_is_idempotent_and_maps_groups_to_roles(): void
    {
        $admin = $this->user(2, $this->customer);
        $payload = [
            'externalId' => '00u123',
            'userName' => 'provisioned@example.test',
            'displayName' => 'Provisioned User',
            'active' => true,
            'groups' => ['idelium-admins'],
        ];

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers/'.$this->provider->id.'/scim/users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.role', 2)
            ->assertJsonPath('data.mfaRequired', true);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers/'.$this->provider->id.'/scim/users', array_merge($payload, [
                'displayName' => 'Provisioned User Updated',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Provisioned User Updated');

        $this->assertSame(1, User::where('email', 'provisioned@example.test')->count());
        $this->assertSame(1, ScimIdentity::where('externalId', '00u123')->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'scim_user.upsert',
            'targetType' => 'user',
        ]);
    }

    public function test_scim_deprovision_disables_user_and_is_tenant_scoped(): void
    {
        $admin = $this->user(2, $this->customer);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers/'.$this->provider->id.'/scim/users', [
                'externalId' => '00u456',
                'userName' => 'disabled@example.test',
                'displayName' => 'Disabled User',
                'active' => false,
                'groups' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.role', 3);

        $foreignAdmin = $this->user(2, $this->otherCustomer);
        $this->actingAs($foreignAdmin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers/'.$this->provider->id.'/scim/users', [
                'externalId' => '00u789',
                'userName' => 'foreign@example.test',
            ])
            ->assertNotFound();
    }

    public function test_break_glass_accounts_are_audited_and_excluded_from_scim(): void
    {
        $admin = $this->user(2, $this->customer);
        $breakGlass = $this->user(2, $this->customer, 'breakglass@example.test');

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/identity/accounts/'.$breakGlass->id.'/break-glass', [
                'enabled' => true,
                'reason' => 'Emergency tenant recovery.',
            ])
            ->assertOk()
            ->assertJsonPath('data.isBreakGlass', true);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/accounts/'.$breakGlass->id.'/break-glass/test')
            ->assertOk()
            ->assertJsonPath('data.isBreakGlass', true);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers/'.$this->provider->id.'/scim/users', [
                'externalId' => 'breakglass-1',
                'userName' => 'breakglass@example.test',
                'active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('userName');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'break_glass.update',
            'targetType' => 'user',
            'targetId' => (string) $breakGlass->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'break_glass.test',
            'targetType' => 'user',
            'targetId' => (string) $breakGlass->id,
        ]);
    }

    public function test_identity_management_is_deny_by_default(): void
    {
        $user = $this->user(3, $this->customer);

        $this->actingAs($user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/identity/providers', [
                'type' => 'scim',
                'name' => 'Blocked',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTHORIZATION_FORBIDDEN');
    }

    private function customer(string $prefix): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => ucfirst($prefix).' customer',
            'description' => ucfirst($prefix).' customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $prefix.'-api-key',
        ]);
    }

    private function user(int $role, Costumer $customer, ?string $email = null): User
    {
        return User::forceCreate([
            'name' => 'Identity user',
            'role' => $role,
            'email' => $email ?? uniqid('identity-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
