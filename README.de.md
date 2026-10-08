<p align="center">
  <img src="docs/assets/magiclink-badge.svg" height="58" alt="MagicLink — selbst gehostete passwortlose Anmeldung">
</p>

<p align="center">
  <a href="README.md"><img src="docs/assets/readme-language-en.svg" height="40" alt="Read this README in English"></a>
</p>

<p align="center">
  <a href="https://github.com/angusu-de/MagicLink-CI/actions/workflows/ci.yml"><img src="https://raw.githubusercontent.com/angusu-de/MagicLink-CI/ci-proof/proof/ci-proof.svg" height="58" alt="Öffentlicher MagicLink-CI-Nachweis"></a>
</p>

<h1 align="center">MagicLink</h1>

<p align="center"><strong>Passwortlose Anmeldung, die auf Shared Hosting genauso klein startet wie auf einem VPS – mit eigener UI, eigener Config und ohne sichtbaren Infrastrukturballast.</strong></p>

<p align="center">
  <a href="https://github.com/IamAngusU/MagicLink/releases/latest"><strong>Fertiges ZIP herunterladen</strong></a>
  · <a href="docs/API.md">Headless API</a>
  · <a href="docs/CONFIGURATION.md">Config</a>
  · <a href="docs/OPERATIONS.md">Betrieb</a>
  · <a href="SECURITY.md">Security</a>
</p>

<p align="center"><a href="https://github.com/IamAngusU/icon-marquee"><img src="docs/assets/stack-marquee.svg" alt="PHP, JavaScript, HTML, CSS, SQLite, MySQL, Apache und Nginx" width="780"></a></p>

## Problem

Ein kleiner Login zieht schnell Passwort-Resets, Credential-Stuffing, Sessions,
CSRF, Mailfehler, Enumeration und Retention nach sich. Viele Magic-Link-Snippets
legen zudem den Token ins Serverlog oder melden den falschen Browser an.

## Lösung

MagicLink kapselt diesen Unterbau hinter einer kleinen, versionierten API:

- das Geheimnis bleibt im URL-Fragment und wird erst per geschütztem POST verbraucht;
- ausschließlich das Gerät mit dem Link erhält die Sitzung;
- State- und Batch-Endpunkte liefern stabile Codes für deine eigene UI;
- E-Mail läuft über eine verschlüsselte Outbox mit Retry und gleicher Außenwirkung
  für erlaubte und nicht erlaubte Adressen;
- Rate Limits, Request-Größen, Proxy-Vertrauen, Sessions, CORS und Retention sind
  sichere Defaults – und vollständig über `.env` steuerbar;
- `auto` wählt kleine SQLite- oder größere MySQL-Batches, explizite Werte gewinnen
  immer.

Kein Framework und kein Composer-Zwang: PHP 8.2+, PDO und Sodium oder OpenSSL.

<p align="center"><img src="docs/assets/magiclink-flow.svg" alt="MagicLink trennt Request-State und Token-Exchange: Nur der Browser mit dem Secret erhält eine Session." width="960"></p>

## In drei Minuten

1. [Das aktuelle ZIP laden](https://github.com/IamAngusU/MagicLink/releases/latest/download/magiclink-shared-hosting.zip) und hochladen.
2. Mit Shell einfach `php bin/install.php` starten und drei Fragen beantworten.
3. Ohne Shell `.env.example` nach `.env` kopieren und drei Werte setzen:

```dotenv
APP_URL=https://login.example.com
MAIL_FROM_ADDRESS=no-reply@example.com
MAGICLINK_ALLOWED_EMAILS=you@example.com
```

4. Domain öffnen. SQLite, Schema und App-Key entstehen automatisch.

<p align="center"><img src="docs/assets/terminal-setup.svg" alt="Geführte MagicLink-Installation und Doctor-Check im Terminal" width="900"></p>

Wenn möglich, zeigt der Document Root auf `public/`. Der Root-Fallback für
klassisches Apache-Hosting ist bereits enthalten. Details: [Shared Hosting](docs/SHARED-HOSTING.md)
oder [VPS](docs/VPS.md).

## Eigene UI

```js
const api = "https://login.example.com/api/v1";
const config = await fetch(`${api}/config`, { credentials: "include" })
  .then(r => r.json());

const request = await fetch(config.data.endpoints.request, {
  method: "POST",
  credentials: "include",
  headers: {
    "Content-Type": "application/json",
    "X-CSRF-Token": config.data.csrf_token
  },
  body: JSON.stringify({ email: "you@example.com" })
}).then(r => r.json());
```

Danach `GET /api/v1/state?id=…` mit dem in `meta.poll_after_ms` gelieferten
Intervall pollen – oder mehrere eigene Requests über `POST /api/v1/states`
bündeln. Antworten verwenden immer dieselbe Form:
`{ ok, code, data, error, meta }`.

Alle Endpunkte, States, CORS-Regeln und ein kompletter Browser-Flow stehen in der
[Headless-API-Doku](docs/API.md).

## Wenn es größer wird

Ohne Setup wird nach der HTTP-Antwort genau eine Mail abgearbeitet. Bei mehr
Traffic übernimmt ein Worker die Queue:

```bash
php bin/worker.php --loop
php bin/maintain.php --all
```

Für Shared-Hosting-Cron genügt `php bin/worker.php --once`. MySQL, Workerzahl,
Batch-Regeln, Retry-Verhalten und Retention erklärt [Betrieb unter Last](docs/OPERATIONS.md).
Der [reproduzierbare Performance-Check](docs/PERFORMANCE.md) misst den SQLite-Hot-Path
getrennt von HTTP-, SMTP- und Netzwerklatenz.

## Sicherheitsgrenze

MagicLink schützt den Login-Flow, nicht die Autorisierung deiner Anwendung oder
ein kompromittiertes Postfach. Vor einem öffentlichen Deployment:

```bash
php bin/doctor.php
php bin/check.php
```

Lies außerdem [`SECURITY.md`](SECURITY.md) und das [Threat Model](docs/THREAT-MODEL.md).

Badge und Sprach-Squircle stammen aus `IamAngusU/Badges`. Der anklickbare
Stack-Marquee führt zu [`IamAngusU/icon-marquee`](https://github.com/IamAngusU/icon-marquee).
Die aktuelle Link-Marke ist ein Platzhalter und kann später an einer kanonischen
SVG-Quelle ersetzt werden.

CI läuft wegen der getrennten GitHub-Abrechnung im öffentlichen, quellcodefreien
Harness [`angusu-de/MagicLink-CI`](https://github.com/angusu-de/MagicLink-CI).
Er liest nur den angeforderten privaten Commit über einen read-only Deploy Key;
Aufbau und Proof-Modell stehen in [CI.md](docs/CI.md).

Dieses private Repository enthält derzeit keine öffentliche Softwarelizenz.
