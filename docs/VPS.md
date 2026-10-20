# VPS deployment

[English](VPS.md) · [Deutsch](VPS.de.md)

The application needs PHP 8.2+, PDO SQLite or MySQL, OpenSSL or Sodium, PHP-FPM,
and a working mail transport.

The commands assume the PHP-FPM pool runs as `www-data`. Substitute the actual
pool user/group everywhere. A dedicated per-app account provides better
isolation on a server hosting several applications.

## Install

```bash
sudo install -d -o "$USER" -g www-data /var/www/magiclink
git clone https://github.com/IamAngusU/MagicLink.git /var/www/magiclink
cd /var/www/magiclink
php bin/install.php
php bin/check.php
sudo chown "$USER":www-data .env
sudo chmod 0640 .env
sudo chown -R www-data:www-data storage
sudo chown "$USER":www-data storage storage/.htaccess storage/.gitkeep
sudo chmod 2770 storage
sudo -u www-data php bin/doctor.php
```

The deployment user owns the source and `.env`; PHP-FPM can read configuration
and owns private database, key and session files. The top-level `storage/`
directory permits both accounts to maintain the tracked deny-rule placeholders;
the session directory stays `0700` and the generated key stays `0600`. Merely
changing the storage group does not grant access to an operator-owned `0600`
key or `0700` session directory. Run worker and maintenance commands as the
**same runtime account** as PHP-FPM.

Configure the selected virtual host from `deploy/`, request a TLS certificate,
then reload Nginx or Apache. Use `public/` as its document root. Keep `.env` and
`storage/` outside every public site root. After the virtual host is live:

```bash
curl --fail --silent --show-error https://login.example.com/health
curl --fail --silent --show-error --output /dev/null https://login.example.com/
```

The first request checks liveness; the second exercises session-backed rendering
under PHP-FPM. Then request a link for an allowed address and verify delivery,
confirmation and sign-in using the configured transport.

The installer prompts in an interactive terminal. For reproducible automation,
the equivalent non-interactive form remains available:

```bash
php bin/install.php \
  --url=https://login.example.com \
  --allow=owner@example.com \
  --from=no-reply@example.com
```


For independent delivery, set `MAIL_AUTO_DISPATCH=false` and supervise
`php /var/www/magiclink/bin/worker.php --loop` with systemd or Supervisor,
using the PHP-FPM runtime account (`User=www-data` in this example).
See [operations](OPERATIONS.md) for retries, maintenance and capacity planning.

## Update

```bash
cd /var/www/magiclink
git pull --ff-only
php bin/check.php
sudo -u www-data php bin/doctor.php
```

Schema changes are additive and applied by the application before serving a
request. Back up the database and application key consistently before updating.
Keep the runtime identity and restart long-running workers around the update.
Recheck the HTTPS homepage and complete a sign-in afterward.

## Reverse proxies

MagicLink uses `REMOTE_ADDR` unless the immediate peer is listed in
`TRUSTED_PROXIES`. It then validates the entire `X-Forwarded-For` chain and walks
it from right to left. List only proxies you operate that replace incoming
client-supplied forwarding headers.
