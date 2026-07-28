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
- `claimWorker` consumes a token once when `Idelium-Run-Token` is provided.
- Replay, wrong-agent, expired, revoked, and wrong-run attempts fail with a
  stable validation error.

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

## Remaining Roadmap Work

Customer API keys are still supported for backward compatibility while CLI and
Docker migrate. Full mTLS or equivalent workload identity, token revocation
endpoints, and audit events for issuance/use/rejection remain open roadmap work.
