<p align="center">
  <img src="docs/assets/magiclink-badge.svg" width="400" alt="MagicLink — selbst gehostete passwortlose Anmeldung">
</p>

<h1 align="center">Passwortlos anmelden, ohne deinen Stack abzugeben.</h1>
<p align="center">Eine kleine PHP-App hochladen. UI, Mail, Daten und Limits behalten.<br>MagicLink übernimmt die Sicherheitsmechanik darunter.</p>

<p align="center">
  <a href="README.md"><img src="docs/assets/readme-language-en.svg" height="40" alt="Read this README in English"></a>
</p>

<p align="center">
  <a href="https://github.com/IamAngusU/MagicLink/releases/latest"><img src="docs/assets/readme/repo-release.svg" height="40" alt="Uploadfertiges ZIP herunterladen"></a>
  <a href="composer.json"><img src="docs/assets/readme/repo-runtime.svg" height="40" alt="PHP 8.2 oder neuer"></a>
  <a href="docs/SHARED-HOSTING.md"><img src="docs/assets/readme/repo-hosting.svg" height="40" alt="Shared-Hosting- und VPS-Deployment"></a>
  <a href="docs/API.de.md"><img src="docs/assets/readme/repo-api.svg" height="40" alt="Versionierte Headless API"></a>
  <a href="LICENSE"><img src="docs/assets/readme/repo-license.svg" height="40" alt="MIT-lizenziert"></a>
</p>

<p align="center">
  <a href="https://github.com/angusu-de/MagicLink-CI/actions/workflows/ci.yml"><img src="https://raw.githubusercontent.com/angusu-de/MagicLink-CI/ci-proof/proof/ci-proof.svg" height="54" alt="Aktueller öffentlicher MagicLink-CI-Nachweis"></a>
</p>
<p align="center"><sub>Öffentliche CI auf einem getrennten Account, gleicher Maintainer. Das Badge belegt die veröffentlichten Jobs für den genannten Commit; es ist kein unabhängiges Audit.</sub></p>

<p align="center"><a href="#schnellstart">Schnellstart</a> · <a href="#warum-magiclink">Warum MagicLink?</a> · <a href="#deine-eigene-ui">Eigene UI</a> · <a href="#deine-mail-dein-design">Eigene Mail</a> · <a href="#vom-shared-hosting-zum-vps">Skalierung</a> · <a href="#sicherheitsgrenze">Security</a> · <a href="docs/API.de.md">Doku</a></p>

MagicLink ist ein kleiner selbst gehosteter Auth-Service für gewöhnliches PHP-
Shared-Hosting und VPS-Deployments. Er braucht weder Framework noch Composer,
startet mit SQLite und bietet der eingebauten Oberfläche und deiner UI dieselbe
versionierte API.

## Schnellstart

