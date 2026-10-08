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
  · <a href="docs/API.de.md">Headless API</a>
  · <a href="docs/CONFIGURATION.de.md">Config</a>
  · <a href="docs/OPERATIONS.de.md">Betrieb</a>
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
- State- und Batch-Endpunkte liefern stabile Codes für deine eigene UI, aber
  weder Identität noch Token;
- E-Mail läuft über eine verschlüsselte Outbox mit Retry und gleicher Außenwirkung
  für erlaubte und nicht erlaubte Adressen;
- die eingebauten deutschen und englischen Mails lassen sich durch lokale,
  einfache UTF-8-Templates ersetzen und ohne Versand vorschauen;
- ein optionaler RP-initiierter, State- und PKCE-gebundener Handoff übergibt die
  bestätigte Identität an ein anderes Backend, ohne Session-Cookies zu teilen;
- Rate Limits, Request-Größen, Proxy-Vertrauen, Sessions, CORS und Retention sind
  sichere Defaults – und vollständig über `.env` steuerbar;
- ein explizites globales Request-Budget und eine begrenzte Pending-Queue bremsen
  vor Überlast; `auto` dimensioniert Queue- und Batch-Limits für SQLite oder
  MySQL, explizite Werte gewinnen immer.

Kein Framework und kein Composer-Zwang: PHP 8.2+, PDO und Sodium oder OpenSSL.

<p align="center"><img src="docs/assets/magiclink-flow.svg" alt="MagicLink trennt Request-State und Token-Exchange: Nur der Browser mit dem Secret erhält eine Session." width="960"></p>

## In drei Minuten

1. [Das aktuelle uploadfertige ZIP laden](https://github.com/IamAngusU/MagicLink/releases/latest/download/magiclink-shared-hosting.zip) und hochladen.
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
[Headless-API-Doku](docs/API.de.md).

Für ein getrenntes Backend aktivierst du den optionalen Server-Handoff. Dieses
Backend startet die Transaktion, bindet erwarteten `state` und PKCE-Verifier an
die startende RP-Browser-Session und schickt den Browser zur Authorize-URL.
Derselbe Browser meldet sich an; der Callback prüft `state`, tauscht den
kurzlebigen Code,
entfernt ihn aus der URL und erstellt seine eigene Session. Details:
[Integration](docs/INTEGRATION.de.md).

## Deine Mail, dein Design

Kopiere `resources/mail-templates` nach `storage/mail-templates`, bearbeite die
rohen Dateien `subject.txt`, `plain.txt` und `html.html` und setze
`MAIL_TEMPLATE_DIR=storage/mail-templates`. Die Vorschau erzeugt weder gültigen
Token noch Mail:

```bash
php bin/mail-preview.php --locale=de --format=html > preview.html
```

Nur dokumentierte Platzhalter werden akzeptiert, HTML-Werte werden escaped und
das Template-Verzeichnis bleibt lokal außerhalb von `public/`. Mehr steht unter
[Konfiguration](docs/CONFIGURATION.de.md#eigene-mail-templates).

## Wenn es größer wird

Ohne Setup versucht MagicLink nach der HTTP-Antwort eine fällige Queue-Zeile.
Bei mehr Traffic übernimmt ein Worker die Queue:

```bash
php bin/worker.php --loop
php bin/maintain.php --all
php bin/status.php
```

Für Shared-Hosting-Cron genügt `php bin/worker.php --once`. MySQL, Workerzahl,
Queue-Kapazität, Zustellsemantik, Retries und Retention erklärt
[Betrieb unter Last](docs/OPERATIONS.de.md).
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
Das optionale Client-Secret liegt auf beiden Servern, der PKCE-Verifier nur im
Relying-Party-Backend. Der Callback prüft `state`, tauscht den Code sofort und
leitet auf eine saubere URL weiter; niemals diese Werte an JavaScript geben oder
den State-Endpunkt als Identitätsnachweis behandeln.

Badge und Sprach-Squircle stammen aus `IamAngusU/Badges`. Der anklickbare
Stack-Marquee führt zu [`IamAngusU/icon-marquee`](https://github.com/IamAngusU/icon-marquee).
Die aktuelle Link-Marke ist ein Platzhalter und kann später an einer kanonischen
SVG-Quelle ersetzt werden.

CI läuft wegen der getrennten GitHub-Abrechnung im öffentlichen, quellcodefreien
Harness [`angusu-de/MagicLink-CI`](https://github.com/angusu-de/MagicLink-CI).
Er liest nur den angeforderten privaten Commit über einen read-only Deploy Key;
Aufbau und Proof-Modell stehen in [CI.md](docs/CI.md).

Dieses private Repository enthält derzeit keine öffentliche Softwarelizenz.
