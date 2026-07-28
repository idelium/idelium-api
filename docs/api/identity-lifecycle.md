# Enterprise identity lifecycle

Idelium API stores tenant-scoped enterprise identity configuration so SSO, MFA,
SCIM, and break-glass workflows can evolve without weakening existing session
authentication.

## Identity providers

Identity providers are owned by a single tenant. The first registry version stores
provider type, issuer, audience, redirect allow-list, status, and group-to-role
mapping metadata. OIDC and SAML login flows must validate issuer, audience,
signature, nonce, state, time bounds, and redirect targets before a session is
created. The API stores those policy inputs before enabling browser or IdP
callback flows.

## SCIM provisioning

SCIM user upserts are idempotent by tenant, identity provider, and external id.
Provisioned users are marked with `identityProviderId`, `externalId`, and
`mfaRequired=true`. Group mappings are resolved through the provider
`groupRoleMap`, falling back to the standard user role when no configured group
matches.

Deprovisioned SCIM users are set to `disabled` and active API tokens are revoked.
SCIM cannot modify break-glass accounts; this prevents an IdP configuration
mistake from disabling emergency access.

## Break-glass lifecycle

Break-glass accounts are explicitly flagged on the account record, include an
operator reason, and can record periodic access tests. Enablement, disablement,
and tests are audited. Break-glass accounts are intentionally excluded from SCIM
modification and should be monitored separately from daily-use accounts.

## Compatibility

The identity lifecycle columns are additive. Existing users default to active,
non-break-glass, non-MFA-required accounts, preserving the current login behavior
until a tenant enables stricter identity policies.
