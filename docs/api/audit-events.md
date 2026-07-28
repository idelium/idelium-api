# Audit Events

Idelium stores privileged and security-relevant activity as append-only audit
events. Audit events are tenant-scoped and include the authenticated actor, the
active tenant, the target resource, the result, source IP, timestamp, and a
correlation ID.

## Current Contract

- Audit events are insert-only. Application code cannot update or delete them
  through the `AuditEvent` model.
- Sensitive keys such as passwords, API keys, tokens, authorization headers,
  cookies, credentials, and session identifiers are stored as `[REDACTED]`.
- API responses include an `X-Correlation-ID` header. If callers provide a valid
  UUID in that header, Idelium preserves it; otherwise Idelium generates one.
- Tenant-scoped audit search is available through `GET /api/audit-events`.
- Tenant switching writes an audit event because it changes the active tenant
  boundary for the browser session.

## Migration Policy

New privileged operations must record an audit event through
`AuditEventService`. Existing controllers should be migrated incrementally, with
security-sensitive operations prioritized first: authentication, identity and
role changes, plugin changes, key changes, launches, cancellations, reports,
secret references, and platform status changes.

Retention, export, legal hold, and cross-repository Web/Docker surfaces are
tracked by the enterprise roadmap and are not complete in this initial API
slice.
