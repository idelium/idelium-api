# Parallel run scheduling API

Idelium exposes tenant-scoped scheduling endpoints for coordinating parallel
test-cycle execution across runners. The contract is available to both the web
application through Sanctum and automation clients through `Idelium-Key`.

## Tenant scope

Every route includes the project id and resolves the project, test cycle, and
parallel run inside the authenticated customer before any mutation is applied.
Cross-tenant project ids, test-cycle ids, and run ids return `404` so resource
existence is not leaked.

## Web routes

- `POST /api/admin/projects/{idProject}/parallel-runs`
- `POST /api/admin/projects/{idProject}/parallel-runs/matrix`
- `GET /api/admin/projects/{idProject}/parallel-runs`
- `GET /api/admin/projects/{idProject}/parallel-runs/{parallelRun}`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/claim`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}/heartbeat`
- `PUT /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/cancel`
- `GET /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/results`

## CLI routes

- `POST /api/ideliumcl/projects/{idProject}/parallel-runs`
- `POST /api/ideliumcl/projects/{idProject}/parallel-runs/matrix`
- `GET /api/ideliumcl/projects/{idProject}/parallel-runs`
- `GET /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}`
- `POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/claim`
- `POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}/heartbeat`
- `PUT /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}`
- `POST /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/cancel`
- `GET /api/ideliumcl/projects/{idProject}/parallel-runs/{parallelRun}/results`

## Scheduling payload

```json
{
  "testCycleId": 42,
  "idempotencyKey": "release-2026-07-27-main",
  "requestedConcurrency": 4,
  "metadata": {
    "trigger": "ci",
    "commit": "abc123"
  }
}
```

`requestedConcurrency` is bounded to `1..32`. The idempotency key is unique for
the authenticated customer and project, so retrying the same schedule request
returns the existing run instead of creating duplicates.

## Matrix scheduling payload

Matrix scheduling expands validated platform, browser, device, and environment
axes into deterministic parallel-run schedules:

```json
{
  "testCycleId": 42,
  "idempotencyKey": "release-2026-07-27-main",
  "requestedConcurrency": 2,
  "matrix": {
    "platforms": ["linux"],
    "browsers": ["chrome", "firefox"],
    "environments": ["demo", "prod"]
  },
  "metadata": {
    "pipeline": "release"
  }
}
```

The API creates one schedule for each combination, up to 64 generated runs per
request. Each generated run receives an idempotency key derived from the client
key and the matrix combination. Retrying the same request returns the same
durable run identities and `runUrl` values.

## Run metadata

Run metadata is normalized under `metadata.run` at schedule creation time:

```json
{
  "run": {
    "build": "1042",
    "commit": "abc123",
    "branch": "main",
    "repository": "idelium/idelium-cli",
    "initiator": "ci",
    "pipeline": "release",
    "workloadIdentity": {
      "provider": "github-actions",
      "issuer": "https://token.actions.githubusercontent.com",
      "subject": "repo:idelium/idelium-cli:ref:refs/heads/main",
      "audience": "idelium"
    }
  }
}
```

Legacy top-level metadata fields with the same names are accepted and moved into
`metadata.run`. Sensitive claims such as tokens, API keys, credentials,
authorization headers, cookies, and passwords are removed before persistence.

The list endpoint supports exact-match filters for `build`, `commit`, `branch`,
`repository`, `initiator`, and `pipeline`.

## Worker lifecycle

Workers claim capacity with `POST .../claim` and a stable `workerId`. The API
returns `409` when the requested concurrency is already consumed and `422` when
the run is terminal.

Claimed workers receive a finite lease. The default lease is 120 seconds. Runners
can renew it with:

```json
{
  "leaseSeconds": 120
}
```

When a running worker misses its lease, the API deterministically marks that
worker as `lost`. Lost workers are no longer active owners for the run.

Workers update their status with:

```json
{
  "status": "completed",
  "result": {
    "tests": 12,
    "failed": 0,
    "durationMs": 18421
  }
}
```

Allowed worker statuses are `running`, `completed`, `failed`, `cancelled`, and
`lost`. Terminal run states reject further claims or updates.

## Aggregation

The result endpoint returns deterministic worker ordering by `workerId`. Failed
workers make the aggregate status failed, cancelled-only runs are cancelled, and
lost workers make the aggregate status lost when there are no failed workers.
All-completed runs are passed. Responses expose only explicit contract fields and
never serialize customer internals.
