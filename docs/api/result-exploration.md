# Result exploration API

Idelium exposes performed execution results through tenant-scoped endpoints used
by the Web console:

- `GET /api/admin/testcyclesperfomed/{testCycleId}`
- `GET /api/admin/testsperfomed/{performedTestCycleId}`

Both endpoints keep their legacy response shape when no pagination parameters are
provided: the response is a JSON array. This preserves existing Web and CLI
consumers.

## Server-side pagination

When `page` or `perPage` is provided, the endpoint returns a paginated contract:

```json
{
  "data": [],
  "meta": {
    "pagination": {
      "page": 1,
      "perPage": 25,
      "total": 0,
      "lastPage": 1,
      "sort": "date",
      "direction": "desc"
    }
  }
}
```

`perPage` is bounded to `1..100`. The API ignores unsupported sort columns and
falls back to the endpoint default so callers cannot inject arbitrary database
columns into the result query.

## Filters and sorting

Common query parameters:

| Parameter | Description |
| --- | --- |
| `page` | 1-based page number. Enables the paginated response contract. |
| `perPage` | Requested page size, bounded by the API. Enables the paginated response contract. |
| `status` | Optional integer status filter. |
| `sort` | Optional sort column from the endpoint allow-list. |
| `direction` | `asc` or `desc`; invalid values fall back to the endpoint default. |

Performed test cycles allow sorting by `id`, `date`, `status`, `created_at`, and
`updated_at`. Their default order is `date desc`.

Performed tests allow sorting by `id`, `name`, `status`, `created_at`, and
`updated_at`. Their default order is `id asc`.

## Tenant isolation and indexes

Every result exploration query is constrained by the authenticated user's
`idCostumer`. Result exploration indexes cover the tenant identifier, parent
result identifier, status, and stable sort column so large result sets can be
loaded without cross-tenant scans.

## Analytics windows and time zones

The Web console computes pass rate, failure rate, duration, queue time, failure
taxonomy, and flaky-test candidates from the result set currently available to
the authenticated tenant. URL-persisted analytics filters use:

| Parameter | Description |
| --- | --- |
| `analyticsWindow` | Aggregation window identifier, for example `7d`, `30d`, or `90d`. |
| `analyticsTimezone` | IANA time zone used to describe aggregation boundaries. |
| `status` | Status filter shared with result exploration views. |

Server-side aggregation endpoints can reuse the same parameter names to preserve
deep links and dashboard refresh behavior.

## Export descriptors

Large exports should be represented as asynchronous descriptors rather than raw
download URLs:

```json
{
  "format": "json",
  "status": "completed",
  "url": "/api/reports/44.json",
  "expiresAt": "2026-07-28T15:00:00Z"
}
```

The Web client only enables downloads for completed, same-origin descriptors
that have not expired. Future durable export job endpoints must keep the same
descriptor fields and enforce the same tenant and capability checks as result
exploration endpoints.
