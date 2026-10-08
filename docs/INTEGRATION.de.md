# Integration

**Deutsch** · [English](INTEGRATION.md)

## Gleiche PHP-Anwendung

`bootstrap.php` startet MagicLink und dieselbe PHP-Session. Nach einem
erfolgreichen Exchange liefert `Session::email()` die normalisierte Identität:

```php
<?php
$app = require __DIR__ . '/magic-link/bootstrap.php';

$email = IamAngusU\MagicLink\Session::email();
if ($email === null) {
    header('Location: /login');
    exit;
}
```

Die E-Mail ist ein Identity-Key, keine Rollenentscheidung. Autorisierung bleibt
Aufgabe der Host-Anwendung.

## Eigene oder getrennte Browser-UI

Nutze die versionierten Endpunkte aus [API.de.md](API.de.md). Die UI beginnt mit
`/api/v1/config`, übernimmt absolute URLs, State-Katalog und Limits, kopiert den
CSRF-Token in schreibende Requests und sendet immer Credentials mit.

Bei einer anderen Origin konfigurierst du eine exakte `API_ALLOWED_ORIGINS`,
HTTPS und `SESSION_SAMESITE=None`; bei Bedarf zusätzlich `AUTH_SUCCESS_URL`.
Session-Cookies werden nicht zwischen Hosts kopiert. Die State-API ist reine
Anzeige: keine E-Mail, kein Token und keine Authentifizierung durch `verified`.

## Getrenntes Backend: RP-initiierter Handoff

Aktiviere den eingebauten Confidential-Client-Flow, wenn ein anderes Backend
seine eigene Session erstellen soll. Er ist OAuth-ähnlich, aber kein allgemeiner
OAuth-/OIDC-Provider: genau ein vertrauliches RP, eine exakte Redirect-URI und nur
eine E-Mail-Identität. Rollen und Autorisierung bleiben beim RP.

```dotenv
HANDOFF_REDIRECT_URL=https://app.example.com/auth/magic-link
HANDOFF_CLIENT_SECRET=<Base64-kodierte 32 Zufallsbytes>
HANDOFF_TRANSACTION_TTL_SECONDS=900
HANDOFF_CODE_TTL_SECONDS=60
```

Erzeuge das gemeinsame Client-Secret einmal auf einem vertrauenswürdigen Rechner:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Es liegt nur in den privaten Umgebungen von MagicLink und Relying-Party-Backend
(RP). Niemals in JavaScript, HTML, Query-String, öffentliches Repository oder
clientseitige Config schreiben.

### 1. Im RP-Backend starten

Erzeuge State aus mindestens 32 Zufallsbytes und ein PKCE-S256-Paar. Soll-State
und Verifier werden an die startende RP-Browser-Session gebunden gespeichert –
nicht in einer globalen Suche ohne Browserbindung:

```php
<?php
session_start();

$base64url = static fn (string $raw): string =>
    rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

$state = $base64url(random_bytes(32));
$verifier = $base64url(random_bytes(32));
$challenge = $base64url(hash('sha256', $verifier, true));

$_SESSION['magiclink_state'] = $state;
$_SESSION['magiclink_verifier'] = $verifier;
```

Rufe vom Backend `POST /api/v1/handoffs/transactions` mit Bearer-Client-Secret
auf:

```json
{
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "state": "<state>",
  "code_challenge": "<challenge>",
  "code_challenge_method": "S256"
}
```

Leite den Browser auf die gelieferte `data.authorize_url`. Diese URL nicht selbst
zusammenbauen. Nur die S256-Challenge verlässt das RP; der Verifier bleibt mit
dieser Transaktion auf seinem Server.

### 2. MagicLink im selben Browser abschließen

Die Authorize-URL bindet die Transaktion an diese MagicLink-Browser-Session. Ist
sie anonym, erscheint die normale Anmeldung. Der Mail-Link muss im selben Browser
geöffnet werden; ein anderer Browser kann sich selbst anmelden, aber nicht die
gebundene RP-Transaktion autorisieren: Sein `data.handoff` ist `null`, also erhält
das RP keine Identität. `unavailable` bedeutet, dass die aktuelle Session einen
wartenden Request hatte, der ablief oder die Prüfung nicht bestand. Eine bereits
angemeldete MagicLink-Session geht direkt weiter – deshalb sind State und PKCE
zwingend und keine optionale Formalität.

Nach der Anmeldung leitet MagicLink nur zur exakt registrierten URI mit `code`
und ursprünglichem `state`. Der Mail-Token bleibt in MagicLink und wird nie als
Backend-Credential wiederverwendet.

Meldet `magic_link.verified` den Status `data.handoff.status=retryable`, war die
Anmeldung erfolgreich und der Mail-Token ist bereits verbraucht. Im selben
Browser `retry_url` folgen; den Mail-Token nicht erneut senden. Der Retry stellt
das bestehende Authorization-Ergebnis wieder her, solange es gültig ist.

### 3. Im RP-Backend prüfen und austauschen

Der Callback vergleicht den zurückgelieferten State per `hash_equals` mit der
Server-Session. Fehlende oder abweichende Werte werden vor dem Exchange
abgewiesen. Das geschieht samt Code-Exchange, bevor HTML gerendert oder
Drittressourcen geladen werden. Danach ruft der Server
`POST /api/v1/handoffs/exchange` auf:

```json
{
  "code": "<code-aus-dem-callback>",
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "code_verifier": "<verifier-aus-der-server-session>"
}
```

Auch dieser Request nutzt das Bearer-Client-Secret. Eine erfolgreiche Antwort
enthält `data.email`. RP-Session rotieren/erstellen, dort autorisieren,
gespeicherten State und Verifier löschen und mit HTTP 303 auf eine saubere URL
ohne `code` oder `state` leiten. Der Callback sollte `Cache-Control: no-store`
und `Referrer-Policy: no-referrer` senden.

Der Code ist kurzlebig und einmalig; Redirect-URI und PKCE-Verifier müssen zur
Transaktion passen. Replay, Ablauf oder Abweichung liefert absichtlich denselben
HTTP 410 – die UX verzweigt nicht nach Ursache. Callback-Query-Strings gehören
nicht in Access- oder Analytics-Logs. Die State-API übergibt nie Identität oder
Session; nur dieser authentifizierte Server-Exchange tut das. Das alte generische
`POST /api/v1/handoffs` liefert bewusst 404. Exakte Payloads und Retry-Verhalten
stehen in [API.de.md](API.de.md).

## Mail-Design

MagicLink liefert deutsche und englische Defaults. Für eigenes Branding kopierst
du `resources/mail-templates` in ein nicht öffentliches lokales Verzeichnis und
setzt `MAIL_TEMPLATE_DIR`; daraus darf kein Upload-Ziel werden. Platzhalter und
Vorschau stehen unter [Konfiguration](CONFIGURATION.de.md#eigene-mail-templates).
