<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserResponseSecurityTest extends TestCase
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

    public function test_superadmin_account_list_uses_the_explicit_safe_contract(): void
    {
        $superadmin = $this->createUser(
            $this->firstCustomer,
            1,
            'superadmin@example.test'
        );
        $this->createUser($this->firstCustomer, 2, 'admin@example.test');
        $this->createUser($this->secondCustomer, 3, 'user@example.test');
        Sanctum::actingAs($superadmin);

        $accounts = $this->assertSafeAccountResponse(
            $this->getJson('/api/admin/accounts')
        );

        $this->assertCount(3, $accounts);
        $this->assertSame([
            'admin@example.test',
            'superadmin@example.test',
            'user@example.test',
        ], array_column($accounts, 'email'));
    }

    public function test_tenant_admin_only_receives_accounts_from_own_customer(): void
    {
        $tenantAdmin = $this->createUser(
            $this->firstCustomer,
            2,
            'admin@example.test'
        );
        $this->createUser($this->firstCustomer, 3, 'user@example.test');
        $this->createUser($this->secondCustomer, 2, 'other-admin@example.test');
        $this->createUser($this->secondCustomer, 3, 'other-user@example.test');
        $this->createUser($this->firstCustomer, 1, 'superadmin@example.test');
        Sanctum::actingAs($tenantAdmin);

        $accounts = $this->assertSafeAccountResponse(
            $this->getJson('/api/admin/accounts')
        );

        $this->assertSame([
            'admin@example.test',
            'user@example.test',
        ], array_column($accounts, 'email'));
        foreach ($accounts as $account) {
            $this->assertSame($this->firstCustomer->id, $account['idCostumer']);
            $this->assertGreaterThan(1, $account['role']);
        }
    }

    public function test_profile_response_uses_an_explicit_safe_contract(): void
    {
        $user = $this->createUser(
            $this->firstCustomer,
            3,
            'profile@example.test'
        );
        Sanctum::actingAs($user);

        $expectedProfile = [
            'email' => 'profile@example.test',
            'name' => 'Test user',
            'companyName' => 'First customer',
            'roleName' => 'user',
        ];

        $this->getJson('/api/admin/profile')
            ->assertOk()
            ->assertExactJson($expectedProfile);

        $this->putJson('/api/admin/profile', [
            'password' => 'UpdatedPassword123!',
        ])->assertOk()->assertExactJson($expectedProfile);
    }

    public function test_account_mutations_keep_the_same_safe_response_contract(): void
    {
        $superadmin = $this->createUser(
            $this->firstCustomer,
            1,
            'superadmin@example.test'
        );
        Sanctum::actingAs($superadmin);

        $createResponse = $this->postJson('/api/admin/accounts', [
            'name' => 'Created user',
            'email' => 'created@example.test',
            'password' => 'StrongPassword123!',
            'role' => 3,
            'idCostumer' => $this->firstCustomer->id,
        ]);
        $createdAccounts = $this->assertSafeAccountResponse($createResponse);
        $this->assertContains('created@example.test', array_column($createdAccounts, 'email'));

        $createdUser = User::where('email', 'created@example.test')->firstOrFail();
        $updateResponse = $this->putJson('/api/admin/accounts/'.$createdUser->id, [
            'name' => 'Updated user',
            'password' => 'AnotherPassword123!',
        ]);
        $updatedAccounts = $this->assertSafeAccountResponse($updateResponse);
        $updatedAccount = collect($updatedAccounts)->firstWhere('id', $createdUser->id);
        $this->assertSame('Updated user', $updatedAccount['name']);

        $deleteResponse = $this->deleteJson('/api/admin/accounts/'.$createdUser->id);
        $remainingAccounts = $this->assertSafeAccountResponse($deleteResponse);
        $this->assertNotContains(
            'created@example.test',
            array_column($remainingAccounts, 'email')
        );
    }

    private function assertSafeAccountResponse(TestResponse $response): array
    {
        $response->assertOk();
        $accounts = $response->json();
        $this->assertIsArray($accounts);

        $allowedFields = [
            'id',
            'email',
            'name',
            'role',
            'idCostumer',
            'costumer',
            'roleName',
        ];
        $sensitiveFields = [
            'password',
            'remember_token',
            'email_verified_at',
            'provider_name',
            'provider_id',
            'created_at',
            'updated_at',
            'apiKey',
        ];

        foreach ($accounts as $account) {
            $this->assertEqualsCanonicalizing($allowedFields, array_keys($account));
            foreach ($sensitiveFields as $field) {
                $this->assertArrayNotHasKey($field, $account);
            }
        }

        return $accounts;
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

    private function createUser(
        Costumer $customer,
        int $role,
        string $email
    ): User {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'remember_token' => 'sensitive-remember-token',
            'idCostumer' => $customer->id,
            'provider_name' => 'sensitive-provider',
            'provider_id' => 'sensitive-provider-id',
        ]);
    }
}
