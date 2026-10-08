# Shared Hosting

## Fast path

1. Download `magiclink-shared-hosting.zip` from the latest private GitHub release.
2. Extract it into a dedicated domain or subdomain directory.
3. With SSH or a hosting terminal, run `php bin/install.php` and answer the
   guided prompts. Without a shell, copy `.env.example` to `.env`.
4. In a manually copied file, set `APP_URL`, `MAIL_FROM_ADDRESS` and at least
   one allowed email or domain.
5. Open the configured URL and then check `php bin/doctor.php` if the host offers
   a terminal.

SQLite is created under `storage/database.sqlite`. The PHP process needs write
access to `storage/`, but other users on the same server should not.

## Preferred directory boundary

If the hosting panel supports a custom document root, choose the extracted
`public/` directory. The browser can then never address `src/`, `.env`, storage or
tests directly.

If the document root cannot be changed, upload the entire package. The supplied
root `.htaccess` denies private paths and forwards public requests to
`public/index.php`. This fallback requires Apache `mod_rewrite` and permission to
use `.htaccess`.

## Mail

Start with `MAIL_TRANSPORT=mail` when the provider exposes a configured PHP mail
transport. Use `smtp` when the provider gives explicit SMTP credentials. Never
commit those credentials; `.env` is ignored by Git.

Zero-setup mode processes one queue item after the HTTP response. On a plan with
cron support, the more predictable setup is:

```dotenv
MAIL_AUTO_DISPATCH=false
```

```cron
* * * * * /usr/bin/php /home/account/magiclink/bin/worker.php --once >/dev/null 2>&1
```

See [operations and load](OPERATIONS.md) for the worker, cleanup and scaling path.

The supplied `.htaccess` and `public/.user.ini` cap request bodies at 16 KiB
before PHP parses them. If the host ignores either file, apply the equivalent
limit in its control panel. When changing `HTTP_MAX_BODY_BYTES`, keep those
host-level limits in sync.

## MySQL instead of SQLite

```dotenv
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=magic_link
DB_USERNAME=magic_link
DB_PASSWORD=replace-me
```

Use a database user limited to this one database. Tables and indexes are created
automatically on first start.

## Subdirectory install

Use the complete public path in `APP_URL`, for example:

```dotenv
APP_URL=https://example.com/tools/login
```

Session cookies and internal routes automatically inherit that base path.
