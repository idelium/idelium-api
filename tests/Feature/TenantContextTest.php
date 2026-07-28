<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateIdeliumKey;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantContextTest extends TestCase
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

    public function test_tenant_admin_cannot_switch_active_tenant(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2, 'admin@example.test');
        $this->actingAs($admin);

        $this->withHeader('Origin', 'https://localhost')
            ->withSession([])
            ->putJson('/api/menu/header/'.$this->secondCustomer->id)
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'The requested action is not authorized.',
                'error' => [
                    'code' => 'AUTHORIZATION_FORBIDDEN',
                ],
            ]);

        $this->assertNull(session(ResolveTenantContext::SESSION_ACTIVE_TENANT));
        $this->assertSame($this->firstCustomer->id, $admin->fresh()->idCostumer);
    }

    public function test_superadmin_switches_active_tenant_without_changing_actor_identity(): void
    {
        $superadmin = $this->createUser($this->firstCustomer, 1, 'root@example.test');
        Project::forceCreate([
            'name' => 'First project',
            'description' => 'First project',
            'idCostumer' => $this->firstCustomer->id,
        ]);
        $secondProject = Project::forceCreate([
            'name' => 'Second project',
            'description' => 'Second project',
            'idCostumer' => $this->secondCustomer->id,
        ]);
        $this->actingAs($superadmin);

        $this->withHeader('Origin', 'https://localhost')
            ->withSession([])
            ->putJson('/api/menu/header/'.$this->secondCustomer->id)
            ->assertOk()
            ->assertJsonPath('tenantContext.actorUserId', $superadmin->id)
            ->assertJsonPath('tenantContext.actorTenantId', $this->firstCustomer->id)
            ->assertJsonPath('tenantContext.activeTenantId', $this->secondCustomer->id)
            ->assertJsonPath('tenantContext.impersonating', true);

        $this->assertSame($this->firstCustomer->id, $superadmin->fresh()->idCostumer);

        $this->withHeader('Origin', 'https://localhost')
            ->getJson('/api/menu/header')
            ->assertOk()
            ->assertJsonPath('tenantContext.actorUserId', $superadmin->id)
            ->assertJsonPath('tenantContext.actorTenantId', $this->firstCustomer->id)
            ->assertJsonPath('tenantContext.activeTenantId', $this->secondCustomer->id)
            ->assertJsonPath('tenantContext.impersonating', true)
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.id', $secondProject->id);
    }

    public function test_missing_switched_tenant_returns_standard_not_found_envelope(): void
    {
        $superadmin = $this->createUser($this->firstCustomer, 1, 'root@example.test');
        $this->actingAs($superadmin);

        $this->withHeader('Origin', 'https://localhost')
            ->withSession([])
            ->putJson('/api/menu/header/999')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Tenant was not found.',
                'error' => [
                    'code' => 'TENANT_NOT_FOUND',
                ],
            ]);
    }

    public function test_idelium_key_resolves_tenant_context_for_cli_requests(): void
    {
        $request = request();
        $request->headers->set('Idelium-Key', $this->firstCustomer->apiKey);

        $middleware = app(AuthenticateIdeliumKey::class);
        $response = $middleware->handle($request, function ($request) {
            $context = $request->attributes->get(
                AuthenticateIdeliumKey::TENANT_CONTEXT_ATTRIBUTE
            );

            return response()->json([
                'actorUserId' => $context->actorUserId,
                'actorTenantId' => $context->actorTenantId,
                'activeTenantId' => $context->activeTenantId,
                'impersonating' => $context->isImpersonating(),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'actorUserId' => null,
            'actorTenantId' => $this->firstCustomer->id,
            'activeTenantId' => $this->firstCustomer->id,
            'impersonating' => false,
        ], $response->getData(true));
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
            'idCostumer' => $customer->id,
        ]);
    }
}
