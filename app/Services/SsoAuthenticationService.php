<?php

namespace App\Services;

use App\Models\IdentityProvider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SsoAuthenticationService
{
    /**
     * @return array{authorizationUrl: string, state: string, nonce: string}
     */
    public function start(IdentityProvider $provider, string $redirectUri, Request $request): array
    {
        $this->assertProviderActive($provider, [IdentityProvider::TYPE_OIDC, IdentityProvider::TYPE_SAML]);
        $this->assertRedirectAllowed($provider, $redirectUri);
        $this->assertBrowserSession($request);

        $state = Str::random(48);
        $nonce = Str::random(48);
        $request->session()->put($this->sessionKey($state), [
            'providerId' => $provider->id,
            'redirectUri' => $redirectUri,
            'nonce' => $nonce,
            'createdAt' => now()->toISOString(),
        ]);

        return [
            'authorizationUrl' => $this->authorizationUrl($provider, $redirectUri, $state, $nonce),
            'state' => $state,
            'nonce' => $nonce,
        ];
    }

    public function completeOidc(IdentityProvider $provider, string $state, string $idToken, Request $request): User
    {
        $session = $this->consumeState($provider, $state, $request);
        [$header, $claims, $signedPart, $signature] = $this->decodeJwt($idToken, 'id token');
        $this->validateJwtSignature($provider, $header, $signedPart, $signature);
        $this->validateCommonClaims(
            provider: $provider,
            claims: $claims,
            nonce: (string) $session['nonce'],
            recipient: (string) $session['redirectUri'],
        );

        return $this->loginLinkedUser($provider, $claims, $request);
    }

    public function completeSaml(IdentityProvider $provider, string $state, string $samlResponse, string $signature, Request $request): User
    {
        $session = $this->consumeState($provider, $state, $request);
        $secret = $provider->metadataValue('samlSigningSecret');
        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['The SAML signing policy is not configured.'],
            ]);
        }

        if (! hash_equals(hash_hmac('sha256', $samlResponse, $secret), $signature)) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['The SAML response signature is invalid.'],
            ]);
        }

        $claims = json_decode(base64_decode($samlResponse, true) ?: '', true);
        if (! is_array($claims)) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['The SAML response is invalid.'],
            ]);
        }

        $this->validateCommonClaims(
            provider: $provider,
            claims: $claims,
            nonce: null,
            recipient: (string) $session['redirectUri'],
        );

        return $this->loginLinkedUser($provider, $claims, $request);
    }

    /**
     * @param  array<int, string>  $types
     */
    private function assertProviderActive(IdentityProvider $provider, array $types): void
    {
        if (! in_array($provider->type, $types, true) || $provider->status !== IdentityProvider::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'provider' => ['The identity provider is not active for this SSO flow.'],
            ]);
        }
    }

    private function assertRedirectAllowed(IdentityProvider $provider, string $redirectUri): void
    {
        if (! in_array($redirectUri, $provider->redirectUris ?? [], true)) {
            throw ValidationException::withMessages([
                'redirectUri' => ['The SSO redirect target is not allowed.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeState(IdentityProvider $provider, string $state, Request $request): array
    {
        $this->assertBrowserSession($request);
        $sessionKey = $this->sessionKey($state);
        $session = $request->session()->pull($sessionKey);
        if (! is_array($session) || (int) ($session['providerId'] ?? 0) !== $provider->id) {
            throw ValidationException::withMessages([
                'state' => ['The SSO state is invalid or has already been used.'],
            ]);
        }

        $createdAt = isset($session['createdAt']) ? strtotime((string) $session['createdAt']) : false;
        if ($createdAt === false || $createdAt < now()->timestamp - (int) config('sso.state_ttl_seconds', 300)) {
            throw ValidationException::withMessages([
                'state' => ['The SSO state has expired.'],
            ]);
        }

        return $session;
    }

    private function assertBrowserSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw ValidationException::withMessages([
                'session' => ['SSO requires a stateful browser session.'],
            ]);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decodeJwt(string $jwt, string $name): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO '.$name.' must be a JWT.'],
            ]);
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO '.$name.' is invalid.'],
            ]);
        }

        return [$header, $claims, $parts[0].'.'.$parts[1], $parts[2]];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function validateJwtSignature(IdentityProvider $provider, array $header, string $signedPart, string $signature): void
    {
        $alg = (string) ($header['alg'] ?? '');
        $allowed = $provider->metadataValue('algorithms', ['HS256']);
        if ($alg !== 'HS256' || ! in_array('HS256', is_array($allowed) ? $allowed : [], true)) {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO id token algorithm is not allowed.'],
            ]);
        }

        $secret = $provider->metadataValue('hmacSecret');
        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO signing policy is not configured.'],
            ]);
        }

        $expected = hash_hmac('sha256', $signedPart, $secret, true);
        if (! hash_equals($expected, $this->base64UrlDecode($signature))) {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO id token signature is invalid.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateCommonClaims(
        IdentityProvider $provider,
        array $claims,
        ?string $nonce,
        string $recipient,
    ): void {
        foreach (['iss', 'aud', 'sub', 'email', 'exp', 'iat'] as $required) {
            if (! array_key_exists($required, $claims)) {
                throw ValidationException::withMessages([
                    'assertion' => ['The SSO assertion is missing '.$required.'.'],
                ]);
            }
        }

        if ((string) $claims['iss'] !== (string) $provider->issuer) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion issuer is not trusted.'],
            ]);
        }

        if (! $this->audienceMatches($claims['aud'], (string) $provider->audience)) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion audience is not allowed.'],
            ]);
        }

        if ($nonce !== null && (string) ($claims['nonce'] ?? '') !== $nonce) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion nonce is invalid.'],
            ]);
        }

        if (isset($claims['recipient']) && (string) $claims['recipient'] !== $recipient) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion recipient is not allowed.'],
            ]);
        }

        $now = now()->timestamp;
        if ((int) $claims['exp'] <= $now) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion is expired.'],
            ]);
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion is not valid yet.'],
            ]);
        }

        $maxAge = (int) config('sso.max_assertion_age_seconds', 300);
        if ((int) $claims['iat'] > $now || (int) $claims['iat'] < $now - $maxAge) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion issue time is outside the accepted window.'],
            ]);
        }

        if (($claims['email_verified'] ?? true) !== true) {
            throw ValidationException::withMessages([
                'assertion' => ['The SSO assertion email is not verified.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function loginLinkedUser(IdentityProvider $provider, array $claims, Request $request): User
    {
        $email = strtolower((string) $claims['email']);
        $user = User::query()
            ->where('idCostumer', $provider->idCostumer)
            ->where('email', $email)
            ->where('status', 'active')
            ->first();

        if (! $user instanceof User || (bool) $user->isBreakGlass) {
            throw ValidationException::withMessages([
                'account' => ['The SSO assertion is not linked to an active tenant account.'],
            ]);
        }

        $user->forceFill([
            'identityProviderId' => $provider->id,
            'externalId' => (string) $claims['sub'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }

    private function authorizationUrl(IdentityProvider $provider, string $redirectUri, string $state, string $nonce): string
    {
        if ($provider->type === IdentityProvider::TYPE_SAML) {
            return (string) $provider->metadataValue('ssoUrl', $provider->issuer);
        }

        $endpoint = (string) $provider->metadataValue('authorizationEndpoint', rtrim((string) $provider->issuer, '/').'/authorize');

        return $endpoint.'?'.http_build_query([
            'client_id' => (string) $provider->audience,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    private function sessionKey(string $state): string
    {
        return config('sso.session_state_key', 'idelium.sso').'.'.$state;
    }

    private function audienceMatches(mixed $actual, string $expected): bool
    {
        return is_array($actual)
            ? in_array($expected, $actual, true)
            : (string) $actual === $expected;
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw ValidationException::withMessages([
                'idToken' => ['The SSO id token is not valid base64url.'],
            ]);
        }

        return $decoded;
    }
}
