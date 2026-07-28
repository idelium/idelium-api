# Architecture

## Runtime topology

```text
Browser user
  │
  │ HTTPS + Sanctum CSRF/session
  ▼
Idelium Web ───────► Idelium API ───────► MariaDB / MySQL
                         │
                         ├──── queue worker for async integration and artifact work
                         │
Idelium CLI ─────────────┤
  │ Idelium-Key / service-account credentials
  │ run-token control plane
  ▼
Runner workers ─────────► /api/ideliumrunner/* token data plane
```

In the Docker deployment, Idelium API is usually reached through the gateway at
`https://localhost/api`. Web and API share the same browser origin in local
demo mode, which simplifies Sanctum cookie handling. Production deployments may
place the API behind a reverse proxy, but the same security model applies:
TLS termination, explicit CORS allow-lists, secure cookies, redacted logs, and
bounded request handling.

## Application layers

| Layer | Main code | Responsibility |
| --- | --- | --- |
| Routes | `routes/api.php` | Declares the public API surface and route middleware. |
| Middleware | Laravel `auth:sanctum`, `tenant.context`, CLI key middleware | Resolves identity, tenant context, and request security. |
| Controllers | `app/Http/Controllers` | Thin HTTP adapters: validate, authorize, call services, serialize responses. |
| Form requests | `app/Http/Requests` | Boundary validation for project-owned resources and payload shape. |
| Services | `app/Services` | Domain logic for tenancy, capabilities, result policies, token lifecycle, integrations, audit, artifact lifecycle, and identity. |
| Models | `app/Models` | Database-backed entities and relationships. |
| Migrations | `database/migrations` | Schema, indexes, tenant columns, foreign keys, and operational tables. |
| Tests | `tests/Feature`, `tests/Unit` | Contract, tenant-isolation, security, and regression coverage. |

## Core domain model

Idelium is organized around a tenant/customer and one or more projects:

```text
Costumer (tenant)
  ├─ Users / roles / capabilities
  ├─ Projects
  │   ├─ Environments
  │   ├─ Plugins
  │   ├─ Steps
  │   ├─ Tests
  │   ├─ Test cycles
  │   ├─ Asset versions / review events
  │   ├─ Parallel run schedules / run tokens
  │   └─ Integration endpoints / deliveries
  └─ Performed execution hierarchy
      ├─ Performed test cycles
      ├─ Performed tests
      ├─ Performed steps
      ├─ Artifact descriptors
      └─ Result exports
```

The current database schema still uses the historical `Costumer` spelling and
`idCostumer` column name. New code must preserve compatibility while enforcing
strict customer ownership at every query boundary.

## Request lifecycle

1. The request reaches a route in `routes/api.php`.
2. Middleware authenticates the caller and resolves the active tenant context.
3. A Form Request or controller validates input shape, type, ownership, limits, and allowed values.
4. Controllers call domain services or tenant-scoped model queries.
5. Responses serialize explicit fields only. Sensitive values are redacted or omitted.
6. Security-relevant transitions record audit events where implemented.

## Bounded data access

Large tables are moving to the enterprise grid contract. Grid-aware endpoints
keep legacy arrays unless `page` or `pageSize` is provided, then return bounded
`data` and `meta` payloads with server-side search, sort, filters, and pagination.
Current grid-enabled endpoints include projects, steps, tests, and test cycles.

## Transactional boundaries

Multi-record operations are transactional:

- Selenium import creates multiple steps and one test atomically.
- Step reordering updates all affected step positions atomically.
- Project deletion removes the project hierarchy and related performed results inside one transaction.
- Parallel run claim/update/cancel flows use transactions to maintain worker lease consistency.

Foreign keys and explicit application-level deletes protect the core hierarchy,
while keeping rollback and migration sequencing visible.
