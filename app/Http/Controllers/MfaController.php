<?php

namespace App\Http\Controllers;

use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Services\MfaService;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public const SESSION_STEP_UP_VERIFIED_AT = 'idelium.mfaStepUpVerifiedAt';

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly MfaService $mfa,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function enroll(Request $request)
    {
        $this->capabilities->require($request->user(), 'profile.manage');
        $enrollment = $this->mfa->enroll($request->user());
        $this->auditEvents->record(
            $request,
            'mfa.enroll',
            'user',
            (string) $request->user()->id,
            afterValues: [
                'secret' => $enrollment['secret'],
                'recoveryCodes' => $enrollment['recoveryCodes'],
            ],
        );

        return response()->json([
            'data' => [
                'otpauthUri' => $enrollment['otpauthUri'],
                'secret' => $enrollment['secret'],
                'recoveryCodes' => $enrollment['recoveryCodes'],
            ],
        ], 201);
    }

    public function confirm(Request $request)
    {
        $this->capabilities->require($request->user(), 'profile.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $this->mfa->confirm($request->user(), $validated['code']);
        $this->auditEvents->record(
            $request,
            'mfa.confirm',
            'user',
            (string) $user->id,
            afterValues: [
                'mfaRequired' => (bool) $user->mfaRequired,
                'mfaConfirmedAt' => optional($user->mfaConfirmedAt)->toISOString(),
            ],
        );

        return response()->json([
            'data' => $this->state($user),
        ]);
    }

    public function stepUp(Request $request)
    {
        $this->capabilities->require($request->user(), 'profile.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        if (! $this->mfa->verifyStepUp($request->user(), $validated['code'])) {
            $this->auditEvents->record(
                $request,
                'mfa.step_up',
                'user',
                (string) $request->user()->id,
                result: \App\Models\AuditEvent::RESULT_FAILURE,
                afterValues: ['code' => $validated['code']],
            );

            return response()->json([
                'message' => 'The MFA code is invalid.',
                'errors' => [
                    'code' => ['The MFA code is invalid.'],
                ],
            ], 422);
        }

        $verifiedAt = now();
        $request->session()->put(self::SESSION_STEP_UP_VERIFIED_AT, $verifiedAt->toISOString());
        $this->auditEvents->record(
            $request,
            'mfa.step_up',
            'user',
            (string) $request->user()->id,
            afterValues: [
                'verifiedAt' => $verifiedAt->toISOString(),
            ],
        );

        return response()->json([
            'data' => [
                ...$this->state($request->user()->fresh()),
                'stepUpVerifiedAt' => $verifiedAt->toISOString(),
                'stepUpExpiresAt' => $verifiedAt->copy()
                    ->addSeconds((int) config('mfa.step_up_ttl_seconds', 900))
                    ->toISOString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state($user): array
    {
        return [
            'mfaRequired' => (bool) $user->mfaRequired,
            'mfaConfirmed' => $user->mfaConfirmedAt !== null,
            'mfaConfirmedAt' => optional($user->mfaConfirmedAt)->toISOString(),
            'recoveryCodesRemaining' => count($user->mfaRecoveryCodeHashes ?? []),
        ];
    }
}
