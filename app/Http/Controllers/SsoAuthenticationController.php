<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\IdentityProvider;
use App\Services\AuditEventService;
use App\Services\SsoAuthenticationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SsoAuthenticationController extends Controller
{
    public function __construct(
        private readonly SsoAuthenticationService $sso,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function start(Request $request, IdentityProvider $identityProvider)
    {
        $validated = $request->validate([
            'redirectUri' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $started = $this->sso->start($identityProvider, $validated['redirectUri'], $request);
        $this->auditEvents->record(
            $this->tenantlessRequest($request, $identityProvider),
            'sso.start',
            'identity_provider',
            (string) $identityProvider->id,
            afterValues: [
                'redirectUri' => $validated['redirectUri'],
                'state' => '[REDACTED]',
                'nonce' => '[REDACTED]',
            ],
        );

        return response()->json([
            'data' => $started,
        ]);
    }

    public function oidcCallback(Request $request, IdentityProvider $identityProvider)
    {
        $validated = $request->validate([
            'state' => ['required', 'string'],
            'idToken' => ['required', 'string'],
        ]);

        return $this->complete($request, $identityProvider, fn () => $this->sso->completeOidc(
            $identityProvider,
            $validated['state'],
            $validated['idToken'],
            $request,
        ));
    }

    public function samlCallback(Request $request, IdentityProvider $identityProvider)
    {
        $validated = $request->validate([
            'state' => ['required', 'string'],
            'SAMLResponse' => ['required', 'string'],
            'Signature' => ['required', 'string'],
        ]);

        return $this->complete($request, $identityProvider, fn () => $this->sso->completeSaml(
            $identityProvider,
            $validated['state'],
            $validated['SAMLResponse'],
            $validated['Signature'],
            $request,
        ));
    }

    private function complete(Request $request, IdentityProvider $provider, callable $callback)
    {
        try {
            $user = $callback();
            $this->auditEvents->record(
                $this->tenantlessRequest($request, $provider),
                'sso.complete',
                'user',
                (string) $user->id,
                afterValues: [
                    'providerId' => $provider->id,
                    'providerType' => $provider->type,
                    'email' => $user->email,
                ],
            );

            return response()->json([
                'authenticated' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'mfaRequired' => (bool) $user->mfaRequired,
                ],
            ]);
        } catch (ValidationException $exception) {
            $this->auditEvents->record(
                $this->tenantlessRequest($request, $provider),
                'sso.reject',
                'identity_provider',
                (string) $provider->id,
                result: AuditEvent::RESULT_FAILURE,
                afterValues: [
                    'errors' => $exception->errors(),
                    'idToken' => '[REDACTED]',
                    'SAMLResponse' => '[REDACTED]',
                ],
            );

            throw $exception;
        }
    }

    private function tenantlessRequest(Request $request, IdentityProvider $provider): Request
    {
        $customer = Costumer::findOrFail($provider->idCostumer);
        $request->attributes->set(
            ResolveTenantContext::ATTRIBUTE,
            TenantContext::forCustomerKey($customer)
        );

        return $request;
    }
}
