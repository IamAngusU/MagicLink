# Integration

[Deutsch](INTEGRATION.de.md) · **English**

## Same PHP application

`bootstrap.php` starts MagicLink and the same PHP session. After a successful
exchange, `Session::email()` returns the normalized identity:

```php
<?php
$app = require __DIR__ . '/magic-link/bootstrap.php';

$email = IamAngusU\MagicLink\Session::email();
if ($email === null) {
    header('Location: /login');
    exit;
}
```

The email is an identity key, not a role decision. Authorization remains the
host application's responsibility.

## Custom or separate browser UI

Use the versioned endpoints in [API.md](API.md). Start with `/api/v1/config`, use
the advertised absolute endpoint URLs, state catalogue and limits, copy its CSRF
token to state-changing requests, and always include credentials.

For another origin, configure an exact `API_ALLOWED_ORIGINS`, HTTPS and
`SESSION_SAMESITE=None`; set `AUTH_SUCCESS_URL` when needed. Do not copy session
cookies between hosts. The state API is display data only: it exposes no email or
token and a `verified` state is not an authentication grant.

## Separate backend: RP-initiated handoff

Enable the built-in confidential-client flow when another backend must create
its own session. It is OAuth-like, not a general OAuth/OIDC provider: one
confidential RP, one exact redirect URI and an email identity only. The RP still
owns roles and authorization.

```dotenv
HANDOFF_REDIRECT_URL=https://app.example.com/auth/magic-link
HANDOFF_CLIENT_SECRET=<base64-encoded 32 random bytes>
HANDOFF_TRANSACTION_TTL_SECONDS=900
HANDOFF_CODE_TTL_SECONDS=60
```

Generate the shared client secret once on a trusted machine:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Store it only in the private environments of MagicLink and the relying-party
(RP) backend. It never belongs in JavaScript, HTML, a query string, a public
repository or client-visible config.

### 1. Start on the RP backend

Generate state from at least 32 random bytes and create a PKCE S256 pair. Store
the expected state and verifier against the initiating RP browser session—not in
a global lookup detached from that browser:

```php
<?php
session_start();

$base64url = static fn (string $raw): string =>
    rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

$state = $base64url(random_bytes(32));
$verifier = $base64url(random_bytes(32));
$challenge = $base64url(hash('sha256', $verifier, true));

$_SESSION['magiclink_state'] = $state;
$_SESSION['magiclink_verifier'] = $verifier;
```

From that backend, call `POST /api/v1/handoffs/transactions` with the bearer
client secret:

```json
{
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "state": "<state>",
  "code_challenge": "<challenge>",
  "code_challenge_method": "S256"
}
```

Redirect the browser to the returned `data.authorize_url`. Do not construct this
URL yourself. Only the S256 challenge leaves the RP; the verifier stays on its
server with that transaction.

### 2. Complete MagicLink in the same browser

The authorization URL binds the transaction to that MagicLink browser session.
If it is anonymous, MagicLink shows its normal sign-in. The user must open the
email link in the same browser; a different browser can authenticate itself but
cannot authorize the bound RP transaction: its `data.handoff` is `null`, so no RP
identity is granted. `unavailable` means the current session did have a pending
request, but it expired or failed validation. An already authenticated MagicLink
browser proceeds directly, which is why state and PKCE are mandatory rather than
optional ceremony.

After authentication, MagicLink redirects only to the exactly registered URI
with `code` and the original `state`. The email token remains in MagicLink and is
never reused as a backend credential.

If `magic_link.verified` reports `data.handoff.status=retryable`, login already
succeeded and the email token is consumed. Follow `retry_url` in that same
browser; do not submit the email token again. The retry recovers the existing
authorization result while it is valid.

### 3. Verify and exchange on the RP backend

At the callback, compare returned state with the server-session value using
`hash_equals`. Reject a missing or mismatched value before any exchange. Do this
and exchange the code before rendering HTML or loading third-party resources.
Then call `POST /api/v1/handoffs/exchange` from the server:

```json
{
  "code": "<code-from-callback>",
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "code_verifier": "<verifier-from-server-session>"
}
```

Authenticate that request with the same bearer client secret. A successful
response contains `data.email`. Rotate/create the RP session, assign authorization
there, delete the stored state and verifier, and respond with a 303 redirect to a
clean URL without `code` or `state`. The callback should send
`Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

The code is short-lived and single-use; the redirect URI and PKCE verifier must
match the original transaction. Replay, expiry or any mismatch intentionally
returns the same HTTP 410—do not vary UX by cause. Keep callback query strings
out of access and analytics logs. The state API never transfers identity or
session state; only this authenticated server exchange does. The old generic
`POST /api/v1/handoffs` route deliberately returns 404. Exact payloads and retry
behavior are in [API.md](API.md).

## Mail appearance

MagicLink supplies English and German defaults. For custom branding, copy
`resources/mail-templates` to a non-public local directory and set
`MAIL_TEMPLATE_DIR`; do not turn the template directory into an upload target.
See [configuration](CONFIGURATION.md#custom-mail-templates) for placeholders and
the no-send preview command.
