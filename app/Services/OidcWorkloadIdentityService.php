<?php

namespace App\Services;

use App\Models\OidcWorkloadAssertion;
use App\Models\OidcWorkloadToken;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OidcWorkloadIdentityService
{
    public const TOKEN_PREFIX = 'idwo';

    /**
     * @return array{workloadToken: OidcWorkloadToken, token: string, claims: array<string, mixed>}
     */
    public function exchange(string $providerName, string $assertion, int $projectId): array
    {
        $provider = config('oidc_workload_identity.providers.'.$providerName);
        if (! is_array($provider)) {
            throw ValidationException::withMessages([
                'provider' => ['The OIDC provider is not configured.'],
            ]);
        }

        [$header, $claims, $signedPart, $signature] = $this->decodeAssertion($assertion);
        $this->validateSignature($provider, $header, $signedPart, $signature);
        $this->validateClaims($provider, $claims);
        $project = $this->projectForPolicy($provider, $claims, $projectId);

        return DB::transaction(function () use ($providerName, $claims, $project) {
            $jti = (string) $claims['jti'];
            $expiresAt = now()->createFromTimestamp((int) $claims['exp']);

            try {
                OidcWorkloadAssertion::create([
                    'provider' => $providerName,
                    'jti' => $jti,
                    'expiresAt' => $expiresAt,
                ]);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'assertion' => ['The OIDC assertion has already been used.'],
                ]);
            }

            $tokenId = self::TOKEN_PREFIX.'_'.Str::random(24);
            $secret = Str::random(64);
            $token = OidcWorkloadToken::create([
                'idCostumer' => $project->idCostumer,
                'idProject' => $project->id,
                'provider' => $providerName,
                'subject' => (string) $claims['sub'],
                'repository' => $this->claimString($claims, 'repository'),
                'ref' => $this->claimString($claims, 'ref'),
                'environment' => $this->claimString($claims, 'environment'),
                'tokenId' => $tokenId,
                'tokenHash' => Hash::make($secret),
                'scopes' => $this->policyForClaims($project->id, $providerName, $claims)['scopes'] ?? ['runs.launch'],
                'expiresAt' => now()->addSeconds((int) config('oidc_workload_identity.token_ttl_seconds', 300)),
            ]);

            return [
                'workloadToken' => $token,
                'token' => $tokenId.'.'.$secret,
                'claims' => $this->safeClaims($claims),
            ];
        });
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decodeAssertion(string $assertion): array
    {
        $parts = explode('.', $assertion);
        if (count($parts) !== 3) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion must be a JWT.'],
            ]);
        }

        $header = $this->jsonPart($parts[0], 'header');
        $claims = $this->jsonPart($parts[1], 'claims');

        return [$header, $claims, $parts[0].'.'.$parts[1], $parts[2]];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPart(string $part, string $name): array
    {
        $decoded = json_decode($this->base64UrlDecode($part), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion '.$name.' is invalid.'],
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $header
     */
    private function validateSignature(array $provider, array $header, string $signedPart, string $signature): void
    {
        $alg = (string) ($header['alg'] ?? '');
        $allowed = $provider['algorithms'] ?? [];
        if (! in_array($alg, is_array($allowed) ? $allowed : [], true)) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion algorithm is not allowed.'],
            ]);
        }

        $signatureBytes = $this->base64UrlDecode($signature);
        $verified = match ($alg) {
            'HS256' => $this->verifyHmac($provider, $signedPart, $signatureBytes),
            'RS256' => $this->verifyRsa($provider, $header, $signedPart, $signatureBytes),
            default => false,
        };

        if (! $verified) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion signature is invalid.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function verifyHmac(array $provider, string $signedPart, string $signature): bool
    {
        $secret = $provider['hmacSecret'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $signedPart, $secret, true), $signature);
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $header
     */
    private function verifyRsa(array $provider, array $header, string $signedPart, string $signature): bool
    {
        $kid = (string) ($header['kid'] ?? '');
        $keys = $provider['publicKeys'] ?? [];
        $publicKey = is_array($keys) ? ($keys[$kid] ?? null) : null;

        return is_string($publicKey)
            && openssl_verify($signedPart, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $provider, array $claims): void
    {
        foreach (['iss', 'aud', 'sub', 'exp', 'iat', 'jti'] as $required) {
            if (! array_key_exists($required, $claims)) {
                throw ValidationException::withMessages([
                    'assertion' => ['The OIDC assertion is missing '.$required.'.'],
                ]);
            }
        }

        if ((string) $claims['iss'] !== (string) ($provider['issuer'] ?? '')) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion issuer is not trusted.'],
            ]);
        }

        if (! $this->audienceMatches($claims['aud'], (string) ($provider['audience'] ?? ''))) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion audience is not allowed.'],
            ]);
        }

        $now = now()->timestamp;
        if ((int) $claims['exp'] <= $now) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion is expired.'],
            ]);
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion is not valid yet.'],
            ]);
        }

        $maxAge = (int) config('oidc_workload_identity.max_assertion_age_seconds', 300);
        if ((int) $claims['iat'] > $now || (int) $claims['iat'] < $now - $maxAge) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion issue time is outside the accepted window.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $claims
     */
    private function projectForPolicy(array $provider, array $claims, int $projectId): Project
    {
        $policy = $this->policyForClaims($projectId, '', $claims, $provider);
        if ($policy === null) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion is not authorized for this project.'],
            ]);
        }

        return Project::query()
            ->whereKey($projectId)
            ->where('idCostumer', (int) $policy['idCostumer'])
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $provider
     * @return array<string, mixed>|null
     */
    private function policyForClaims(
        int $projectId,
        string $providerName,
        array $claims,
        ?array $provider = null
    ): ?array {
        $providerConfig = $provider ?? config('oidc_workload_identity.providers.'.$providerName, []);
        $policies = is_array($providerConfig) ? ($providerConfig['policies'] ?? []) : [];
        if (! is_array($policies)) {
            return null;
        }

        foreach ($policies as $policy) {
            if (! is_array($policy) || (int) ($policy['idProject'] ?? 0) !== $projectId) {
                continue;
            }

            if (! $this->claimMatches($claims, $policy, 'repository')
                || ! $this->claimMatches($claims, $policy, 'ref')
                || ! $this->claimMatches($claims, $policy, 'environment')) {
                continue;
            }

            return $policy;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $policy
     */
    private function claimMatches(array $claims, array $policy, string $key): bool
    {
        if (! array_key_exists($key, $policy)) {
            return true;
        }

        $allowed = $policy[$key];
        if ($allowed === null) {
            return true;
        }

        $claim = $this->claimString($claims, $key);
        $allowedValues = is_array($allowed) ? $allowed : [$allowed];

        foreach ($allowedValues as $allowedValue) {
            if (is_string($allowedValue) && fnmatch($allowedValue, (string) $claim)) {
                return true;
            }
        }

        return false;
    }

    private function audienceMatches(mixed $audience, string $expected): bool
    {
        return is_string($audience)
            ? $audience === $expected
            : (is_array($audience) && in_array($expected, $audience, true));
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function safeClaims(array $claims): array
    {
        return [
            'iss' => $claims['iss'] ?? null,
            'aud' => $claims['aud'] ?? null,
            'sub' => $claims['sub'] ?? null,
            'repository' => $claims['repository'] ?? null,
            'ref' => $claims['ref'] ?? null,
            'environment' => $claims['environment'] ?? null,
            'jti' => $claims['jti'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function claimString(array $claims, string $key): ?string
    {
        return isset($claims[$key]) && is_scalar($claims[$key]) ? (string) $claims[$key] : null;
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0
            ? strlen($value)
            : strlen($value) + 4 - strlen($value) % 4, '=', STR_PAD_RIGHT);

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw ValidationException::withMessages([
                'assertion' => ['The OIDC assertion is not valid base64url.'],
            ]);
        }

        return $decoded;
    }
}
