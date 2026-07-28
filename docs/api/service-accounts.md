# Service Accounts

Service accounts are the migration path away from singleton retrievable
customer API keys. They are tenant-scoped, optionally project-scoped, and use a
one-time-reveal credential secret.

## Current Contract

- Service account credentials use the `idsa_<credentialId>.<secret>` format.
- The secret is revealed only by `ServiceAccountService::create()` and is stored
  only as a hash.
- Revoked or expired credentials fail immediately.
- Successful authentication updates `lastUsedAt`.
- The legacy `Costumer.apiKey` remains supported by `Idelium-Key` during the
  migration window for backward compatibility with existing CLI releases.

## Remaining Roadmap Work

Web and CLI flows for creation, rotation, revocation, controlled overlap,
project/scope enforcement on every endpoint, audit events, and migration tooling
remain open enterprise roadmap work.
