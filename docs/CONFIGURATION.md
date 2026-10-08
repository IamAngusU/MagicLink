# Configuration

[Deutsch](CONFIGURATION.de.md) · **English**

`.env` is the user-facing control plane. Safe defaults are included; every
`auto` choice can be replaced with an explicit value.

## Application and database

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_ENV` | `production` | `production`, `local` or `test` |
| `APP_URL` | required | Canonical public backend URL, including a subpath |
| `APP_NAME` | `Magic Link` | Visible product name |
| `APP_LOCALE` | `de` | `de` or `en` |
| `AUTH_SUCCESS_URL` | empty | Absolute destination after a successful link, otherwise app root |
| `APP_KEY` | automatic | Base64-encoded 32 bytes, otherwise atomically stored in `storage/app.key` |
| `DB_DRIVER` | `sqlite` | `sqlite` or `mysql` |
| `DB_PATH` | `storage/database.sqlite` | SQLite file, relative to the install root or absolute |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | — | MySQL connection |
| `DB_SSL_MODE` | `auto` | `auto`, `verify_identity` or `disabled` |
| `DB_SSL_CA` | empty | Optional local CA file for verified MySQL TLS |

Back up `APP_KEY` and the database together. Anyone who can read both can decrypt
stored identities. In `auto`, providing a CA selects `verify_identity`; without a
CA it stays unencrypted only where policy permits. Remote production MySQL fails
closed unless `verify_identity` and a CA are configured; certificate and server
name are both checked. `disabled` is accepted only where production policy
allows a local database connection. MySQL uses the `mysqlnd` PDO backend; other
client backends are rejected so TLS capability stripping cannot depend on an
untested fallback policy.

## Optional server handoff

| Variable | Default | Purpose |
| --- | --- | --- |
| `HANDOFF_REDIRECT_URL` | empty | One registered, exact HTTPS callback URL |
| `HANDOFF_CLIENT_SECRET` | empty | Shared Base64-encoded 32-byte server credential |
| `HANDOFF_TRANSACTION_TTL_SECONDS` | `900` | RP authorization transaction lifetime, 120–3600 seconds |
| `HANDOFF_CODE_TTL_SECONDS` | `60` | One-time authorization code lifetime, 30–300 seconds |
| `HANDOFF_INIT_LIMIT` | `1000` | RP transaction starts per window |
| `HANDOFF_INIT_WINDOW` | `600` | Transaction-start window in seconds |
| `HANDOFF_RETENTION_SECONDS` | `86400` | Retain expired handoff rows for cleanup |

The redirect and secret must be set together. Production requires HTTPS; the URL
cannot contain credentials, a fragment or existing `code`/`state` query keys.
Generate the secret with:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Keep it only in the private environments of both servers. The relying-party (RP)
backend initiates each transaction with unpredictable `state` and a PKCE S256
challenge, binding expected state and verifier to the initiating RP browser
session. Its callback verifies `state`, exchanges the browser-visible code with
the server-held verifier and exact redirect URI, then redirects to a clean URL.
This is an OAuth-like handoff for one email-identity RP, not an OAuth/OIDC
provider or authorization system.
See [integration](INTEGRATION.md#separate-backend-rp-initiated-handoff).

## Access and lifetime

| Variable | Default | Boundary |
| --- | --- | --- |
| `MAGICLINK_ALLOWED_EMAILS` | empty | Comma-separated exact addresses |
| `MAGICLINK_ALLOWED_DOMAINS` | empty | Domains including their subdomains |
| `MAGICLINK_ALLOW_ANY_EMAIL` | `false` | Deliberately enable open registration |
| `MAGICLINK_TTL_SECONDS` | `900` | 120–3600 seconds |
| `MAGICLINK_RETENTION_SECONDS` | `604800` | 1 hour–365 days |
| `AUDIT_RETENTION_SECONDS` | `2592000` | 1 hour–365 days |
| `MAINTENANCE_INTERVAL_SECONDS` | `300` | Deterministic cleanup interval, 60–86400 seconds |

Without an allowlist the app refuses to start unless
`MAGICLINK_ALLOW_ANY_EMAIL=true` is explicitly set.

## Abuse and HTTP limits

| Variable | Default | Purpose |
| --- | --- | --- |
| `MAGICLINK_GLOBAL_LIMIT` | `1000` | Requests across the installation per window |
| `MAGICLINK_IP_LIMIT` | `10` | Requests per IP/window |
| `MAGICLINK_EMAIL_LIMIT` | `5` | Requests per identity/window |
| `MAGICLINK_RATE_WINDOW` | `3600` | Request window in seconds |
| `MAGICLINK_EXCHANGE_IP_LIMIT` | `60` | Exchanges per IP/window |
| `MAGICLINK_EXCHANGE_SELECTOR_LIMIT` | `10` | Attempts per link/window |
| `MAGICLINK_EXCHANGE_GLOBAL_LIMIT` | `1000` | Exchanges across the installation/window |
| `MAGICLINK_EXCHANGE_WINDOW` | `900` | Exchange window |
| `HTTP_MAX_BODY_BYTES` | `16384` | Maximum JSON/form body |
| `MAGICLINK_POLL_AFTER_MS` | `2500` | Base interval for state polling |

The global budget is consumed before email validation, then IP and identity
budgets narrow abuse further. Counters store HMAC-derived buckets rather than
plain identities.

The shipped Apache, Nginx and `.user.ini` examples enforce the 16 KiB default
before PHP parses a form. MagicLink accepts JSON and URL-encoded forms, not
multipart uploads. If you raise `HTTP_MAX_BODY_BYTES`, raise the matching
`LimitRequestBody`, `client_max_body_size`, `post_max_size` and
`upload_max_filesize` values too; the smallest layer wins.

## Mail and queue

| Variable | Default | Purpose |
| --- | --- | --- |
| `MAIL_TRANSPORT` | `mail` | `mail`, `smtp`, or `log` outside production |
| `MAIL_FROM_ADDRESS` | required | Envelope/from address |
| `MAIL_FROM_NAME` | app name | Visible sender |
| `MAIL_TEMPLATE_DIR` | empty | Optional local template pack relative to the app root |
| `SMTP_HOST`, `SMTP_PORT` | — / `587` | SMTP server and port |
| `SMTP_ENCRYPTION` | `tls` | `tls`, `ssl`, or `none` outside production |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | empty | Optional authentication |
| `MAIL_AUTO_DISPATCH` | `true` | Process one queue item after the HTTP response |
| `MAIL_WORKER_BATCH` | `auto` | SQLite 25, MySQL 100; maximum 250 |
| `MAIL_PENDING_MAX` | `auto` | Backpressure at 500 pending/sending rows on SQLite, 5000 on MySQL |
| `MAIL_MAX_ATTEMPTS` | `5` | 1–20 delivery attempts |
| `MAIL_LOCK_TIMEOUT_SECONDS` | `300` | Claim lease timeout and crashed-worker recovery |
| `MAIL_RETENTION_SECONDS` | `604800` | Retain completed queue rows |

Production rejects `log` mail and unencrypted SMTP. TLS certificates and host
names are verified, and SMTP is restricted to TLS 1.2 or newer. Response sizes,
lines and network time are bounded.

### Custom mail templates

Built-in German and English mail works without configuration. To brand it, copy
`resources/mail-templates` to `storage/mail-templates`, edit the copy, and set:

```dotenv
MAIL_TEMPLATE_DIR=storage/mail-templates
```

Each locale needs `subject.txt`, `plain.txt` and `html.html`. Templates are plain
UTF-8, never PHP. Body placeholders are `{{app_name}}`,
`{{expires_minutes}}`, `{{magic_link}}` and `{{recipient}}`; the subject accepts
only the first two. Both bodies must contain `{{magic_link}}`. Unknown or broken
placeholders, header control characters and oversized output fail closed; values
inserted into HTML are escaped.

The directory must resolve inside the installation, outside `public/`, and must
not be an upload target or writable by untrusted users. Long-running workers
cache the validated pack, so restart them after edits. Previewing renders a
clearly invalid dummy link and sends nothing:

```bash
php bin/mail-preview.php --locale=en --format=html > preview.html
php bin/mail-preview.php --locale=de --format=plain
```

## Sessions, UI and proxies

| Variable | Default | Purpose |
| --- | --- | --- |
| `SESSION_NAME` | `magiclink_session` | Cookie name |
| `SESSION_STORAGE` | `files` | `files` or the host-level `configured` handler |
| `SESSION_SAVE_PATH` | `storage/sessions` | Dedicated PHP session directory |
| `SESSION_SAMESITE` | `Lax` | `Lax`, `Strict` or `None` |
| `SESSION_IDLE_SECONDS` | `28800` | Idle lifetime |
| `SESSION_ABSOLUTE_SECONDS` | `604800` | Absolute lifetime |
| `API_ALLOWED_ORIGINS` | empty | Exact comma-separated UI origins |
| `TRUSTED_PROXIES` | empty | Exact IPs or CIDRs of proxies you operate |

With `files`, a relative path is resolved below the install root; MagicLink
creates it with restrictive permissions, owns the PHP garbage-collection policy
and requires the path to be writable. `/health` stays stateless and creates no
session file. Keep session storage outside the document root. For multiple web nodes without sticky sessions, set
`SESSION_STORAGE=configured` and configure a shared PHP session handler (for
example Redis) at the host/PHP level; MagicLink then preserves that handler. A
node-local file store is safe only with sticky routing. Forwarding headers are
ignored unless the direct peer is trusted. Wildcard CORS is intentionally
unsupported.

## Automatic sizing

| Variable | SQLite | MySQL | Hard range |
| --- | ---: | ---: | ---: |
| `MAGICLINK_STATE_BATCH_MAX` | 32 | 100 | 1–100 |
| `MAIL_WORKER_BATCH` | 25 | 100 | 1–250 |
| `MAIL_PENDING_MAX` | 500 | 5000 | 10–100000 |
| `MAINTENANCE_BATCH` | 1563* | 1563* | 10–5000 |

`auto` is deterministic and uses no telemetry. State, worker and queue values
depend on the database driver. Maintenance is calculated from configured
request/handoff budgets and the cleanup interval with 25% headroom, then bounded
to 10–5000; `*` shows the shipped defaults. An integer overrides any automatic
value. Batch polling grows per started ten IDs and caps at 15 seconds.
