# Run Tokens

Run tokens are short-lived, one-time credentials for runner traffic. They are
bound to tenant, project, run, and agent identity.

## Current Contract

- Run tokens use the `idrt_<tokenId>.<secret>` format.
- The secret is revealed only once by the issuance endpoint and is stored only
  as a hash.
- Tokens are bound to `idCostumer`, `idProject`, `parallelRunScheduleId`, and
  `agentId`.
- Tokens expire after `IDELIUM_RUN_TOKEN_TTL_SECONDS`, defaulting to 300
  seconds.
- `claimWorker` requires and consumes `Idelium-Run-Token` before opening worker
  ownership.
- Registered agents may include `identityProof.certificateSha256`; when present,
  `claimWorker` requires the same thumbprint in `Idelium-Agent-Cert-Sha256`.
  This supports mTLS termination at an ingress or load balancer while preserving
  a reviewed workload identity binding at the API boundary.
- Replay, wrong-agent, expired, revoked, and wrong-run attempts fail with a
  stable validation error.
- Issuance, consumption, rejection, and revocation are audited with token values
  redacted.

## Endpoint

`POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/tokens`

Request body:

```json
{
  "agentId": "runner-01"
}
```

Response body:

```json
{
  "token": "idrt_xxx.yyy",
  "expiresAt": "2026-07-28T10:00:00.000000Z",
  "agentId": "runner-01"
}
```

`POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/tokens/{tokenId}/revoke`

Revocation is idempotent. Revoked tokens cannot be consumed.

## Migration Policy

Customer API keys remain supported for control-plane compatibility while CLI and
Docker migrate token issuance to service-account or OIDC workload identity
flows. Runner claim traffic must use short-lived run tokens. Set
`IDELIUM_RUN_TOKEN_REQUIRED_FOR_CLAIM=false` only during a bounded legacy
migration window.
