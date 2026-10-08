# Operations and load

[Deutsch](OPERATIONS.de.md) · **English**

MagicLink starts without a service process but has a deliberate growth path.

## Response layers

1. HTTP boundaries validate body size, JSON, session, CSRF, origin and request
   budgets.
2. One short database transaction creates the link, audit record and encrypted
   outbox entry together.
3. The HTTP 202 response is finalized and the session lock is released.
4. Only then does zero-setup mode attempt one ready queue item.

With PHP-FPM, `fastcgi_finish_request()` closes the visible response before mail
delivery. If the host cannot flush early reliably, or volume grows, set
`MAIL_AUTO_DISPATCH=false` and run a worker so public latency never waits for
SMTP.

## Backpressure before failure

Requests pass a global fixed-window budget before IP and identity budgets. The
service also counts `pending` plus `sending` outbox rows before accepting more
work. `MAIL_PENDING_MAX=auto` permits 500 on SQLite and 5000 on MySQL; an explicit
10–100000 value remains under operator control. Capacity returns HTTP 429 with a
retry hint instead of growing the queue without bound. Every identity is rejected
identically at the cap—including one with a pending row—so capacity cannot
become an account-membership oracle.

Use the global request budget for application protection, but keep upstream
CDN/WAF and host connection limits for volumetric attacks. App limits are not a
DDoS service.

## Shared-hosting cron

```cron
* * * * * /usr/bin/php /home/account/magiclink/bin/worker.php --once >/dev/null 2>&1
*/15 * * * * /usr/bin/php /home/account/magiclink/bin/maintain.php --all >/dev/null 2>&1
```

One run claims only ready rows. Abandoned claims are recoverable after
`MAIL_LOCK_TIMEOUT_SECONDS`; retries use exponential delay with jitter, capped at
one hour.

## VPS worker

```bash
php bin/worker.php --loop
```

Each pass writes compact JSON with `claimed`, `sent`, `retried`, `failed` and
`lost`. systemd or Supervisor can restart the process. `--batch=50` and
`--sleep=2` override the bounded defaults.

SQLite uses WAL and short serialized write transactions. It is the simplest
choice for one frontend and moderate login traffic. Use MySQL for several PHP-FPM
nodes, workers or sustained concurrency. Set `DB_SSL_CA` when MySQL crosses an
untrusted network and keep `DB_SSL_MODE=verify_identity`; remote production does
not offer a TLS mode without host verification. MySQL claims lock selected rows so workers do
not intentionally deliver the same queue item together.

Multiple web nodes also need shared session state. Configure the shared PHP
handler at host level and set `SESSION_STORAGE=configured`, or guarantee sticky
routing. The default node-local file store is not a cross-node session store.

## Delivery semantics

- A claim has an unpredictable ownership token and lease; the worker renews it
  around network I/O and records ownership loss instead of updating a row it no
  longer owns.
- SMTP connections are reused within one claimed batch, checked with `NOOP`, and
  closed after the batch. A failed connection is discarded before retry.
- SMTP 4xx responses and transport failures retry; SMTP 5xx and configuration or
  payload failures terminate the item. `MAIL_MAX_ATTEMPTS` is the final bound.
- The MIME message uses encoded headers and bodies. A deterministic `Message-ID`
  derived from the link selector stays stable across retries.

Delivery is **at least once**, not exactly once. If an SMTP server accepted the
message but the connection failed before the worker saw confirmation, retrying is
safer than losing the login mail and can produce a duplicate. The stable
`Message-ID` helps downstream deduplication but does not guarantee it. Consumers
must treat every magic-link token as single-use regardless of duplicate mail.

## State polling

- Follow `meta.poll_after_ms` for individual reads.
- Batch owned request IDs through `POST /api/v1/states`.
- `auto` allows 32 IDs on SQLite and 100 on MySQL.
- The interval grows per started ten IDs, capped at 15 seconds.
- Early polling returns HTTP 429 with `Retry-After` instead of more DB work.

Stop when `terminal=true`. `verified` is an observation, not authorization for
the polling browser; the state endpoints expose neither identity nor token.

## Retention and storage

After every response, one indexed due-check schedules cleanup at the configured
interval. `MAINTENANCE_BATCH=auto` derives a bounded batch from admitted
request/handoff rates with 25% headroom, so shipped defaults have cleanup capacity
to spare without cron. A dedicated run is still more predictable:

```bash
php bin/maintain.php --all
```

Cleanup deletes bounded batches of expired links and handoffs, expired rate
counters, old audit events and completed outbox rows. Retention stays configurable
in `.env`. Back up the database and `storage/app.key` together before upgrades;
schema migrations are additive and run on boot. Keep `storage/sessions`, the key,
database and custom templates outside the public document root.

For a controlled rollout, run `php bin/migrate.php` before replacing workers.
Boot still checks the schema; MySQL migration work is serialized with a database
advisory lock so concurrent nodes do not apply the same DDL together.

## Abuse layers

- Global and IP budgets are consumed before email validation.
- Identity budgets use HMACs instead of clear text.
- Token attempts are bounded per IP and selector.
- Token exchange also has a global installation budget.
- Unknown or blocked identities follow the same public request shape.
- State IDs must belong to the server-side session.
- Request body, JSON depth, session lifetime and remembered requests are bounded.
- Shipped ingress/PHP configs cap bodies before parsing; multipart is rejected.
- File sessions own a bounded PHP GC policy; `/health` creates no session.
- Untrusted `X-Forwarded-For` is ignored.
- Queue capacity and retention bound stored work.
- RP handoff initiation is globally rate-limited; transactions are same-browser,
  state- and PKCE-bound, and their authorization codes are short-lived/one-time.

## Checks and visibility

```bash
php bin/doctor.php
php bin/check.php
php bin/status.php
php bin/status.php --json
```

`doctor` validates runtime, storage, encryption and schema. `status` reports queue
counts, oldest pending age, stale claims, last delivery and maintenance, active
handoffs, and effective batch/capacity values without making them public over
HTTP. `/health` intentionally reveals only basic liveness.

Alert on a growing oldest-pending age, any stale claims that persist beyond a
worker cycle, sustained queue occupancy near `MAIL_PENDING_MAX`, or repeated
terminal failures. Do not send secrets, email addresses, handoff codes or query
strings to logs or metrics.
