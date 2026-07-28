# Artifact Descriptors

Artifact descriptors define the authoritative server-side contract for
execution artifacts. They describe metadata, integrity, tenancy, lifecycle
state, and retention without exposing storage internals to the Web UI.

## Current Contract

- Artifacts are scoped by tenant, project, and performed test cycle.
- Supported descriptor types are JSON, JUnit, Markdown, HTML, screenshot, and
  log.
- Descriptors include content type, size, SHA-256 checksum, lifecycle state,
  retention timestamp, and storage key.
- The API rejects unsupported content types, invalid checksums, and artifacts
  larger than `IDELIUM_ARTIFACT_MAX_SIZE_BYTES`.
- Stable lifecycle states are `available`, `archived`, `expired`,
  `quarantined`, `unavailable`, and `deleted`.
- Legal hold is stored in descriptor metadata and prevents archive or deletion
  markers while enabled.
- Web clients should enable artifact actions only from these descriptors.

## Endpoints

- `GET /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts`
- `GET /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}`
- `GET /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/impact`
- `PUT /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/legal-hold`
- `POST /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/delete-marker`
- `POST /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/archive`
- `POST /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}/restore`

The legal-hold, archive, restore, and delete-marker endpoints require the
`artifacts.manage` capability and record audit events. Archive and delete-marker
transitions are rejected while legal hold is enabled. Archived artifacts can be
restored back to `available`; deleted artifacts cannot be archived or restored
through the reversible lifecycle path.

The impact endpoint requires `artifacts.read` and returns the artifact scope,
storage size, retention status, legal-hold status, lifecycle blockers, and the
actions currently allowed for that descriptor. Web clients should use it before
archive, restore, delete-marker, or hard-delete confirmation flows.

## Retention and purge operations

`php artisan artifacts:purge-expired` enqueues idempotent hard-delete jobs for
descriptors whose retention period has expired and whose lifecycle state is
`archived`, `deleted`, or `expired`. Legal hold prevents purge eligibility. Each
purge attempt records an `artifact.hard_delete` audit event with success or
failure result metadata. The command accepts `--limit` to bound each run.

Run the command from a trusted operational context only. The command is bounded,
repeatable, and safe to retry because each queued job re-checks tenant,
retention, lifecycle state, and legal-hold eligibility before deleting a
descriptor. For rollback, restore the descriptor rows and related audit trail
from the database backup that precedes the purge window. Physical object-store
deletion must follow the same descriptor idempotency key when a storage adapter
is introduced.

## Migration Policy

CLI upload and time-bounded download URLs remain roadmap work. Existing result
payloads remain backward compatible while CLI, Web, and Docker are migrated to
the descriptor contract.
