# Asset Versioning

Idelium creates immutable version records for QA assets so executions can later
reference the exact definitions that were approved and used.

## Current Contract

- `asset_versions` stores append-only snapshots for `step`, `test`,
  `test_cycle`, and `environment` assets.
- Create and update operations record a new version with tenant, project, asset
  type, asset ID, version number, actor, timestamp, reason, and snapshot.
- Asset versions cannot be updated or deleted through the `AssetVersion` model.
- The current migration window assigns default reasons such as
  `asset.created` and `asset.updated` when the caller does not provide one.
- Parallel run schedules persist an `executionSnapshot` inside `metadata`. The
  snapshot records the latest known test-cycle version and the latest known
  versions for tests, steps, and environments referenced by the test-cycle
  configuration.

## Remaining Roadmap Work

Approval state, diff, rollback, import/export version contracts, richer CLI
snapshot delivery, and Web history views remain open roadmap work.
