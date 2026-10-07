<p align="center">
  <img src="docs/assets/magiclink-badge.svg" height="58" alt="MagicLink — self-hosted passwordless authentication">
</p>

<h1 align="center">MagicLink</h1>

<p align="center"><strong>Ein sicherer Anmeldelink für Shared Hosting und VPS.<br>Kein Framework, kein Composer-Zwang, kein Passwortspeicher.</strong></p>

<p align="center">
  <a href="https://github.com/IamAngusU/MagicLink/releases/latest"><strong>Fertiges ZIP herunterladen</strong></a>
  · <a href="docs/SHARED-HOSTING.md">Shared Hosting</a>
  · <a href="docs/VPS.md">VPS</a>
  · <a href="SECURITY.md">Sicherheit</a>
</p>

<p align="center"><img src="docs/assets/stack-marquee.svg" alt="PHP, JavaScript, HTML, CSS, SQLite, MySQL, Apache und Nginx" width="780"></p>

## Das Problem

Ein Passwort-Login klingt klein und bringt trotzdem Reset-Mails, Hash-Parameter,
Credential-Stuffing, Sessions, CSRF, Rate Limits und sensible Fehlerzustände mit.
Viele Magic-Link-Beispiele verschieben das Problem nur: Der Token landet im
Access-Log, ein Mail-Scanner verbraucht ihn per GET oder derselbe Link funktioniert
mehrfach.

## Die Lösung

MagicLink ist ein kleiner, eigenständiger PHP-Login mit einer klaren Grenze:

- der öffentliche Selector steht im Querystring;
- das eigentliche Geheimnis bleibt im URL-Fragment und erreicht den Server erst
  durch einen CSRF- und Origin-geschützten POST;
- der Token liegt nur als HMAC in SQLite oder MySQL;
- der erste gültige POST verbraucht ihn atomar;
- Replay, Ablauf, Rate Limit und Cross-Device-Bestätigung sind eigene Zustände;
- E-Mail-Adressen werden verschlüsselt, Audit-Ereignisse enthalten nur Hashes und
  technische Metadaten;
- nicht freigeschaltete Adressen erhalten denselben sichtbaren Waiting-State und
  verraten dadurch keine Allowlist.

Die Sicherheitslogik stammt aus dem produktiven PRISM-Flow und wurde hier von
PRISM-, Alva-, Billing- und Workspace-Code getrennt.

## In drei Minuten nutzen

### Shared Hosting

1. [Das aktuelle Shared-Hosting-ZIP herunterladen](https://github.com/IamAngusU/MagicLink/releases/latest/download/magiclink-shared-hosting.zip).
2. Den Inhalt in die gewünschte Domain oder einen Unterordner hochladen.
3. `.env.example` als `.env` kopieren und mindestens diese Werte ändern:

```dotenv
APP_URL=https://login.example.com
MAIL_FROM_ADDRESS=no-reply@example.com
MAGICLINK_ALLOWED_EMAILS=you@example.com
```

4. Die URL öffnen. Datenbank und App-Key werden beim ersten Start angelegt.

Die Root-`.htaccess` schützt private Verzeichnisse und leitet auf `public/` um.
Wenn das Hosting einen eigenen Document Root erlaubt, ist `public/` die
bevorzugte und engere Grenze. Die vollständige Anleitung steht unter
[Shared Hosting](docs/SHARED-HOSTING.md).

### VPS

```bash
git clone https://github.com/IamAngusU/MagicLink.git
cd MagicLink
php bin/install.php \
  --url=https://login.example.com \
  --allow=you@example.com \
  --from=no-reply@example.com
php bin/doctor.php
```

Danach zeigt Nginx oder Apache auf `public/`. Fertige Konfigurationen liegen in
[`deploy/`](deploy/); die genauen Schritte stehen unter [VPS](docs/VPS.md).

### Lokal prüfen

```bash
cp .env.example .env
# APP_ENV=local, APP_URL=http://127.0.0.1:8080 und MAIL_TRANSPORT=log setzen
php -S 127.0.0.1:8080 -t public public/router.php
```

Die Entwicklungs-Mail wird unter `storage/mail/` abgelegt. `log` wird in
Production absichtlich abgelehnt.

## Mail versenden

`MAIL_TRANSPORT=mail` verwendet die Mail-Konfiguration des Hostings. Für einen
SMTP-Anbieter:

```dotenv
MAIL_TRANSPORT=smtp
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USERNAME=account@example.com
SMTP_PASSWORD=replace-me
```

TLS-Zertifikate werden geprüft. Unverschlüsseltes SMTP ist in Production
gesperrt.

## Zustände

| Zustand | Bedeutung | Sichtbares Verhalten |
| --- | --- | --- |
| `requested` | Eingabe angenommen | neutrale Vorbereitung |
| `waiting` | Link aktiv oder Enumeration-geschützter Decoy | Postfachansicht und Polling |
| `verified` | Token atomar verbraucht | Sitzung wird auf beiden Geräten geöffnet |
| `expired` | TTL überschritten | neuer Link erforderlich |
| `replayed` | bereits verwendeter Token | generischer Fehler, Audit-Ereignis |
| `rate_limited` | IP- oder E-Mail-Budget verbraucht | HTTP 429 |
| `denied` | Adresse nicht freigeschaltet | nach außen weiterhin neutral |
| `failed` | ungültige oder unvollständige Übergabe | neuer Link erforderlich |

Die stabilen Codes liegen in [`MagicLinkState.php`](src/MagicLinkState.php), die
deutschen und englischen Texte unter [`resources/states/`](resources/states/).

## In eine Anwendung einbauen

Nach erfolgreichem Login steht die normalisierte Identität in der serverseitigen
Session:

```php
<?php
$app = require __DIR__ . '/magic-link/bootstrap.php';

$email = IamAngusU\MagicLink\Session::email();
if ($email === null) {
    header('Location: /login');
    exit;
}
```

Die Beispiel-Dashboardseite ist nur der Übergabepunkt. Weitere Hinweise stehen
unter [Integration](docs/INTEGRATION.md).

## Sicherheitsgrenzen

MagicLink schützt den Login-Flow. Es ersetzt weder TLS, ein gepflegtes PHP,
saubere Serverrechte noch die Autorisierung innerhalb deiner Anwendung. Lies vor
einem öffentlichen Deployment [`SECURITY.md`](SECURITY.md) und das
[`Threat Model`](docs/THREAT-MODEL.md).

## Badge und Logo

Der Badge wird aus dem privaten Repository `IamAngusU/Badges` generiert. Die
aktuelle Link-Marke ist ausdrücklich ein Platzhalter. Sobald das finale Logo
vorliegt, wird nur die kanonische SVG-Quelle im Badge-Katalog ersetzt und der
Generator rendert alle Varianten neu.

Dieses Repository ist privat und enthält derzeit keine öffentliche
Softwarelizenz.
