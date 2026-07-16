# Test cycle ownership and responses

The authenticated test-cycle endpoints scope every project and test-cycle lookup
to the authenticated customer's identifier. A project or test cycle owned by a
different customer is treated as unavailable and returns `404 Not Found`.

Creating a test cycle requires an `idProject` owned by the authenticated customer.
An inaccessible or invalid project identifier returns `422 Unprocessable Entity`
with an `idProject` validation error.

Successful test-cycle detail responses expose only these fields:

- `id`
- `name`
- `description`
- `config`
- `idProject`

The internal customer identifier and database timestamps are not serialized.

## Compatibility note

Inaccessible test-cycle detail and update requests previously returned a
non-standard `555` response in some paths. They now consistently return standard
`404` or `422` responses so clients can use normal HTTP error handling.
