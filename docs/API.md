# Headless API v1

[Deutsch](API.de.md) · **English**

The bundled UI uses the same API as a custom client. Every response has one
stable envelope:

```json
{
  "ok": true,
  "code": "request.accepted",
  "data": {},
  "error": null,
  "meta": {
    "api_version": "v1",
    "request_id": "…",
    "server_time": "2026-10-08T10:00:00+00:00",
    "poll_after_ms": 2500
  }
}
```

On failure, `data` is `null`; `error` contains `message` and, when relevant,
`retry_after`. Branch on `code`, never on translated message text.

## Browser flow

Browser calls send cookies (`credentials: "include"`). State-changing browser
requests copy the token from `GET /api/v1/config` into `X-CSRF-Token`. An
optional `X-Request-ID` of 8–64 safe characters is returned in the response and
logs; otherwise the server creates one.

1. `GET /api/v1/config`
2. `POST /api/v1/requests` with `{ "email": "you@example.com" }`
3. Poll `GET /api/v1/state?id=…` after `meta.poll_after_ms`
4. The email opens `/auth/check`; that page removes the fragment from the URL and
   consumes it through `POST /api/v1/exchange`
5. Only the link browser receives the authenticated MagicLink session

The requesting browser may observe `verified`, but it receives neither an email
address nor an authentication grant. Never turn a state response into a local
login.

## Endpoints

### `GET /api/v1/config`

Returns the app name and locale, CSRF token, absolute endpoint URLs, state
catalogue, feature flags and effective public limits. No secrets are returned.
Check `features.server_handoff` before offering a handoff action.

### `POST /api/v1/requests`

```json
{ "email": "you@example.com" }
```

Returns HTTP 202 with `id`, `state`, `masked_email` and `expires_at`. Allowed and
disallowed identities have the same public response shape and queue path; only
allowed entries are delivered. The IP, identity and global fixed-window budgets,
plus pending-mail capacity, may return HTTP 429.

### `GET /api/v1/state?id=ml_…`

```json
{
  "id": "ml_…",
  "state": "waiting",
  "message": "…",
  "verified": false,
  "terminal": false,
  "expires_at": 1791450000,
  "authenticated": false
}
```

The request ID must belong to the current browser session. This response never
contains the email address or secret token and never authenticates the polling
browser. Poll no sooner than `meta.poll_after_ms`.

### `POST /api/v1/states`

```json
{ "ids": ["ml_…", "ml_…"] }
```

Reads several session-owned requests in one database query. The maximum is
published as `limits.state_batch_max`; the recommended polling interval grows
with the batch. The request requires CSRF protection.

### `POST /api/v1/exchange`

```json
{ "id": "ml_…", "token": "fragment-secret" }
```

Atomically consumes the email token and rotates the session ID. The bundled
`/auth/check` page normally calls it after removing the URL fragment. If this same
browser previously opened an RP-created `authorize_url`, MagicLink also completes
that pending transaction: `data.redirect` carries the authorization `code` and
original `state` to the registered callback, while `data.handoff` reports
`authorized`, `retryable`, or `unavailable`. The email token is never forwarded.

### `GET /api/v1/session`

Returns `authenticated` and, only for the session that actually completed the
link exchange, the normalized `email`.

### `POST /api/v1/logout`

Destroys the MagicLink session. Requires the CSRF header.

## Optional server handoff

This is an OAuth-like confidential-client authorization-code flow, not a general
OAuth or OpenID Connect provider. It supports one configured RP, one exact
redirect URI and an email identity only—never roles or authorization. It is
disabled unless both handoff settings are configured. The RP initiates every
transaction; a MagicLink browser cannot mint an unsolicited identity handoff.
The former browser-minting `POST /api/v1/handoffs` route deliberately returns
404.

### `POST /api/v1/handoffs/transactions`

Server-to-server request authenticated by the bearer client secret:

