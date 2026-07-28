# Authentication and Tenancy

## Browser authentication

Browser clients use Laravel Sanctum in stateful cookie mode:

1. `GET /api/sanctum/csrf-cookie`
2. `POST /api/login`
3. Authenticated browser calls to `/api/admin/*`, `/api/menu/*`, and `/api/me/*`
4. `POST /api/logout`

The API does not expose browser session identifiers or bearer tokens to
JavaScript. Cookies must be secure in production, CORS origins must be explicit,
and CSRF must be sent for mutating browser requests.

## CLI and machine authentication

CLI control-plane routes under `/api/ideliumcl/*` currently accept `Idelium-Key`.
The key identifies the tenant and must be provided through a protected secret
store. It must never be logged, placed in URLs, or committed.

Service accounts are the migration path away from singleton customer API keys.
They use one-time-reveal credentials with the `idsa_<credentialId>.<secret>`
format and hash-only storage. Revoked or expired credentials fail immediately.

## Runner token data plane

Parallel execution uses short-lived one-time run tokens:

- `POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/tokens`
  issues an `idrt_<tokenId>.<secret>` token for a specific agent.
- `POST /api/ideliumrunner/claim` consumes the run token and returns a worker token.
- `POST /api/ideliumrunner/heartbeat` and `PUT /api/ideliumrunner/worker` use `Idelium-Worker-Token`.

Run tokens are tenant-, project-, run-, and agent-bound. Replay, wrong-agent,
expired, revoked, and wrong-run attempts fail with validation errors and audit
records where implemented.

## Tenant context

Every tenant-owned resource lookup must be constrained by the active tenant. A
valid login, CLI key, service-account credential, or run token is not sufficient
to access another customer’s records.

Implementation rules:

- Browser routes resolve a tenant context from the authenticated user and, where supported, controlled tenant switch metadata.
- CLI routes resolve the tenant from machine credentials.
- Project-owned resources must check both `idProject` and `idCostumer`.
- Foreign tenant resources should return `404` rather than disclosing existence.
- Validation failures should use standard `422`; authorization failures should use standard `403` where existence can be disclosed safely.

## Capabilities

`GET /api/me/capabilities` returns the current user capability map used by Web
clients to enable or disable sensitive actions. Server-side capability checks
remain authoritative for privileged operations such as artifact lifecycle,
agent management, integration management, and resource governance.

## Redaction and response safety

Responses must expose explicit fields only. The API must never serialize:

- password hashes or remember tokens;
- API keys, service-account secrets, run-token secrets, worker-token secrets;
- authorization headers;
- session identifiers;
- raw secret references or environment values;
- unbounded upstream response bodies in diagnostics.

Performed-result payloads may contain runtime data. The API validates schema
versions, bounds payload size, and applies redaction before Web result readers
receive sensitive content.
