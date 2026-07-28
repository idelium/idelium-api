# Tenant Context

Idelium separates the authenticated actor from the active tenant used to scope
data access.

## Current Contract

- Browser requests resolved through Sanctum receive a `TenantContext` request
  attribute.
- CLI requests authenticated with `Idelium-Key` receive the same context shape,
  with no user actor because the current credential belongs to a customer.
- Existing controllers continue to read `idCostumer` from the authenticated user
  during the migration window. The tenant-context middleware sets this value to
  the validated active tenant for the request only; the persisted user identity
  is not modified.
- Unauthorized tenant switches return HTTP 403 with the stable
  `TENANT_SWITCH_FORBIDDEN` error code.
- Missing target tenants return HTTP 404 with the stable `TENANT_NOT_FOUND`
  error code.
- Tenant switches require a reason and expiry. The reason and expiry are
  returned in `tenantContext` so the Web console can show a persistent
  impersonation banner.
- Expired impersonation context is cleared automatically and the request falls
  back to the actor tenant.

## Migration Policy

New tenant-scoped code must read the active tenant from `TenantContext` rather
than from session state or request payloads. Existing controllers should be
migrated incrementally to consume `TenantContext` directly and avoid duplicating
role checks.

Safe impersonation still requires Web banner integration and broader
cross-resource negative-security coverage before the roadmap item can be
considered complete.
