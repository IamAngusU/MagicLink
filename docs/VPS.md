# VPS deployment

The application needs PHP 8.2+, PDO SQLite or MySQL, OpenSSL or Sodium, PHP-FPM,
and a working mail transport.

## Install

```bash
sudo install -d -o "$USER" -g www-data /var/www/magiclink
git clone https://github.com/IamAngusU/MagicLink.git /var/www/magiclink
cd /var/www/magiclink
php bin/install.php \
  --url=https://login.example.com \
  --allow=owner@example.com \
  --from=no-reply@example.com
php bin/doctor.php
sudo chgrp -R www-data storage
sudo chmod 2770 storage
```

Configure the selected virtual host from `deploy/`, request a TLS certificate,
then reload Nginx or Apache. Keep the repository and `storage/` out of unrelated
site roots.

## Update

```bash
cd /var/www/magiclink
git pull --ff-only
php bin/doctor.php
```

Schema changes are additive and applied by the application before serving a
request. Back up both the database and `storage/app.key` before updating.

## Reverse proxies

MagicLink deliberately uses `REMOTE_ADDR` and does not trust
`X-Forwarded-For` by default. Configure the web server to replace the remote
address only when requests come from a proxy you control. Never pass a client
supplied forwarding header through unchanged.
