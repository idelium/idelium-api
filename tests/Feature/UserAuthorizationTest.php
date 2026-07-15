<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
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
        $this->firstCustomer = $this->createCustomer('First customer');
        $this->secondCustomer = $this->createCustomer('Second customer');
    }

    public function test_tenant_admin_cannot_create_a_superadmin(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2, 'admin@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Escalated user',
            'email' => 'escalated@example.test',
            'password' => 'StrongPassword123!',
            'role' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
    }

    public function test_tenant_admin_cannot_create_an_account_for_another_customer(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2, 'admin@example.test');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Other tenant user',
            'email' => 'other@example.test',
            'password' => 'StrongPassword123!',
            'role' => 3,
            'idCostumer' => $this->secondCustomer->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'other@example.test']);
    }

    public function test_tenant_admin_cannot_mutate_inaccessible_accounts(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2, 'admin@example.test');
        $otherUser = $this->createUser($this->secondCustomer, 3, 'other@example.test');
        $superadmin = $this->createUser($this->firstCustomer, 1, 'root@example.test');
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/accounts/'.$otherUser->id, [
            'name' => 'Changed',
            'password' => 'StrongPassword123!',
        ])->assertNotFound();
        $this->deleteJson('/api/admin/accounts/'.$superadmin->id)->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'name' => 'Test user']);
        $this->assertDatabaseHas('users', ['id' => $superadmin->id]);
    }

    public function test_regular_user_cannot_mutate_accounts(): void
    {
        $user = $this->createUser($this->firstCustomer, 3, 'user@example.test');
        $target = $this->createUser($this->firstCustomer, 3, 'target@example.test');
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Created',
            'email' => 'created@example.test',
            'password' => 'StrongPassword123!',
            'role' => 3,
        ])->assertForbidden();
        $this->putJson('/api/admin/accounts/'.$target->id, [
            'name' => 'Changed',
            'password' => 'StrongPassword123!',
        ])->assertForbidden();
        $this->deleteJson('/api/admin/accounts/'.$target->id)->assertForbidden();
    }

    public function test_superadmin_can_create_update_and_delete_accounts(): void
    {
        $superadmin = $this->createUser($this->firstCustomer, 1, 'root@example.test');
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Created user',
            'email' => 'created@example.test',
            'password' => 'StrongPassword123!',
            'role' => 3,
            'idCostumer' => $this->secondCustomer->id,
        ])->assertOk();

        $created = User::where('email', 'created@example.test')->firstOrFail();
        $this->putJson('/api/admin/accounts/'.$created->id, [
            'name' => 'Updated user',
            'password' => 'AnotherPassword123!',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $created->id, 'name' => 'Updated user']);

        $this->deleteJson('/api/admin/accounts/'.$created->id)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $created->id]);
    }

    public function test_role_and_customer_identifiers_are_validated(): void
    {
        $superadmin = $this->createUser($this->firstCustomer, 1, 'root@example.test');
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/admin/accounts', [
            'name' => 'Invalid user',
            'email' => 'invalid@example.test',
            'password' => 'StrongPassword123!',
            'role' => 999,
            'idCostumer' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role', 'idCostumer']);
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

    private function createUser(Costumer $customer, int $role, string $email): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
