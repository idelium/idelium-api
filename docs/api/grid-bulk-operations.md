# Enterprise grid bulk operations

Enterprise grid bulk operations use an immutable, expiring server-side query
snapshot. The client never submits an unbounded list of IDs for an all-results
operation.

## Flow

1. `POST /api/admin/grid/query-snapshots` validates the resource and canonical
   query, applies the active tenant scope, resolves at most 1,000 entity IDs, and
   returns a snapshot ID, count, and expiry.
2. `POST /api/admin/grid/bulk-jobs` accepts the snapshot ID, an approved action,
   and action-specific input. The API rechecks the actor, tenant, expiry,
   capability, and current ownership of every target.
3. `GET /api/admin/grid/bulk-jobs/{jobId}` returns bounded progress and outcome
   metadata. It never returns the query or full snapshot.
4. A completed export is downloaded from
   `GET /api/admin/grid/bulk-jobs/{jobId}/export`.

The initial resource implementation supports projects with `archive`, `tag`, and
`export`. Project exports contain explicit fields only and neutralize spreadsheet
formula prefixes.

## Security boundaries

- Snapshots and jobs are bound to the active tenant and creating actor.
- Snapshots expire after 15 minutes and contain at most 1,000 authorized IDs.
- Archive and tag require `projects.manage`; export accepts `projects.read` or
  `projects.manage`.
- Ownership is checked again inside the mutation transaction.
- Audit events contain counts and status only. They do not contain entity data,
  filters, credentials, authorization headers, or exported content.
- Missing targets produce a partial outcome with same-tenant IDs so the client can
  provide retry guidance without exposing another tenant.

## Compatibility, rollout, and rollback

Existing project routes remain compatible. Archived projects are excluded from the
active project listing; existing rows start with `archivedAt = null` and
`tags = null`. Rollout requires applying migration
`2026_07_29_090000_create_grid_bulk_operation_tables`.

Rollback must first stop new bulk jobs. Reverting the application restores the
legacy UI. Rolling back the migration removes job history, tags, and archive state,
so production rollback should export operational records first or retain the
schema until the rollback window closes.
