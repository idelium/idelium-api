# Idelium API Directives

These rules extend the workspace-level Idelium engineering directives.

## Directives

1. **Use English for documentation and source-code comments.** This includes PHPDoc,
   inline comments, validation messages, API descriptions, migration notes, test
   names, and operational documentation.
2. **Enforce tenant isolation on every query and mutation.** A valid login or API
   key does not authorize access to another customer's records. Scope lookup,
   update, delete, relationship loading, and background jobs by the authenticated
   customer before accessing the resource.
3. **Centralize authentication and authorization.** Resolve API keys in middleware,
   use Laravel policies or gates for resource authorization, and avoid duplicating
   ad-hoc role checks across controllers.
4. **Return only explicit response fields.** Do not use broad selections such as
   `users.*` for API responses. Password hashes, remember tokens, internal keys,
   and unrelated tenant fields must never be serialized.
5. **Use standard HTTP semantics.** Return appropriate `2xx`, `4xx`, and `5xx`
   status codes with a stable error schema. Do not invent non-standard application
   status codes.
6. **Validate at the boundary.** Use Form Requests or equivalent dedicated
   validation for types, ownership, formats, limits, and allowed values before
   invoking domain logic.
7. **Make destructive operations transactional.** Multi-table deletes, imports,
   cycle creation, and result recording must use database transactions and
   database-enforced referential integrity where possible.
8. **Test authorization negatively.** Feature tests must prove that customer A
   cannot read, update, or delete customer B's data, both through Sanctum and
   `Idelium-Key` endpoints.
9. **Keep schema changes reversible and deployable.** Migrations must have a safe
   rollback or an explicitly documented irreversible strategy. Avoid relying on
   demo seed data in normal application startup.
10. **Document and version the API contract.** Changes consumed by Idelium Web or
    Idelium CLI require contract tests, compatibility notes, and coordinated
    versioning when they are not backward compatible.

## Required verification

- Run the full PHPUnit suite with an isolated test database.
- Run tenant-isolation and role-authorization tests.
- Run static analysis and project formatting checks.
- Exercise migrations from an empty database and from the previous supported
  schema version.
- Verify representative API responses contain no hidden or cross-tenant fields.
