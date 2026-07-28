# Idelium API Wiki

Idelium API is the Laravel backend for the Idelium test automation platform. It
stores and governs the product data used by Idelium Web and Idelium CLI:
projects, environments, plugins, reusable steps, tests, test cycles, platform
catalogues, run schedules, execution results, artifacts, identities, and audit
events.

In the Docker stack, Idelium API is the backend service behind the `/api` path of
the local HTTPS gateway. Idelium Web calls it with a Sanctum browser session;
Idelium CLI and execution agents call it with dedicated machine credentials,
service-account credentials, and short-lived run tokens depending on the API
area.

## What Idelium API does

- Serves the browser administration console used to design and operate automated tests.
- Provides tenant-scoped CRUD APIs for projects, environments, plugins, steps, tests, and cycles.
- Stores Selenium, Appium, Postman, and Idelium DSL configuration payloads with schema-version compatibility rules.
- Receives performed run, test, and step results from Idelium CLI.
- Exposes result exploration APIs for the Web console, including Postman/Newman execution details.
- Coordinates parallel execution through schedules, workers, leases, run tokens, and runner heartbeats.
- Manages enterprise security capabilities: tenant context, service accounts, MFA, SSO/OIDC/SAML, SCIM, agent registration, audit events, artifact descriptors, asset versioning, and integration deliveries.

## Wiki map

- [Architecture](Architecture): deployment topology, request flow, module boundaries, and data model.
- [Authentication and Tenancy](Authentication-and-Tenancy): browser sessions, CLI keys, service accounts, run tokens, tenant context, capabilities, and redaction.
- [API Reference](API-Reference): full route table generated from Laravel.
- [API Domains](API-Domains): detailed explanation of every functional API area.
- [Enterprise Grid and Results](Enterprise-Grid-and-Results): server-side grids, result exploration, Postman result contracts, and exports.
- [Operations and Deployment](Operations-and-Deployment): Docker runtime, configuration, migrations, quality gates, queues, and rollback notes.

## Source of truth

The authoritative implementation is the code in [`routes/api.php`](https://github.com/idelium/idelium-api/blob/main/routes/api.php), controllers under [`app/Http/Controllers`](https://github.com/idelium/idelium-api/tree/main/app/Http/Controllers), request validators under [`app/Http/Requests`](https://github.com/idelium/idelium-api/tree/main/app/Http/Requests), and services under [`app/Services`](https://github.com/idelium/idelium-api/tree/main/app/Services). The detailed technical notes in [`docs/api`](https://github.com/idelium/idelium-api/tree/main/docs/api) remain the repository-level design records.
