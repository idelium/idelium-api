# Project resource ownership

Projects, tests, steps, plugins, environments, and test cycles are scoped to the
authenticated customer on every list, detail, create, update, reorder, and delete
operation.

Requests for another customer's project or resource return `404 Not Found` and do
not reveal whether the identifier exists. Creating a project-owned resource with
an inaccessible `idProject` returns `422 Unprocessable Entity` with an
`idProject` validation error.

Detail responses contain only fields required by API clients. Internal customer
identifiers and database timestamps are not serialized.

## Compatibility note

Legacy project-resource endpoints returned a non-standard `555` status in some
ownership failure paths. These paths now return standard `404` or `422` responses.
Clients should use standard HTTP status handling and must not depend on `555`.
