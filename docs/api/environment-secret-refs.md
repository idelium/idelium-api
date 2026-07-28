# Environment Secret References

Environment configuration must not persist plaintext secrets. Sensitive values
must be represented by opaque `secretRef` objects and resolved only by runtime
components that have an authorized tenant and project context.

## Current Contract

- New environment writes reject inline values for secret-like keys such as
  passwords, tokens, API keys, authorization headers, cookies, credentials, and
  session identifiers.
- API responses redact `secretRef` entries and secret-like keys as `[REDACTED]`.
- Existing stored plaintext secrets should be migrated to provider-backed
  references before production use.

## Example

```json
{
  "baseUrl": "https://example.test",
  "apiKey": {
    "secretRef": "tenant/demo/projects/webapp/secrets/api-key"
  }
}
```

## Remaining Roadmap Work

Runtime provider resolution, audit events for secret access, provider
availability errors, Web editing support, CLI resolution, and leakage tests for
logs/reports/screenshots/artifacts are tracked as cross-repository enterprise
roadmap work.
