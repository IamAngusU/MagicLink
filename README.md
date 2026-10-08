<p align="center">
  <img src="docs/assets/magiclink-badge.svg" width="400" alt="MagicLink — self-hosted passwordless authentication">
</p>

<h1 align="center">Passwordless sign-in without handing over your stack.</h1>
<p align="center">Upload one small PHP app. Keep your UI, mail, data and limits.<br>MagicLink handles the security machinery underneath.</p>

<p align="center">
  <a href="README.de.md"><img src="docs/assets/readme-language-de.svg" height="40" alt="Diese README auf Deutsch lesen"></a>
</p>

<p align="center">
  <a href="https://github.com/IamAngusU/MagicLink/releases/latest"><img src="docs/assets/readme/repo-release.svg" height="40" alt="Download the ready-to-upload ZIP"></a>
  <a href="composer.json"><img src="docs/assets/readme/repo-runtime.svg" height="40" alt="PHP 8.2 or newer"></a>
  <a href="docs/SHARED-HOSTING.md"><img src="docs/assets/readme/repo-hosting.svg" height="40" alt="Shared-hosting and VPS deployment"></a>
  <a href="docs/API.md"><img src="docs/assets/readme/repo-api.svg" height="40" alt="Versioned headless API"></a>
</p>

<p align="center">
  <a href="https://github.com/angusu-de/MagicLink-CI/actions/workflows/ci.yml"><img src="https://raw.githubusercontent.com/angusu-de/MagicLink-CI/ci-proof/proof/ci-proof.svg" height="54" alt="Live public MagicLink CI proof"></a>
</p>
<p align="center"><sub>Public source-free CI on a separate account, same maintainer. The badge proves the named commit passed the published jobs; it is not a third-party audit.</sub></p>

<p align="center"><a href="#quick-start">Quick start</a> · <a href="#why-magiclink">Why MagicLink?</a> · <a href="#bring-your-own-ui">Your UI</a> · <a href="#make-the-mail-yours">Your mail</a> · <a href="#from-shared-hosting-to-vps">Scale</a> · <a href="#security-boundary">Security</a> · <a href="docs/API.md">Docs</a></p>

MagicLink is a small self-hosted authentication service for ordinary PHP shared
hosting and VPS deployments. It has no framework or Composer requirement, starts
with SQLite and exposes the same versioned API to its built-in screen and yours.

## Quick start

1. [Download the ready-to-upload ZIP](https://github.com/IamAngusU/MagicLink/releases/latest/download/magiclink-shared-hosting.zip) and upload it.
2. With shell access, run `php bin/install.php` and answer three questions.
3. Without a shell, copy `.env.example` to `.env` and set:

```dotenv
APP_URL=https://login.example.com
MAIL_FROM_ADDRESS=no-reply@example.com
MAGICLINK_ALLOWED_EMAILS=you@example.com
```

4. Open the domain. MagicLink creates SQLite, its schema and the application key.

<p align="center"><img src="docs/assets/terminal-setup.svg" width="900" alt="Guided MagicLink installation and doctor check in a terminal"></p>

Point the document root at `public/` when possible. The included root fallback
also works on traditional Apache hosting. Read the [shared-hosting guide](docs/SHARED-HOSTING.md)
or [VPS guide](docs/VPS.md) when the three-minute path is not enough.

## Why MagicLink?

The email is the easy part. The surrounding failure modes are the product:

| The problem | What MagicLink does |
| --- | --- |
| Tokens leak through server logs | The secret stays in the URL fragment and is consumed only by a protected POST. |
| A link signs in the wrong browser | Request state is bound to the initiating browser; only the browser holding the secret receives a session. |
| Responses reveal whether an account exists | Allowed and denied identities follow the same public path, timing budget and state model. |
| Mail fails halfway through a request | An encrypted outbox adds bounded retries, leases, stable message IDs and explicit terminal states. |
| A burst becomes an outage | Global, IP, identity and selector budgets combine with queue backpressure and bounded automatic cleanup. |
| A custom frontend must understand internals | Stable state and batch endpoints return one response envelope: `{ ok, code, data, error, meta }`. |

<p align="center"><img src="docs/assets/magiclink-flow.svg" width="960" alt="MagicLink keeps request state separate from the secret-bearing token exchange"></p>

## Bring your own UI

```js
const api = "https://login.example.com/api/v1";
const config = await fetch(`${api}/config`, { credentials: "include" })
  .then(response => response.json());

const request = await fetch(config.data.endpoints.request, {
  method: "POST",
  credentials: "include",
  headers: {
    "Content-Type": "application/json",
    "X-CSRF-Token": config.data.csrf_token
  },
  body: JSON.stringify({ email: "you@example.com" })
}).then(response => response.json());
```

Poll `GET /api/v1/state?id=…` using `meta.poll_after_ms`, or group owned
requests through `POST /api/v1/states`. A separate backend can use the optional
confidential-client handoff: exact redirect URI, short-lived code, `state`, PKCE
and one-time exchange, without sharing either application's session cookie.

[API reference](docs/API.md) · [Integration guide](docs/INTEGRATION.md)

## Make the mail yours

Copy `resources/mail-templates` to `storage/mail-templates`, edit the raw
`subject.txt`, `plain.txt` and `html.html`, then set
`MAIL_TEMPLATE_DIR=storage/mail-templates`.

```bash
php bin/mail-preview.php --locale=en --format=html > preview.html
```

The preview creates no token and sends no mail. Only documented placeholders
are accepted, inserted HTML values are escaped and the template directory must
remain local and outside `public/`.

## From shared hosting to VPS

Shared hosting needs no resident worker: MagicLink attempts one queued message
after the response, and a cron job can drain more. On a VPS, keep a worker alive:

```bash
php bin/worker.php --loop
php bin/maintain.php --all
php bin/status.php
```

SQLite is the zero-config default. MySQL adds multi-worker capacity; remote
production connections require a CA, verified server identity and `mysqlnd`.
Automatic queue, worker, state-batch and maintenance sizes stay bounded and can
all be overridden in `.env`.

The repeatable local baseline records a 0.515 ms request p50, 0.008 ms state
lookup and 0.052 ms batch-of-32 lookup with a 2 MiB peak. Real SMTP, storage and
network latency are intentionally measured separately. [Method and results](docs/PERFORMANCE.md).

## Security boundary

Before exposing an installation, run:

```bash
php bin/doctor.php
php bin/check.php
```

MagicLink protects passwordless sign-in. It does not replace application
authorization, protect a compromised mailbox or make an unsafe hosting account
safe. Read the [security policy](SECURITY.md), [threat model](docs/THREAT-MODEL.md)
and [operations guide](docs/OPERATIONS.md) before production use.

The product and language badges come from [`IamAngusU/Badges`](https://github.com/IamAngusU/Badges).
The clickable stack marquee below always opens [`IamAngusU/icon-marquee`](https://github.com/IamAngusU/icon-marquee).

<p align="center"><a href="https://github.com/IamAngusU/icon-marquee"><img src="docs/assets/stack-marquee.svg" width="780" alt="PHP, JavaScript, HTML, CSS, SQLite, MySQL, Apache and Nginx"></a></p>

<p align="center"><sub>No public software license has been granted yet. The current link mark is a placeholder until the final logo is supplied.</sub></p>
