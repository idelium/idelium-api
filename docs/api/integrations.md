# Integration webhooks and adapters

Idelium API exposes a tenant-scoped integration delivery platform for outbound
webhooks and adapter-shaped payloads. The first supported schema is
`2026-07-28.v1` and is additive-only during the current enterprise roadmap
window: consumers must ignore unknown fields and Idelium must keep the existing
top-level fields stable until a migration note declares a later deprecation.

## Endpoint security

Each integration endpoint belongs to one tenant and one project. Endpoint
secrets are encrypted at rest and are never returned by the API, written to
audit events, or included in delivery diagnostics. Delivery destinations are
validated before persistence to prevent localhost, private, link-local, and
reserved-network SSRF targets.

Outbound requests include finite timeouts and these signing headers:

- `Idelium-Delivery-Id`
- `Idelium-Event`
- `Idelium-Tenant-Id`
- `Idelium-Project-Id`
- `Idelium-Schema-Version`
- `Idelium-Signature`

The signature format is `t=<unix timestamp>,v1=<hex hmac>`, where the HMAC input
is `<timestamp>.<raw request body>` and the key is the per-endpoint secret.
Receivers should reject timestamps outside
`IDELIUM_WEBHOOK_SIGNATURE_TOLERANCE` seconds and should store
`Idelium-Delivery-Id` values temporarily to prevent replay.

## Delivery lifecycle

Deliveries are idempotent per tenant, project, endpoint, and idempotency key.
Successful `2xx` responses mark a delivery as `sent`. Non-success responses or
transport errors increment the attempt count, set the next retry timestamp using
bounded backoff, and move the delivery to `dead_letter` after
`IDELIUM_WEBHOOK_MAX_ATTEMPTS`.

Creation, test delivery, replay, disablement, secret rotation, and delivery
attempts are audited. Audit payloads are redacted through the same recursive
policy used by the rest of the API. Dead-letter deliveries are exposed through a
tenant-scoped list endpoint so operators can inspect terminal failures without
cross-project leakage.

## Supported adapters

- `webhook`: sends the canonical Idelium event payload.
- `slack`: wraps the canonical payload under `idelium` and includes a compact
  `text` summary suitable for Slack incoming webhooks.
- `teams`: wraps the canonical payload under `idelium` and includes a compact
  `text` summary suitable for Microsoft Teams webhook workflows.
- `jira`: emits a least-privilege issue-shaped payload with `summary`,
  `description`, `labels`, and the canonical payload under `idelium`.

External credentials for Jira, Slack, and Teams should be represented as secret
references owned by the destination integration service. Idelium stores only the
endpoint signing secret required to protect outbound delivery authenticity.
