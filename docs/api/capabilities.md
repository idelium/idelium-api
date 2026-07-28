# Capability-Based Authorization

Idelium is migrating from ad-hoc numeric role checks to a deny-by-default
capability catalog.

## Current Contract

- The versioned capability catalog lives in `config/capabilities.php`.
- API code should call `CapabilityService::require()` before privileged actions.
- Forbidden actions return HTTP 403 with the stable
  `AUTHORIZATION_FORBIDDEN` error code.
- The Web console can read `GET /api/me/capabilities` to decide which
  affordances to show, but the API remains the security boundary.

## Migration Policy

Existing role checks should be replaced incrementally by explicit capabilities.
The first slice protects tenant switching, account administration, customer
administration, API key rotation, audit event reads, and artifact descriptor
reads.

Resource ownership and tenant isolation checks remain mandatory even when a user
has the relevant capability.
