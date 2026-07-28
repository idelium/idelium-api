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
- `GET /api/admin/projects/{idProject}/parallel-runs`
- `GET /api/admin/projects/{idProject}/parallel-runs/{parallelRun}`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/claim`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}/heartbeat`
- `PUT /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/workers/{workerId}`
- `POST /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/cancel`
- `GET /api/admin/projects/{idProject}/parallel-runs/{parallelRun}/results`

## CLI routes

- `POST /api/ideliumcl/projects/{idProject}/parallel-runs`
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
