# OIDC Workload Identity

Idelium supports CI workload identity through an OIDC token-exchange endpoint.
Trusted CI systems can exchange signed OIDC assertions for short-lived,
project-scoped Idelium workload tokens without static API keys.

## Endpoint

`POST /api/oidc/token-exchange`

```json
{
  "provider": "github-actions",
  "projectId": 123,
  "assertion": "<oidc-jwt>"
}
```

Successful responses reveal the workload token once:

```json
{
  "tokenType": "Bearer",
  "token": "idwo_...",
  "expiresAt": "2026-07-28T12:00:00.000000Z",
  "scopes": ["runs.launch"],
  "projectId": 123
}
```

The token secret is stored only as a hash. Tokens are short-lived,
project-scoped, tenant-scoped, and non-refreshable.

## Validation policy

The exchange validates:

- configured provider name;
- JWT signature and allowed algorithm;
- issuer;
- audience;
- subject;
- expiry, not-before, and issued-at time bounds;
- `jti` replay protection;
- repository, ref, and environment policy for the target project.

Rejections are returned as validation errors and audited without storing the
assertion or generated token value.

## Provider configuration

Configure providers in `config/oidc_workload_identity.php`. Policies are
deny-by-default:

```php
'github-actions' => [
    'issuer' => 'https://token.actions.githubusercontent.com',
    'audience' => 'idelium-api',
    'algorithms' => ['RS256'],
    'publicKeys' => [
        'github-key-id' => env('IDELIUM_OIDC_GITHUB_PUBLIC_KEY'),
    ],
    'policies' => [[
        'idCostumer' => 1,
        'idProject' => 123,
        'repository' => 'idelium/idelium-cli',
        'ref' => 'refs/heads/main',
        'environment' => 'production',
        'scopes' => ['runs.launch'],
    ]],
],
```

Use `RS256` public-key validation in production. `HS256` exists only for local
testing and controlled private issuers.

## CI examples

- GitHub Actions: pin actions by version or commit SHA, request `id-token:
  write`, and set the token audience to the configured Idelium audience.
- GitLab CI: pin included templates and images by digest; configure the ID token
  `aud` field to match Idelium.
- Jenkins: pin the OIDC plugin version and runner images; restrict subject and
  branch/environment claims in the provider policy.

## Operations and rollback

Rotate provider keys by adding the new public key before removing the old key.
Lower token TTLs during incident response. Revocation is performed by setting
`revokedAt` on issued workload tokens. Replay rows expire with their assertion
TTL and may be purged by standard database retention jobs.
