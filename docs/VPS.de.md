# VPS-Deployment

[English](VPS.md) · [Deutsch](VPS.de.md)

Benötigt werden PHP 8.2+, PDO SQLite oder MySQL, OpenSSL oder Sodium, PHP-FPM
und ein funktionierender Mail-Transport. Die Befehle nehmen `www-data` als
PHP-FPM-Benutzer und -Gruppe an. Ersetze beide überall durch die tatsächliche
Pool-Identität. Ein eigener Benutzer pro Anwendung isoliert mehrere Sites besser.

## Installation

Installiere als Deployment-Benutzer und übergib private Laufzeitdaten an PHP-FPM:

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

Der Deployment-Benutzer behält Quellcode und `.env`; PHP-FPM darf die
Konfiguration lesen und besitzt Datenbank, Schlüssel und Session-Dateien.
Das oberste `storage/`-Verzeichnis erlaubt beiden Benutzern die Pflege der
versionierten Schutzdateien. Das Session-Verzeichnis bleibt `0700`, der
generierte Schlüssel `0600`. Nur die Gruppe zu ändern genügt für einen fremden
`0600`-Schlüssel oder ein `0700`-Verzeichnis nicht. Worker und Maintenance
müssen als **derselbe Laufzeitbenutzer** wie PHP-FPM laufen.

Der interaktive Installer unterstützt auch Automatisierung:

```bash
php bin/install.php \
  --url=https://login.example.com \
  --allow=owner@example.com \
  --from=no-reply@example.com
```

Richte einen Virtual Host aus `deploy/` mit `public/` als Document Root ein,
beziehe ein TLS-Zertifikat und lade Nginx oder Apache neu. `.env` und `storage/`
müssen außerhalb aller öffentlichen Site-Wurzeln liegen. Prüfe anschließend:

```bash
curl --fail --silent --show-error https://login.example.com/health
curl --fail --silent --show-error --output /dev/null https://login.example.com/
```

Die erste Anfrage prüft Erreichbarkeit, die zweite das Rendern einer Seite mit
Session unter PHP-FPM. Fordere danach einen Link für eine erlaubte Adresse an
und prüfe Zustellung, Bestätigung und Anmeldung mit dem konfigurierten Transport.

Für unabhängigen Versand setze `MAIL_AUTO_DISPATCH=false` und betreibe
`php /var/www/magiclink/bin/worker.php --loop` unter systemd oder Supervisor mit
`User=www-data`. Details zu Retries, Maintenance und Kapazität stehen unter
[Betrieb](OPERATIONS.de.md).

## Updates

Sichere Datenbank und Anwendungsschlüssel konsistent vor dem Update. Behalte
die Laufzeitidentität bei und starte dauerhafte Worker für das Update neu.

```bash
cd /var/www/magiclink
git pull --ff-only
php bin/check.php
sudo -u www-data php bin/doctor.php
```

Schemaänderungen sind additiv und werden beim Start angewandt. Prüfe danach
die HTTPS-Startseite und einen vollständigen Login.

## Reverse Proxies

MagicLink nutzt `REMOTE_ADDR`, solange der direkte Peer nicht in
`TRUSTED_PROXIES` steht. Andernfalls wird die gesamte `X-Forwarded-For`-Kette
validiert und von rechts nach links ausgewertet. Trage nur eigene Proxies ein,
die vom Client gelieferte Forwarding-Header ersetzen.
