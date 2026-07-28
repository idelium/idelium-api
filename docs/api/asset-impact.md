# Asset Impact API

Idelium exposes a read-only impact endpoint so Web clients can show what would
be affected before changing, deprecating, archiving, or deleting a QA asset.

## Endpoint

`GET /api/admin/projects/{idProject}/asset-impact/{assetType}/{assetId}`

Supported asset types:

- `environment`
- `plugin`
- `step`
- `test`
- `test_cycle`

The endpoint requires the `resources.read` capability and is scoped by both
tenant and project. Foreign projects return `404`.

## Response contract

The response contains:

- `asset`: the requested asset type and ID.
- `summary`: counts for impacted tests and test cycles.
- `tests`: tests whose configuration references the asset.
- `testCycles`: test cycles that directly reference the asset or indirectly
  reference impacted tests.

This first implementation derives dependencies from stored JSON configuration at
query time. A future indexing pass can persist dependency edges for larger
installations without changing the public response contract.
