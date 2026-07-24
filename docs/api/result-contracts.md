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
| Selenium WebDriver | `selenium.webdriver.v2` | `assertions`, `artifacts`, `bidiArtifacts`, `commandTrace`, `logs`, `networkEvents` |
| Selenium BiDi diagnostics | `selenium.bidi.diagnostics.v1` | `artifacts`, `bidiArtifacts`, `commandTrace`, `logs`, `networkEvents` |
| Appium 2 | `appium.v2` | `assertions`, `artifacts`, `commandTrace`, `logs`, `videos` |
| Postman safe runner | `postman.safe.v1` | `assertions`, `executions`, `requests`, `scriptFailures` |
| Newman | `postman.newman.v1` | `assertions`, `executions`, `requests`, `scriptFailures`, `console`, `logs` |

Versioned payloads are allow-listed at the top level. Fields outside the
declared schema are rejected before persistence so result readers can rely on a
bounded contract.

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
| Events per BiDi artifact | `100` items |

The limits are configurable with:

- `IDELIUM_RESULT_PAYLOAD_MAX_BYTES`
- `IDELIUM_ARTIFACT_INLINE_MAX_BYTES`
- `IDELIUM_ARTIFACT_COLLECTION_MAX_ITEMS`
- `IDELIUM_BIDI_ARTIFACT_MAX_EVENTS`

Oversized inline artifacts are rejected with validation errors. External
artifact references remain tenant-scoped through the performed-result hierarchy.

BiDi artifacts may use only these artifact MIME types:

- `application/vnd.idelium.bidi.console+json`
- `application/vnd.idelium.bidi.network+json`
- `application/vnd.idelium.bidi.diagnostics+json`

Each BiDi artifact must include a `data.schemaVersion` value of `1.0`. The API
accepts only the bounded artifact keys `name`, `type`, `path`, and `data`, and
only the bounded data keys `schemaVersion`, `totalEvents`, `droppedEvents`,
`truncated`, and `events`.

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
- BiDi diagnostic messages and text fields.

Redaction is deterministic and uses `[REDACTED]` or `[REDACTED BODY]`.
