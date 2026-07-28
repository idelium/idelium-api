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

## MFA lifecycle

MFA enrollment generates a TOTP secret and recovery codes that are returned once.
The API stores the TOTP secret encrypted and stores only recovery-code hashes.
Confirmation validates a TOTP code before setting `mfaRequired=true`.
Step-up verification accepts either a valid TOTP code or one recovery code,
records a session timestamp, audits the attempt, and consumes used recovery
codes. Disabled SCIM users cannot log in, even if they still know their password.

## Browser SSO lifecycle

Browser SSO starts with a stateful session request that records a one-time
`state`, `nonce`, redirect URI, and provider id. OIDC callbacks validate JWT
signature, issuer, audience, nonce, recipient, email verification, and assertion
time bounds. SAML callbacks validate the signed response, issuer, audience,
recipient, email verification, and assertion time bounds.

SSO does not auto-create users. A callback can authenticate only an existing,
active, same-tenant account with a verified IdP email. Break-glass accounts are
rejected from normal SSO. Invalid state, replayed state, wrong issuer, wrong
audience, expired assertion, unknown account, and missing browser-session cases
fail closed and are audited.

## Compatibility

The identity lifecycle columns are additive. Existing users default to active,
non-break-glass, non-MFA-required accounts, preserving the current login behavior
until a tenant enables stricter identity policies.
