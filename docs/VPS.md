# VPS deployment

The application needs PHP 8.2+, PDO SQLite or MySQL, OpenSSL or Sodium, PHP-FPM,
and a working mail transport.

## Install

```bash
sudo install -d -o "$USER" -g www-data /var/www/magiclink
git clone https://github.com/IamAngusU/MagicLink.git /var/www/magiclink
cd /var/www/magiclink
php bin/install.php
php bin/doctor.php
php bin/check.php
sudo chgrp -R www-data storage
sudo chmod 2770 storage
```

Configure the selected virtual host from `deploy/`, request a TLS certificate,
then reload Nginx or Apache. Keep the repository and `storage/` out of unrelated
site roots.

The installer prompts in an interactive terminal. For reproducible automation,
the equivalent non-interactive form remains available:

```bash
php bin/install.php \
  --url=https://login.example.com \
  --allow=owner@example.com \
  --from=no-reply@example.com
```


Für dauerhaft unabhängigen Versand setze `MAIL_AUTO_DISPATCH=false` und betreibe
`php bin/worker.php --loop` unter systemd oder Supervisor. Details und
Batch-Defaults stehen unter [Betrieb und Last](OPERATIONS.md).

## Update

```bash
cd /var/www/magiclink
git pull --ff-only
php bin/doctor.php
```

Schema changes are additive and applied by the application before serving a
request. Back up both the database and `storage/app.key` before updating.

## Reverse proxies

MagicLink verwendet standardmäßig ausschließlich `REMOTE_ADDR`. Nur wenn der
direkte Peer in `TRUSTED_PROXIES` steht, wird eine vollständig validierte
`X-Forwarded-For`-Kette von rechts nach links ausgewertet. Trage ausschließlich
Proxies ein, die du kontrollierst und die eingehende Client-Header ersetzen.
