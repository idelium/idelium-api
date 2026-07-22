# Performed result contracts

Idelium API accepts performed-step results through `POST /api/ideliumcl/step`
and exposes them through tenant-scoped Web endpoints. Result payloads may use
legacy arrays or versioned runtime contracts.

## Common versioned envelope

```json
{
  "runtime": "postman",
  "schemaVersion": "postman.newman.v1",
  "executions": [],
  "assertions": [],
  "scriptFailures": [],
  "artifacts": [],
  "logs": []
}
```

The API accepts the result only when `runtime` matches `schemaVersion` and the
payload contains fields supported by that schema.

## Runtime fields

| Runtime | Schema | Supported result fields |
| --- | --- | --- |
| Selenium | `selenium.v1` | `assertions`, `artifacts`, `commandTrace`, `logs` |
| Selenium WebDriver | `selenium.webdriver.v2` | `assertions`, `artifacts`, `commandTrace`, `logs`, `networkEvents` |
| Appium 2 | `appium.v2` | `assertions`, `artifacts`, `commandTrace`, `logs`, `videos` |
| Postman safe runner | `postman.safe.v1` | `assertions`, `executions`, `requests`, `scriptFailures` |
| Newman | `postman.newman.v1` | `assertions`, `executions`, `requests`, `scriptFailures`, `console`, `logs` |

## Artifact policy

Inline artifacts are intentionally limited. Large screenshots, videos, traces,
and logs should be stored externally and represented as artifact references:

```json
{
  "type": "screenshot",
  "storage": "external",
  "uri": "artifact://runs/123/screenshot.png"
}
```

Current limits:

| Limit | Default |
| --- | --- |
| Full result payload | `1048576` bytes |
| Single inline artifact field | `262144` bytes |
| Artifact collection size | `50` items |

The limits are configurable with:

- `IDELIUM_RESULT_PAYLOAD_MAX_BYTES`
- `IDELIUM_ARTIFACT_INLINE_MAX_BYTES`
- `IDELIUM_ARTIFACT_COLLECTION_MAX_ITEMS`

Oversized inline artifacts are rejected with validation errors. External
artifact references remain tenant-scoped through the performed-result hierarchy.

## Redaction policy

The API redacts sensitive values before storing new performed-step results and
redacts legacy stored payloads before serving them. Redaction covers:

- authorization headers;
- cookies;
- access tokens and refresh tokens;
- API keys;
- passwords;
- secrets;
- session identifiers;
- sensitive URL query parameters;
- request and response bodies.

Redaction is deterministic and uses `[REDACTED]` or `[REDACTED BODY]`.
