<?php

namespace Tests\Feature;

use App\Http\Controllers\MfaController;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\Role;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MfaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $customer = Costumer::forceCreate([
            'costumer' => 'MFA customer',
            'description' => 'MFA customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'mfa-api-key',
        ]);
        $this->user = User::forceCreate([
            'name' => 'MFA user',
            'role' => 3,
            'email' => 'mfa@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }

    public function test_user_enrolls_and_confirms_mfa_without_persisting_plain_recovery_codes(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/enroll')
            ->assertCreated()
            ->assertJsonPath('data.recoveryCodes.0', fn (string $code): bool => str_starts_with($code, 'idr-'))
            ->assertJsonPath('data.secret', fn (string $secret): bool => strlen($secret) === 32);

        $this->user->refresh();
        $this->assertSame($response->json('data.secret'), Crypt::decryptString($this->user->mfaSecretEncrypted));
        $this->assertCount(8, $this->user->mfaRecoveryCodeHashes);
        $this->assertStringNotContainsString(
            $response->json('data.recoveryCodes.0'),
            json_encode($this->user->mfaRecoveryCodeHashes)
        );

        $audit = AuditEvent::where('action', 'mfa.enroll')->firstOrFail();
        $this->assertSame('[REDACTED]', $audit->afterValues['secret']);
        $this->assertSame('[REDACTED]', $audit->afterValues['recoveryCodes']);

        $code = app(MfaService::class)->totpForTest($this->user->fresh());
        $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.mfaRequired', true)
            ->assertJsonPath('data.mfaConfirmed', true);
    }

    public function test_step_up_accepts_totp_and_recovery_code_once(): void
    {
        $enrollment = app(MfaService::class)->enroll($this->user);
        app(MfaService::class)->confirm($this->user->fresh(), app(MfaService::class)->totpForTest($this->user->fresh()));

        $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/step-up', [
                'code' => app(MfaService::class)->totpForTest($this->user->fresh()),
            ])
            ->assertOk()
            ->assertJsonPath('data.mfaConfirmed', true);
        $this->assertNotNull(session(MfaController::SESSION_STEP_UP_VERIFIED_AT));

        $recoveryCode = $enrollment['recoveryCodes'][0];
        $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/step-up', ['code' => $recoveryCode])
            ->assertOk()
            ->assertJsonPath('data.recoveryCodesRemaining', 7);

        $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/step-up', ['code' => $recoveryCode])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_break_glass_account_cannot_enroll_standard_mfa(): void
    {
        $this->user->forceFill([
            'isBreakGlass' => true,
            'breakGlassReason' => 'Emergency recovery.',
        ])->save();

        $this->actingAs($this->user)
            ->withHeader('Origin', 'https://localhost')
            ->postJson('/api/admin/profile/mfa/enroll')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');
    }
}