1. [Das uploadfertige ZIP herunterladen](https://github.com/IamAngusU/MagicLink/releases/latest/download/magiclink-shared-hosting.zip) und hochladen.
2. Mit Shell `php bin/install.php` ausführen und drei Fragen beantworten.
3. Ohne Shell `.env.example` nach `.env` kopieren und setzen:

```dotenv
APP_URL=https://login.example.com
MAIL_FROM_ADDRESS=no-reply@example.com
MAGICLINK_ALLOWED_EMAILS=du@example.com
```

4. Domain öffnen. MagicLink erzeugt SQLite, Schema und App-Key selbst.

<p align="center"><img src="docs/assets/terminal-setup.svg" width="900" alt="Geführte MagicLink-Installation und Doctor-Check im Terminal"></p>

Wenn möglich, zeigt der Document Root auf `public/`. Für klassisches Apache-
Hosting ist ein Root-Fallback enthalten. Mehr steht im [Shared-Hosting-Guide](docs/SHARED-HOSTING.md)
und im [VPS-Guide](docs/VPS.md).

## Warum MagicLink?

Die E-Mail ist der einfache Teil. Das Produkt sind die Fehlerfälle darum herum:

| Das Problem | Was MagicLink übernimmt |
| --- | --- |
| Tokens landen in Serverlogs | Das Secret bleibt im URL-Fragment und wird nur durch einen geschützten POST verbraucht. |
| Ein Link meldet den falschen Browser an | Request-State ist an den startenden Browser gebunden; nur der Browser mit Secret erhält eine Session. |
| Antworten verraten vorhandene Accounts | Erlaubte und abgelehnte Identitäten durchlaufen denselben öffentlichen Pfad, dasselbe Zeitbudget und State-Modell. |
| Mailversand bricht mitten im Request ab | Eine verschlüsselte Outbox liefert begrenzte Retries, Leases, stabile Message-IDs und explizite Endzustände. |
| Ein Burst wird zum Ausfall | Globale, IP-, Identitäts- und Selector-Budgets arbeiten mit Queue-Backpressure und begrenzter automatischer Bereinigung. |
| Eine eigene UI müsste Interna verstehen | Stabile State- und Batch-Endpunkte liefern ein Response-Format: `{ ok, code, data, error, meta }`. |

<p align="center"><img src="docs/assets/magiclink-flow.svg" width="960" alt="MagicLink trennt Request-State vom Token-Exchange mit Secret"></p>

## Deine eigene UI

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
  body: JSON.stringify({ email: "du@example.com" })
}).then(response => response.json());
```

Danach `GET /api/v1/state?id=…` mit `meta.poll_after_ms` pollen oder eigene
Requests über `POST /api/v1/states` bündeln. Ein getrenntes Backend kann den
optionalen Confidential-Client-Handoff verwenden: exakte Redirect-URI,
kurzlebiger Code, `state`, PKCE und einmaliger Exchange, ohne Session-Cookies
zwischen Anwendungen zu teilen.

[API-Referenz](docs/API.de.md) · [Integrationsguide](docs/INTEGRATION.de.md)

## Deine Mail, dein Design

`resources/mail-templates` nach `storage/mail-templates` kopieren, die rohen
Dateien `subject.txt`, `plain.txt` und `html.html` bearbeiten und
`MAIL_TEMPLATE_DIR=storage/mail-templates` setzen.

```bash
php bin/mail-preview.php --locale=de --format=html > preview.html
```

Die Vorschau erzeugt keinen Token und versendet keine Mail. Nur dokumentierte
Platzhalter sind erlaubt, HTML-Werte werden escaped und das Template-Verzeichnis
bleibt lokal außerhalb von `public/`.

## Vom Shared Hosting zum VPS

Shared Hosting braucht keinen dauerhaft laufenden Worker: MagicLink versucht
nach der Response eine Mail aus der Queue; ein Cronjob kann mehr abarbeiten.
Auf dem VPS bleibt ein Worker aktiv:

```bash
php bin/worker.php --loop
php bin/maintain.php --all
php bin/status.php
```

SQLite ist der konfigurationsfreie Default. MySQL ermöglicht mehrere Worker;
entfernte Produktionsverbindungen brauchen CA, verifizierte Serveridentität und
`mysqlnd`. Automatische Queue-, Worker-, State-Batch- und Maintenance-Größen
bleiben begrenzt und lassen sich vollständig in `.env` überschreiben.

Der reproduzierbare lokale Basislauf misst 0,515 ms Request-p50, 0,008 ms für
einen State und 0,052 ms für 32 States bei 2 MiB Peak-Memory. Reale SMTP-,
Storage- und Netzwerklatenz wird bewusst getrennt. [Methode und Ergebnisse](docs/PERFORMANCE.md).

## Sicherheitsgrenze

Vor einem öffentlichen Deployment:

```bash
php bin/doctor.php
php bin/check.php
```

MagicLink schützt die passwortlose Anmeldung. Es ersetzt nicht die Autorisierung
deiner Anwendung, schützt kein kompromittiertes Postfach und macht keinen
unsicheren Hosting-Account sicher. Vor Produktion gehören die
[Security Policy](SECURITY.md), das [Threat Model](docs/THREAT-MODEL.md) und der
[Betriebsleitfaden](docs/OPERATIONS.de.md) dazu.

Produkt- und Sprachbadges kommen aus [`IamAngusU/Badges`](https://github.com/IamAngusU/Badges).
Das klickbare Stack-Marquee unten öffnet immer [`IamAngusU/icon-marquee`](https://github.com/IamAngusU/icon-marquee).

<p align="center"><a href="https://github.com/IamAngusU/icon-marquee"><img src="docs/assets/stack-marquee.svg" width="780" alt="PHP, JavaScript, HTML, CSS, SQLite, MySQL, Apache und Nginx"></a></p>

<p align="center"><sub>MIT-lizenziert. Siehe <a href="LICENSE">LICENSE</a>. Das aktuelle Link-Zeichen bleibt ein Platzhalter, bis das finale Logo vorliegt.</sub></p>
