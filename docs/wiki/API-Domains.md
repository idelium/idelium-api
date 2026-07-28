# API Domains

This page explains the purpose of each API group. The full endpoint list is in
[API Reference](API-Reference).

## Session and identity entrypoints

These routes support browser login, logout, CSRF, SSO, and OIDC workload token
exchange:

- `/api/sanctum/csrf-cookie`
- `/api/login`
- `/api/logout`
- `/api/sso/{identityProvider}/start`
- `/api/sso/{identityProvider}/oidc/callback`
- `/api/sso/{identityProvider}/saml/callback`
- `/api/oidc/token-exchange`

SSO supports OIDC and SAML callback flows. OIDC workload identity exchanges
validate signed assertions and issue bounded project-scoped credentials.

## Navigation support

`/api/menu/sidebar` and `/api/menu/header` provide Web navigation data and the
selected customer context. Header customer switching is protected by tenant
context rules and must not grant access to unauthorized tenants.

## Accounts, customers, roles, profile, and API key rotation

The administration API manages:

- roles: `/api/admin/roles`;
- accounts: `/api/admin/accounts`;
- current profile and password changes: `/api/admin/profile`;
- MFA enrollment, confirmation, and step-up: `/api/admin/profile/mfa/*`;
- customers: `/api/admin/costumers`;
- legacy customer API key retrieval and rotation: `/api/admin/apikey`;
- service accounts: `/api/admin/service-accounts`.

Account and profile responses intentionally expose only safe fields. API key and
service-account secrets are migration-sensitive and must be handled as one-time
or restricted-reveal credentials.

## Enterprise identity lifecycle

The identity lifecycle APIs configure identity providers, SCIM user upsert, and
break-glass controls:

- `/api/admin/identity/providers`
- `/api/admin/identity/providers/{identityProvider}/scim/users`
- `/api/admin/identity/accounts/{user}/break-glass`
- `/api/admin/identity/accounts/{user}/break-glass/test`

Identity providers may map external groups to Idelium roles. Break-glass access
requires reason, status tracking, and periodic test evidence.

## Audit events

`/api/audit-events` and `/api/admin/audit-events` expose append-only audit
activity. Security-sensitive transitions such as token issuance/rejection,
artifact lifecycle, integration delivery, and privileged identity changes should
record redacted audit metadata.

## Projects

`/api/admin/projects` is the root of test-design ownership. Project operations
are tenant-scoped and now support the enterprise grid contract for bounded
server-side listing while keeping legacy array responses when pagination is not
requested.

Deleting a project is a destructive transactional operation that removes the
project hierarchy and related performed results according to the migration and
foreign-key policy.

## Environments

`/api/admin/environments/{idProject}` manages environment definitions for a
project. Environment payloads may include safe secret references; raw secret
values must not be exposed. Environment access is scoped by both tenant and
project.

## Plugins

`/api/admin/plugins/{idProject}` stores reusable plugin definitions. Current
plugin support includes legacy Python snippets and enterprise manifest payloads.
The API normalizes stored manifests, computes source hashes, and prepares CLI
payloads while avoiding secret exposure.

## Steps

`/api/admin/steps/{idProject}` manages reusable execution steps. Steps support
legacy JSON, Selenium/Appium/Postman payloads, and Idelium DSL source or AST
payloads. Step order updates are transactional through
`/api/admin/steps/{idProject}/updateorder`.

## Tests

`/api/admin/tests/{idProject}` stores test definitions that reference steps and
runtime configuration. The API validates versioned runtime payloads for
Selenium, Appium, Postman, and DSL migration compatibility.

## Test cycles

`/api/admin/testcycles/{idProject}` stores ordered or grouped test-cycle
configuration. Test-cycle responses expose explicit fields only, and foreign
project or foreign cycle access returns standard errors.

## Selenium import and launch

`/api/admin/importtest` imports Selenium IDE data into steps and tests inside a
transaction. `/api/admin/launchtest` starts a remote launch request with strict
TLS verification, finite timeouts, and redacted upstream diagnostics.

## Platform catalogues

Platform endpoints manage data used by browser/device targeting:

- status and types;
- operating systems and OS versions;
- browsers and browser versions;
- brands, models, locations, and managed platforms.

These routes are Web-admin oriented and should be migrated to bounded grids as
catalogues grow.

## Performed result exploration

The Web result explorer reads:

- `/api/admin/testcyclesperfomed/{idTestCyclePerformed}`;
- `/api/admin/testsperfomed/{idTestPerformed}`;
- `/api/admin/stepsperfomed/{idTestPerformed}`.

Result exploration keeps legacy arrays without pagination parameters and returns
bounded metadata when paginated parameters are provided. Postman/Newman result
payloads expose request-level status, assertions, timing, URL, method, and
redacted response payloads.

## Result exports

`/api/admin/result-exports` creates and retrieves durable export descriptors for
execution reports. Supported formats include local execution report contracts
such as JSON, JUnit, Markdown, and related report payloads.

## Parallel execution

Parallel execution APIs create schedules, matrix schedules, claims, heartbeats,
worker status updates, cancellation, result listing, and token issuance/revocation.
The scheduling model is tenant- and project-scoped, idempotent where required,
and designed around worker leases.

## Artifacts

Artifact descriptor APIs describe screenshots, logs, JSON reports, JUnit,
Markdown, HTML, and other execution artifacts without exposing storage internals.
Lifecycle operations include impact analysis, legal hold, archive, restore, and
delete markers. Large artifact storage and purge workers are governed by the
artifact lifecycle policy.

## Asset governance

Asset impact and versioning APIs support enterprise change governance:

- impact analysis before mutating or deleting environments, plugins, steps,
  tests, or cycles;
- immutable asset versions;
- diffing between versions;
- review lifecycle events such as `in_review`, `approved`, and `deprecated`.

## Integrations

Integration endpoints configure outbound adapters and delivery records. The API
validates safe destinations, rotates secrets, tests integrations, records
delivery attempts, and supports replay of dead-letter deliveries.

## CLI control plane and reporting

`/api/ideliumcl/*` supports Idelium CLI configuration download and result upload:

- retrieve test cycles, tests, steps, plugins, environments, and project run data;
- create or update performed cycles, performed tests, and performed steps;
- register agents;
- create or manage parallel runs when called by authorized machine credentials.

CLI APIs are compatibility-sensitive because released CLI versions depend on
stable payload shapes.

## Runner data plane

`/api/ideliumrunner/*` is the token-only execution data plane. It avoids long-lived
customer API keys for worker traffic and should be preferred for new runner
implementations.
