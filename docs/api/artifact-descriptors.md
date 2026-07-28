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
- Stable lifecycle states are `available`, `expired`, `quarantined`,
  `unavailable`, and `deleted`.
- Web clients should enable artifact actions only from these descriptors.

## Endpoints

- `GET /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts`
- `GET /api/admin/projects/{idProject}/performed-test-cycles/{performedTestCycleId}/artifacts/{artifactDescriptor}`

## Migration Policy

CLI upload and time-bounded download URLs remain roadmap work. Existing result
payloads remain backward compatible while CLI, Web, and Docker are migrated to
the descriptor contract.
