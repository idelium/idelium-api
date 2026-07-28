# Operations and Deployment

## Docker role

In the Idelium Docker stack, `idelium-api` is the Laravel backend container. It
is normally paired with:

- a MariaDB/MySQL database;
- Idelium Web served behind the same HTTPS gateway;
- a queue worker for asynchronous jobs;
- optional object storage, mail, Redis, and integration services depending on deployment profile.

The local quick-start stack exposes Web and API through HTTPS. Browser requests
reach the API through `/api`, while CLI examples use `https://localhost` as the
base URL and append API paths internally.

## Required runtime

- PHP 8.2, 8.3, or 8.4.
- Composer 2.10.2.
- MariaDB/MySQL for normal runtime.
- SQLite is used for CI tests.
- Required PHP extensions for Laravel, database drivers, and coverage tooling in CI.

## Configuration classes

| Area | Important settings |
| --- | --- |
| Application | `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Browser sessions | `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_LIFETIME`, `SANCTUM_STATEFUL_DOMAINS` |
| CORS | `CORS_ALLOWED_ORIGINS` with explicit origins only |
| Queue/cache | `QUEUE_CONNECTION`, `CACHE_STORE` |
| Launcher TLS | `IDELIUM_LAUNCHER_CA_BUNDLE`, `IDELIUM_LAUNCHER_CONNECT_TIMEOUT`, `IDELIUM_LAUNCHER_TIMEOUT`, `IDELIUM_LAUNCHER_INSECURE` |
| Result/artifact limits | `IDELIUM_RESULT_PAYLOAD_MAX_BYTES`, `IDELIUM_ARTIFACT_INLINE_MAX_BYTES`, `IDELIUM_ARTIFACT_COLLECTION_MAX_ITEMS`, `IDELIUM_ARTIFACT_MAX_SIZE_BYTES` |
| Run tokens | `IDELIUM_RUN_TOKEN_TTL_SECONDS`, legacy migration flags where documented |

Never commit `.env`, application keys, database passwords, API keys, access
tokens, service-account secrets, run tokens, worker tokens, session identifiers,
or authorization headers.

## Migrations

Schema changes are Laravel migrations under `database/migrations`. Deployment
practice:

1. Back up the database.
2. Stop or drain background writers when a migration touches hot tables.
3. Run `php artisan migrate --force`.
4. Smoke test project, environment, step, test, cycle, login, and result-read paths.
5. Restart workers.

Rollback uses `php artisan migrate:rollback --step=1 --force` only when the
migration is explicitly reversible and the previous application version can read
the restored schema.

## Queue and background work

Queue workers process asynchronous integration deliveries, artifact purge work,
and future export or lifecycle jobs. Queue workers must run with the same code
version and secrets as the API container. Failed jobs should be inspected for
redacted error codes, not raw payload secrets.

## Quality gates

The repository CI runs:

- Composer metadata validation and locked dependency audit.
- Dependency installation from `composer.lock`.
- PHP syntax checks.
- Laravel Pint format check.
- Static analysis.
- Migration exercise.
- PHPUnit across supported PHP versions.
- Coverage generation and threshold enforcement.

Before declaring API work complete, run the relevant focused tests locally and
confirm GitHub Actions are green.

## Observability and safe failures

API diagnostics should preserve enough context to troubleshoot while redacting
sensitive values. External HTTP calls use finite timeouts and classified errors.
Remote launcher TLS failures, invalid integration destinations, oversized
artifacts, malformed runtime schemas, and foreign tenant access should fail
closed with standard HTTP status codes.

## Rollback notes

Rollback is safest when changes are additive and backward compatible. API, CLI,
configuration, database, and persisted-data changes must either remain backward
compatible or document a migration and release policy. For schema or payload
changes consumed by Web and CLI, coordinate deployment order and keep legacy
read paths until every client has migrated.
