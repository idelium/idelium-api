<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->customer = Costumer::forceCreate([
            'costumer' => 'Demo customer',
            'description' => 'Demo customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'demo-api-key',
        ]);

        $this->admin = User::forceCreate([
            'name' => 'Administrator',
            'role' => 2,
            'email' => 'admin@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('CurrentPassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_profile_password_change_requires_current_password(): void
    {
        $this->actingAs($this->admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/profile', [
                'password' => 'BetterPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currentPassword');
    }

    public function test_profile_password_change_rejects_wrong_current_password(): void
    {
        $this->actingAs($this->admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/profile', [
                'currentPassword' => 'WrongPassword123!',
                'password' => 'BetterPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currentPassword');
    }

    public function test_profile_password_change_rejects_weak_password(): void
    {
        $this->actingAs($this->admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/profile', [
                'currentPassword' => 'CurrentPassword123!',
                'password' => 'admin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_profile_password_change_accepts_policy_compliant_password(): void
    {
        $this->actingAs($this->admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson('/api/admin/profile', [
                'currentPassword' => 'CurrentPassword123!',
                'password' => 'BetterPassword123!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('BetterPassword123!', $this->admin->fresh()->password));
    }
}
