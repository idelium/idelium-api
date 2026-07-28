# Enterprise Grid and Results

## Enterprise grid contract

Grid-aware endpoints preserve legacy array responses unless a client supplies
`page` or `pageSize`. When enabled, responses use:

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
    "sort": "created_at",
    "direction": "asc",
    "stale": false,
    "partial": false
  }
}
```

Supported parameters:

| Parameter | Description |
| --- | --- |
| `page` | 1-based page number. Enables grid mode. |
| `pageSize` | Bounded to `1..100`. Enables grid mode. |
| `search` | Bounded text search across endpoint-approved columns. |
| `sort` | Endpoint-approved sort column. Invalid values fall back to default. |
| `direction` | `asc` or `desc`; invalid values fall back to default. |
| `filter[key]` | Endpoint-approved exact-match filters; comma-separated values are accepted. |

Current grid-enabled endpoints:

| Endpoint | Search columns | Sort columns | Filters |
| --- | --- | --- | --- |
| `/api/admin/projects` | `name`, `description` | `id`, `name`, `description`, `created_at`, `updated_at` | `id`, `name` |
| `/api/admin/steps/{idProject}` | `name`, `description` | `id`, `name`, `description`, `order`, `created_at`, `updated_at` | `id`, `name` |
| `/api/admin/tests/{idProject}` | `name`, `description` | `id`, `name`, `description`, `created_at`, `updated_at` | `id`, `name` |
| `/api/admin/testcycles/{idProject}` | `name`, `description` | `id`, `name`, `description`, `created_at`, `updated_at` | `id`, `name` |

The API ignores unsupported filter keys and never lets user input become an
arbitrary database column name.

## Result exploration

Performed result endpoints are optimized for the Web execution console. They
support legacy array responses and paginated responses when `page` or `perPage`
is present:

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

Result queries are tenant-scoped and indexed by tenant, parent result, status,
and stable sort columns.

## Runtime result contracts

Performed-step results may be legacy payloads or versioned runtime contracts.
Supported runtime schemas include:

| Runtime | Schema identifiers |
| --- | --- |
| Selenium | `selenium.v1`, `selenium.webdriver.v2`, `selenium.bidi.diagnostics.v1` |
| Appium | `appium.v2` |
| Postman | `postman.safe.v1`, `postman.newman.v1` |
| Idelium DSL | `dsl.source.v1`, `dsl.ast.v1` |

Versioned payloads are allow-listed. Unknown schemas, runtime/schema mismatch,
and oversized inline artifacts are rejected.

## Postman/Newman results

Postman results can contain request executions, assertions, script failures,
console/log data, request metadata, and redacted response payloads. Web clients
should show the request name, method, URL, status, assertion totals, diagnostic,
time, and a controlled response viewer.

Response bodies may be redacted when the policy detects sensitive content or
when size limits apply. Redaction is a feature: it prevents passwords, tokens,
cookies, and authorization material from being exposed through result views.

## Export descriptors

Result export endpoints create durable report descriptors and expose download
links through tenant-scoped authorization. Export formats are managed by the API
contract and should remain stable for automation pipelines.
