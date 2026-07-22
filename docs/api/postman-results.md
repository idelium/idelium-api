# Postman result contract

Idelium CLI records a Postman collection execution through
`POST /api/ideliumcl/step`. The request uses the normal `Idelium-Key` tenant
credential and sets:

- `type` to `postman`;
- `data` to a JSON array of request results;
- `screenshots` to a JSON array, normally empty for API tests.

Each result object uses these stable fields:

| Field | Type | Description |
| --- | --- | --- |
| `name` | string | Postman request name. |
| `method` | string | HTTP method. |
| `url` | string | Resolved request URL. |
| `status` | string | Actual HTTP status code. |
| `time` | number | Request duration in seconds. |
| `response` | string | Redacted response body. |
| `passed` | boolean | Overall status and body assertion result. |
| `assertions` | array | Individual assertion outcomes and messages. |

Older CLI versions may omit `passed` and `assertions`; readers must treat those
records as legacy results rather than successful assertions. New Newman-based
payloads should use `runtime: postman` and `schemaVersion: postman.newman.v1`.

Authenticated Web clients retrieve the hierarchy from the performed-cycle,
performed-test, and performed-step endpoints. Every query is scoped to the
authenticated customer's ID and returns an explicit field allow-list. A result
belonging to another customer is represented by an empty collection and must not
be disclosed.

The current contract is backward compatible with existing stored JSON. New
required fields or renamed fields require a new schema identifier and
coordinated CLI/Web release notes.

Sensitive headers, cookies, tokens, passwords, API keys, URLs, and response
bodies are redacted before new results are stored and before legacy results are
served back to Web clients.
