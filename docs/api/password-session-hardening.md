# Password and Session Hardening

Idelium local authentication is moving toward a policy-driven model for
passwords, sessions, lockout, and auditability.

## Current Contract

- Server-side password changes must satisfy the configurable password policy in
  `config/password_policy.php`.
- The default API policy requires at least 12 characters, mixed case, a number,
  a symbol, and rejects a small built-in list of common passwords.
- Profile password changes require the current password. The API accepts both
  `currentPassword` and `current_password` during the migration window.
- Production session cookies are secure by default, remain HttpOnly, and use the
  configured SameSite policy.

## Migration Policy

The Web profile form must collect the current password before the profile
password-change flow can be considered complete across repositories.

Remaining roadmap work includes login rate limiting and lockout auditing,
session listing and revocation, reset-session invalidation, and administrator
capability checks.
