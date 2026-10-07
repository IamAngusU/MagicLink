# Integration

`public/index.php` is a complete example app. The reusable boundary is the
server-side session set by `MagicLinkService` and exposed through
`Session::email()`.

## Same application

Mount MagicLink under a stable prefix such as `/login`, configure that exact
prefix in `APP_URL`, and require `bootstrap.php` before reading the session.
Use the returned, normalized email as an identity key; perform roles and resource
authorization in the host application.

## Separate application

PHP sessions are intentionally local. For another host or technology stack, do
not copy the session cookie or expose the database. Add an explicit, short-lived
handoff token scoped to the target application and consume it there once. Keep
that bridge separate from the email token so one credential cannot be replayed
across two trust boundaries.

## UI replacement

Templates live under `templates/`, while stable state codes live in
`src/MagicLinkState.php`. A custom UI should branch on the code, not on translated
copy. Preserve the fragment-to-POST exchange in `public/assets/app.js` and keep
the security headers in `App::securityHeaders()` when replacing the pages.
