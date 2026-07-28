# Plugin manifest contract

Idelium API stores plugins as versioned enterprise manifests while preserving the
legacy Web editor shape for source-code editing.

## Runtime manifest

The CLI-facing endpoint `GET /api/ideliumcl/plugin/{id}` returns `code` as a JSON
manifest with the following stable fields:

- `apiVersion`: currently `idelium-plugin/1.1`.
- `name`: plugin step name.
- `source`: Python source code.
- `sourceSha256`: SHA-256 digest of `source`.
- `approvalStatus`: `approved` or `unapproved`.
- `provenance.reviewed`: whether a reviewer approved the exact source digest.
- `capabilities`: currently constrained to `step`.
- `execution.mode`: must be `subprocess` for approved enterprise execution.
- `execution.timeoutSeconds`: bounded from 1 to 300 seconds.

The CLI must refuse execution when approval is missing, integrity does not match,
or the execution boundary is not subprocess-based.

## Web compatibility

The admin Web endpoint still returns `code` as `[source]` so the current editor can
load and save existing plugins without a migration break. When Web sends legacy
source code, the API wraps it into an `unapproved` enterprise manifest. This makes
the saved plugin visible and editable, but not executable by the enterprise CLI
until an approval process stores a reviewed manifest with a matching hash.

## Security rules

- Unapproved plugins are persisted but must not execute.
- Approved plugins require reviewed provenance and a matching SHA-256 source hash.
- Network and filesystem privileges are not granted by this manifest; runtime
  execution remains constrained by the CLI subprocess boundary.
- Tenant ownership is enforced by the existing project and plugin resource lookups.