```http
Authorization: Bearer <HANDOFF_CLIENT_SECRET>
Content-Type: application/json

{
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "state": "43-to-128-character-base64url-value",
  "code_challenge": "base64url-sha256-of-code-verifier",
  "code_challenge_method": "S256"
}
```

The redirect URI must exactly match `HANDOFF_REDIRECT_URL`; only PKCE S256 is
accepted. HTTP 201 returns `authorize_url` and `expires_at`. Generate state from
at least 32 random bytes. The RP stores that expected state and the 43–128
character PKCE verifier against the initiating RP browser session, then sends the
browser to `authorize_url`. A global state lookup is not a safe substitute for
that browser-session binding. Only the S256 challenge leaves the RP backend.

### `GET /api/v1/handoffs/authorize?request=…`

The browser follows the opaque URL returned above. It binds that transaction to
the current MagicLink browser session. An anonymous browser is redirected to the
normal sign-in page; an already authenticated browser proceeds directly to the
registered callback. The email link must be completed in this same browser for
the pending transaction to receive an identity. Opening it elsewhere may create
a local MagicLink session there, but `data.handoff` is `null` and the RP receives
no identity grant. `unavailable` instead means this session had a pending request
that expired or failed validation. Because an existing MagicLink session can
authorize immediately, state and PKCE remain mandatory.

### `POST /api/v1/handoffs/authorize`

Completes the pending transaction for an already authenticated MagicLink
browser. It requires the session cookie and CSRF header and returns `redirect`
plus `expires_at`. This is the explicit retry/custom-UI endpoint; normal sign-in
completes the transaction as part of `POST /api/v1/exchange`. A transient handoff
failure returns `handoff.retryable` with HTTP 503, `Retry-After` and
`meta.retry_url`; authentication itself remains successful and the magic token
is already consumed. Follow that URL—never reuse the email token. A successful
retry recovers the existing authorization result while it remains valid.

### `POST /api/v1/handoffs/exchange`

```http
Authorization: Bearer <HANDOFF_CLIENT_SECRET>
Content-Type: application/json

{
  "code": "browser-visible-one-time-code",
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "code_verifier": "server-held-original-pkce-verifier"
}
```

The callback first compares returned `state` with its server-held expected value,
then calls this server-to-server endpoint. A valid exchange returns the normalized
`email`; the code is short-lived and single-use, and the redirect URI and PKCE
verifier must match the original transaction. Any mismatch, expiry or replay
returns the same HTTP 410; clients must not vary their UX by the hidden cause.

Keep `HANDOFF_CLIENT_SECRET` and the PKCE verifier out of the browser, source
control, logs and URLs. Exchange immediately, create the RP's own session, delete
the stored state/verifier and redirect to a clean URL without `code` or `state`.
Validate and exchange before rendering HTML or loading third-party resources;
callbacks should send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`
and exclude query strings from logs. The state endpoints remain observation-only
and cannot transfer an identity or session to the RP.

## State values

| State | Terminal | Meaning |
| --- | --- | --- |
| `requested` | no | Request accepted |
| `waiting` | no | Active link or enumeration-safe decoy |
| `verified` | yes | Token consumed on the link device |
| `expired` | yes | Expired or replaced by a newer link |
| `replayed` | yes | Internal replay state |
| `rate_limited` | no | Budget exhausted; respect `Retry-After` |
| `denied` | yes | Internal allowlist state, publicly neutralized |
| `failed` | yes | Invalid transition |

## Separate UI and CORS

```dotenv
API_ALLOWED_ORIGINS=https://app.example.com
SESSION_SAMESITE=None
AUTH_SUCCESS_URL=https://app.example.com/signed-in
```

Origins are compared exactly; wildcards are not supported. In production the
backend, allowed origins and success URL must use HTTPS. For a UI on the same
site, keep the safer `Lax` default. HTTP 429 clients should respect both
`Retry-After` and `meta.poll_after_ms`; body size and JSON depth are bounded on
the server.
