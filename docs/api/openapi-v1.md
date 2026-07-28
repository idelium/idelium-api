# OpenAPI v1 Contract

The versioned OpenAPI contract is published in
[`openapi-v1.yaml`](openapi-v1.yaml).

This first v1 slice documents the enterprise API surfaces currently consumed by
Idelium Web and Idelium CLI:

- parallel and matrix run scheduling;
- run metadata filters;
- agent registration and status management;
- asset version history and review transitions;
- asset impact analysis.

The contract is intentionally additive. Legacy routes remain available while Web
and CLI consumers migrate to the documented v1 response shapes. Sensitive claims
and credentials are not part of any request or response schema.
