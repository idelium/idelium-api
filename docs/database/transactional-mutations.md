# Transactional mutations

## Inventory

The API has three operations that write or delete multiple records:

- Selenium import creates multiple steps and one test.
- Step reordering updates multiple step positions.
- Project deletion removes the project hierarchy and its recorded results.

All three operations run inside a database transaction. CLI result-recording
endpoints create or update one record per request, so each request is already an
atomic single-row operation.

The core project and result hierarchy is also protected by foreign keys with
`ON DELETE CASCADE`. Application-level deletion remains explicit so deployments
can be rolled out safely while database migrations are being coordinated.

## Deployment

1. Back up the database and stop background writers.
2. Check for orphaned relationship values using left joins from each child table
   listed in the `2026_07_15_120000_add_core_mutation_foreign_keys` migration to
   its parent table. Resolve every row for which the parent ID is null.
3. Run `php artisan migrate --force`. The migration repeats the orphan preflight
   and aborts before adding any constraint if inconsistent data is found.
4. Run a project create/import/delete smoke test and restart background writers.

The integer relationship columns are upgraded to unsigned big integers to match
Laravel primary keys. Confirm that schema changes fit the maintenance window on
large production tables.

## Rollback

Stop writers, deploy the previous application version, and run
`php artisan migrate:rollback --step=1 --force`. The reverse migration removes
the foreign keys and restores the previous integer column types. Restore the
backup only if the application deployment itself wrote incompatible data.
