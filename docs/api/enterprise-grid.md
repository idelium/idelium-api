# Enterprise grid API contract

Enterprise grid endpoints keep legacy array responses unless the caller provides
`page` or `pageSize`. This lets existing Web views migrate one grid at a time
without breaking older consumers.

## Query parameters

| Parameter | Description |
| --- | --- |
| `page` | 1-based page number. Enables the grid response contract. |
| `pageSize` | Requested page size, bounded to `1..100`. Enables the grid response contract. |
| `search` | Optional bounded text search across endpoint-approved columns. |
| `sort` | Optional sort column from the endpoint allow-list. |
| `direction` | `asc` or `desc`; invalid values fall back to the endpoint default. |
| `filter[key]` | Optional endpoint-approved exact-match filter. Comma-separated values are supported. |

Unsupported sort columns and filter keys are ignored or fall back to endpoint
defaults. User input never controls arbitrary database column names.

## Response

When enabled, the response uses this shape:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "pageSize": 25,
    "total": 0,
    "lastPage": 1,
    "hasNextPage": false,
    "hasPreviousPage": false,
    "sort": "order",
    "direction": "asc",
    "stale": false,
    "partial": false
  }
}
```

`stale` and `partial` are always present so Web components can render consistent
loading, stale, partial, empty, and error states.

## Supported endpoints

### Steps

`GET /api/admin/steps/{idProject}` supports the enterprise grid contract.

Allowed search columns:

- `name`
- `description`

Allowed sort columns:

- `id`
- `name`
- `description`
- `order`
- `created_at`
- `updated_at`

Allowed filters:

- `id`
- `name`

### Tests

`GET /api/admin/tests/{idProject}` supports the enterprise grid contract.

Allowed search columns:

- `name`
- `description`

Allowed sort columns:

- `id`
- `name`
- `description`
- `created_at`
- `updated_at`

Allowed filters:

- `id`
- `name`

All queries are scoped to the authenticated tenant and validated project.
