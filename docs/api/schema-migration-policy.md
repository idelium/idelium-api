# Test tool schema migration policy

Idelium API stores test-tool configuration and execution results as JSON
payloads. New runtime-aware payloads should include both:

- `runtime`: one of `selenium`, `appium`, or `postman`;
- `schemaVersion`: one of the supported schema identifiers documented in the
  test tool support contract.

## Compatibility guarantees

Legacy payloads without `runtime` and `schemaVersion` remain readable and
writable. The API treats them as legacy contracts and does not rewrite them
during normal reads. When a legacy performed result is returned by an
authenticated Web endpoint, sensitive fields are redacted in the response.

Versioned payloads are validated before persistence. Unknown schema versions,
runtime/schema mismatches, and payloads that do not contain any runtime-specific
contract fields are rejected with validation errors.

## Migration behavior

Schema migration is additive:

1. A new schema identifier is added to the API registry.
2. Idelium CLI and Idelium Web may start sending the new schema.
3. Existing records continue to use their original payload shape.
4. A later release may introduce an offline data migration only when a schema
   must become mandatory.

Required-field additions, field renames, removed fields, or semantic changes
must use a new schema identifier and release notes. They must not silently
change the meaning of an existing schema.

## Deprecation policy

A schema may be marked deprecated only after a compatible replacement exists in
API, CLI, and Web. Deprecation notes must include:

- replacement schema identifier;
- affected runtimes;
- migration guidance for stored payloads;
- minimum compatible CLI and Web versions;
- rollback notes.

Deprecated schemas must remain readable for at least one minor release unless a
security issue requires immediate removal.
