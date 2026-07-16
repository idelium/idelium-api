# Browser session authentication

The browser client authenticates with an opaque Laravel session cookie. The
cookie is `HttpOnly`, `Secure`, and `SameSite=Lax`, so browser JavaScript cannot
read session credentials.

The login flow is:

1. `GET /api/sanctum/csrf-cookie`
2. `POST /api/login` with `email`, `password`, and an optional reCAPTCHA token
3. authenticated API requests with browser credentials enabled
4. `POST /api/logout` to invalidate the server-side session

Successful login responses contain an `authenticated` flag and explicit public
user fields. They do not contain a bearer token or session identifier.

Allowed credentialed origins are configured through the comma-separated
`CORS_ALLOWED_ORIGINS` setting. Wildcard origins are not supported when cookies
are enabled.

## Compatibility note

Browser clients must stop reading `access_token` and `session` from the login
response. The previous `GET /api/logout` endpoint has been replaced with the
authenticated `POST /api/logout` endpoint. Idelium-Key CLI endpoints are not
affected by this browser-session change.
