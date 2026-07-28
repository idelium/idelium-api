<?php

namespace App\Http\Controllers;

use App\Models\IdentityProvider;
use App\Models\User;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Services\IdentityLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdentityLifecycleController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly IdentityLifecycleService $identityLifecycle,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function providers(Request $request)
    {
        $this->capabilities->require($request->user(), 'identity.manage');
        $context = $this->tenantContext($request);

        return response()->json([
            'data' => IdentityProvider::query()
                ->where('idCostumer', $context->activeTenantId)
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (IdentityProvider $provider): array => $this->serializeProvider($provider)),
        ]);
    }

    public function storeProvider(Request $request)
    {
        $this->capabilities->require($request->user(), 'identity.manage');
        $context = $this->tenantContext($request);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                IdentityProvider::TYPE_OIDC,
                IdentityProvider::TYPE_SAML,
                IdentityProvider::TYPE_SCIM,
            ])],
            'name' => ['required', 'string', 'max:128'],
            'issuer' => ['nullable', 'string', 'max:512'],
            'audience' => ['nullable', 'string', 'max:512'],
            'redirectUris' => ['nullable', 'array'],
            'redirectUris.*' => ['string', 'url', 'max:2048'],
            'groupRoleMap' => ['nullable', 'array'],
            'groupRoleMap.*' => ['integer', 'exists:roles,id'],
        ]);

        $provider = IdentityProvider::updateOrCreate([
            'idCostumer' => $context->activeTenantId,
            'type' => $validated['type'],
            'name' => $validated['name'],
        ], [
            'issuer' => $validated['issuer'] ?? null,
            'audience' => $validated['audience'] ?? null,
            'redirectUris' => $validated['redirectUris'] ?? [],
            'groupRoleMap' => $validated['groupRoleMap'] ?? [],
            'status' => IdentityProvider::STATUS_ACTIVE,
            'metadata' => [
                'managedBy' => 'idelium-api',
                'schemaVersion' => '2026-07-28.v1',
            ],
        ]);

        $this->auditEvents->record(
            $request,
            'identity_provider.upsert',
            'identity_provider',
            (string) $provider->id,
            afterValues: [
                'type' => $provider->type,
                'name' => $provider->name,
                'issuer' => $provider->issuer,
                'audience' => $provider->audience,
                'redirectUris' => $provider->redirectUris,
                'groupRoleMap' => $provider->groupRoleMap,
            ],
        );

        return response()->json(['data' => $this->serializeProvider($provider)], $provider->wasRecentlyCreated ? 201 : 200);
    }

    public function scimUpsertUser(Request $request, IdentityProvider $identityProvider)
    {
        $this->capabilities->require($request->user(), 'identity.manage');
        $context = $this->tenantContext($request);
        abort_unless((int) $identityProvider->idCostumer === $context->activeTenantId, 404);

        $validated = $request->validate([
            'externalId' => ['required', 'string', 'max:256'],
            'userName' => ['required', 'email', 'max:320'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', 'max:128'],
        ]);

        $result = $this->identityLifecycle->upsertScimUser($identityProvider, $validated);
        $this->auditEvents->record(
            $request,
            'scim_user.upsert',
            'user',
            (string) $result['user']->id,
            afterValues: [
                'externalId' => $result['user']->externalId,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
                'status' => $result['user']->status,
                'groups' => $result['scimIdentity']->groups,
            ],
        );

        return response()->json([
            'data' => $this->serializeUser($result['user']),
        ], $result['created'] ? 201 : 200);
    }

    public function updateBreakGlass(Request $request, User $user)
    {
        $this->capabilities->require($request->user(), 'identity.manage');
        $context = $this->tenantContext($request);
        abort_unless((int) $user->idCostumer === $context->activeTenantId, 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required_if:enabled,true', 'nullable', 'string', 'max:512'],
        ]);

        $before = [
            'isBreakGlass' => (bool) $user->isBreakGlass,
            'breakGlassReason' => $user->breakGlassReason,
        ];
        $updated = $this->identityLifecycle->markBreakGlass(
            $user,
            (string) ($validated['reason'] ?? ''),
            (bool) $validated['enabled'],
        );
        $this->auditEvents->record(
            $request,
            'break_glass.update',
            'user',
            (string) $updated->id,
            beforeValues: $before,
            afterValues: [
                'isBreakGlass' => (bool) $updated->isBreakGlass,
                'breakGlassReason' => $updated->breakGlassReason,
            ],
        );

        return response()->json(['data' => $this->serializeUser($updated)]);
    }

    public function recordBreakGlassTest(Request $request, User $user)
    {
        $this->capabilities->require($request->user(), 'identity.manage');
        $context = $this->tenantContext($request);
        abort_unless((int) $user->idCostumer === $context->activeTenantId, 404);

        $updated = $this->identityLifecycle->recordBreakGlassTest($user);
        $this->auditEvents->record(
            $request,
            'break_glass.test',
            'user',
            (string) $updated->id,
            afterValues: [
                'lastBreakGlassTestAt' => optional($updated->lastBreakGlassTestAt)->toISOString(),
            ],
        );

        return response()->json(['data' => $this->serializeUser($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'mfaRequired' => (bool) $user->mfaRequired,
            'isBreakGlass' => (bool) $user->isBreakGlass,
            'externalId' => $user->externalId,
            'identityProviderId' => $user->identityProviderId,
            'lastBreakGlassTestAt' => optional($user->lastBreakGlassTestAt)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProvider(IdentityProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'type' => $provider->type,
            'name' => $provider->name,
            'issuer' => $provider->issuer,
            'audience' => $provider->audience,
            'redirectUris' => $provider->redirectUris ?? [],
            'groupRoleMap' => $provider->groupRoleMap ?? [],
            'status' => $provider->status,
            'metadata' => app(AuditEventService::class)->redact($provider->metadata ?? []),
        ];
    }
}
