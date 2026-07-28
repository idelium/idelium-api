# Agent Registration API

Idelium keeps a tenant-scoped inventory of execution agents so schedulers can
separate discovery, approval, health, and run ownership.

## CLI registration

`POST /api/ideliumcl/agents/register`

The request is authenticated with the normal `Idelium-Key` header.

```json
{
  "agentId": "runner-01",
  "version": "1.0.14",
  "runtimes": ["selenium", "postman"],
  "capabilities": {
    "browsers": ["chrome"],
    "platforms": ["linux"]
  },
  "identityProof": {
    "certificateSha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
  },
  "maxConcurrency": 2,
  "health": "healthy"
}
```

New agents are created as `pending`. Re-registering an existing agent refreshes
its version, runtime support, capabilities, capacity, health, and `lastSeenAt`
without changing its approval status.

`identityProof.certificateSha256` is optional. When it is present, worker claim
requests must present the same certificate thumbprint in
`Idelium-Agent-Cert-Sha256` together with a valid one-time run token. This lets
operators terminate mTLS at infrastructure boundaries while keeping agent
identity enforcement inside the API.

## Web administration

- `GET /api/admin/agents` lists agents for the active tenant.
- `PUT /api/admin/agents/{agentRegistration}/status` changes status to
  `approved`, `maintenance`, `draining`, or `disabled`.

Status changes require `agents.manage`; listing requires `agents.read`.

## Scheduling compatibility

If a registered agent attempts to claim a parallel run while it is not
`approved` or is `unhealthy`, the claim is rejected with `409`. Unregistered
legacy workers remain supported for compatibility during the rollout window.

Registration refreshes and status changes are audited. Secret material must not
be included in capability payloads.
