# Account and Profile Response Contract

All endpoints in this document require Sanctum authentication.

## Account collection

`GET /api/admin/accounts` and the successful responses from account create, update,
and delete operations return an array of account objects.

Each account object contains only these fields:

| Field | Type | Description |
| --- | --- | --- |
| `id` | integer | Account identifier. |
| `email` | string | Account email address. |
| `name` | string | Account display name. |
| `role` | integer | Role identifier. |
| `idCostumer` | integer | Customer identifier used by the current API schema. |
| `costumer` | string | Customer display name used by the current API schema. |
| `roleName` | string | Role display name. |

Super administrators receive accounts across customers. Tenant administrators
receive only accounts from their own customer and do not receive super
administrator accounts.

## Profile

`GET /api/admin/profile` and a successful `PUT /api/admin/profile` return one
profile object with these fields:

| Field | Type | Description |
| --- | --- | --- |
| `email` | string | Authenticated account email address. |
| `name` | string | Authenticated account display name. |
| `companyName` | string | Customer display name. |
| `roleName` | string | Role display name. |

## Excluded fields

Account and profile responses must never contain password hashes, remember tokens,
access tokens, API keys, authentication-provider identifiers, verification state,
or database timestamps. New response fields require an explicit security review
and corresponding contract tests.
