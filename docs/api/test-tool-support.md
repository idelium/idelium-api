# Test tool support contract

Idelium API stores projects, environments, steps, tests, cycles, and performed
results. It does not execute Selenium, Appium, or Postman directly. Runtime
execution belongs to `idelium-cli`; design-time step creation belongs to
`idelium-web`.

## Schema versioning

New test-tool payloads may declare explicit schema metadata with `runtime` and
`schemaVersion`. The API currently accepts these schema identifiers:

- `selenium.v1`
- `selenium.webdriver.v2`
- `selenium.bidi.diagnostics.v1`
- `appium.v2`
- `postman.safe.v1`
- `postman.newman.v1`
- `dsl.source.v1`
- `dsl.ast.v1`

Payloads without schema metadata are treated as legacy payloads and continue to
load for backward compatibility. Payloads that declare an unknown
`schemaVersion`, omit `runtime`, or declare a runtime that does not match the
schema are rejected with validation errors before persistence.

Runtime-specific validation is intentionally additive. Versioned step,
environment, and result payloads must contain at least one field that belongs to
the declared runtime contract, and versioned payloads may not include top-level
fields outside the declared contract. Detailed migration rules are documented in
[Test tool schema migration policy](schema-migration-policy.md), and performed
result fields are documented in [Performed result contracts](result-contracts.md).

## Selenium

Selenium steps are stored as JSON step definitions and reported back as
performed steps with type `selenium` or `seleniumOrAppium`. The API also exposes
the native Idelium JSON import endpoint, which creates reusable steps and a test
inside one database transaction.

The CLI is responsible for local browser drivers, Selenium Grid sessions,
browser capabilities, screenshots, and command execution.

WebDriver BiDi diagnostics are accepted as versioned performed-result artifacts
through `selenium.bidi.diagnostics.v1`. The API validates the BiDi artifact MIME
type, inner schema version, server-side event limit, and tenant-scoped read path
before exposing the redacted data to Web clients.

## DSL source and canonical AST

The API accepts versioned DSL step payloads without changing the legacy `steps`
table contract:

```json
{
  "runtime": "dsl",
  "schemaVersion": "dsl.source.v1",
  "languageVersion": "1.0",
  "source": "idelium 1.0\n\ntest \"Login\" { open \"https://example.invalid\" }\n"
}
```

Canonical AST payloads use the same runtime and language version with
`schemaVersion: dsl.ast.v1` and an `ast` object whose root is a v1 canonical
document:

```json
{
  "runtime": "dsl",
  "schemaVersion": "dsl.ast.v1",
  "languageVersion": "1.0",
  "ast": {
    "kind": "document",
    "schemaVersion": "1.0",
    "languageVersion": "1.0",
    "tests": []
  }
}
```

Boundary validation rejects missing source text, unsupported DSL versions,
malformed AST roots, empty AST test sets, oversized DSL source payloads, and
fields outside the declared schema. Project ownership validation still scopes
every write to the authenticated tenant before persistence.

Performed DSL executions are stored with performed-step type `dsl`. Their
result payload remains backward-compatible with the legacy result shape until a
versioned DSL result schema is introduced.

## Appium

Appium steps are stored with the same step/test/cycle model used by Selenium.
Mobile environment configuration is stored in the environment `config` payload,
including `isRealDevice`, `appiumServer`, and `appiumDesiredCaps`.

The CLI is responsible for connecting to Appium, selecting the appropriate
driver options, executing mobile commands, and normalizing command results before
posting performed results.

## Postman

Postman collection executions are posted as performed steps with type `postman`.
The `data` payload contains redacted request results and assertion outcomes. The
API validates the payload shape, stores it under the authenticated customer, and
serves it back only through tenant-scoped performed-result endpoints.

The built-in CLI runner executes a deterministic subset of Postman Collection
v2.1. It does not execute arbitrary `pm.*` scripts. Use Newman outside Idelium
when full Postman runtime compatibility is required.
