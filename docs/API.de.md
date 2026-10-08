# Headless API v1

**Deutsch** · [English](API.md)

Die mitgelieferte Oberfläche verwendet dieselbe API wie eine eigene UI. Jede
Antwort hat eine stabile Hülle:

```json
{
  "ok": true,
  "code": "request.accepted",
  "data": {},
  "error": null,
  "meta": {
    "api_version": "v1",
    "request_id": "…",
    "server_time": "2026-10-08T10:00:00+00:00",
    "poll_after_ms": 2500
  }
}
```

Bei Fehlern ist `data` null; `error` enthält `message` und gegebenenfalls
`retry_after`. Clients verzweigen auf `code`, nie auf übersetzten Text.

## Browser-Flow

Browser-Aufrufe senden Cookies (`credentials: "include"`). Schreibende
Browser-Requests kopieren den Token aus `GET /api/v1/config` in
`X-CSRF-Token`. Eine optionale `X-Request-ID` mit 8–64 sicheren Zeichen wird in
Antwort und Logs übernommen; sonst erzeugt der Server eine.

1. `GET /api/v1/config`
2. `POST /api/v1/requests` mit `{ "email": "you@example.com" }`
3. `GET /api/v1/state?id=…` nach `meta.poll_after_ms` pollen
4. Die Mail öffnet `/auth/check`; die Seite entfernt das Fragment aus der URL
   und verbraucht es über `POST /api/v1/exchange`
5. Nur der Link-Browser erhält die authentifizierte MagicLink-Session

Der anfragende Browser darf `verified` sehen, erhält aber weder E-Mail-Adresse
noch Authentifizierung. Niemals aus einem State lokal einen Login ableiten.

## Endpunkte

### `GET /api/v1/config`

Liefert App-Name und Sprache, CSRF-Token, absolute Endpoint-URLs, State-Katalog,
Feature-Flags und effektive öffentliche Limits. Secrets werden nicht ausgegeben.
Prüfe `features.server_handoff`, bevor die UI einen Handoff anbietet.

### `POST /api/v1/requests`

```json
{ "email": "you@example.com" }
```

Antwortet mit HTTP 202 und `id`, `state`, `masked_email`, `expires_at`. Erlaubte
und nicht erlaubte Identitäten haben dieselbe öffentliche Form und denselben
Queue-Pfad; nur erlaubte Einträge werden zugestellt. IP-, Identitäts- und
globales Zeitfenster-Budget sowie Queue-Kapazität können HTTP 429 auslösen.

### `GET /api/v1/state?id=ml_…`

```json
{
  "id": "ml_…",
  "state": "waiting",
  "message": "…",
  "verified": false,
  "terminal": false,
  "expires_at": 1791450000,
  "authenticated": false
}
```

Die ID muss zur aktuellen Browser-Session gehören. Die Antwort enthält weder
E-Mail-Adresse noch Secret-Token und authentifiziert den Polling-Browser nicht.
Nicht schneller als `meta.poll_after_ms` abfragen.

### `POST /api/v1/states`

```json
{ "ids": ["ml_…", "ml_…"] }
```

Liest mehrere sessioneigene Requests in einer Datenbankabfrage. Das Maximum
steht in `limits.state_batch_max`; das empfohlene Polling-Intervall wächst mit
dem Batch. Der Request benötigt CSRF-Schutz.

### `POST /api/v1/exchange`

```json
{ "id": "ml_…", "token": "fragment-secret" }
```

Verbraucht den Mail-Token atomar und rotiert die Session-ID. Normalerweise ruft
die mitgelieferte `/auth/check`-Seite ihn auf, nachdem sie das URL-Fragment
entfernt hat. Hat derselbe Browser vorher eine RP-erzeugte `authorize_url`
geöffnet, schließt MagicLink auch diese Transaktion ab: `data.redirect` bringt
Authorization-`code` und ursprünglichen `state` zum registrierten Callback;
`data.handoff` meldet `authorized`, `retryable` oder `unavailable`. Der Mail-Token
wird nie weitergereicht.

### `GET /api/v1/session`

Liefert `authenticated` und ausschließlich für die Session, die den Link
verbraucht hat, die normalisierte `email`.

### `POST /api/v1/logout`

Zerstört die MagicLink-Session. Der CSRF-Header ist erforderlich.

## Optionaler Server-Handoff

Dies ist ein OAuth-ähnlicher Confidential-Client-Authorization-Code-Flow, kein
allgemeiner OAuth- oder OpenID-Connect-Provider. Er unterstützt genau ein RP,
eine exakte Redirect-URI und ausschließlich eine E-Mail-Identität – niemals
Rollen oder Autorisierung. Ohne beide Handoff-Einstellungen bleibt er deaktiviert.
Das RP startet jede Transaktion; ein MagicLink-Browser kann keine ungeforderte
Identitätsübergabe erzeugen. Das frühere browserseitige
`POST /api/v1/handoffs` liefert bewusst 404.

### `POST /api/v1/handoffs/transactions`

Server-to-server mit Bearer-Client-Secret:

```http
Authorization: Bearer <HANDOFF_CLIENT_SECRET>
Content-Type: application/json

{
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "state": "43-bis-128-Zeichen-Base64url-Wert",
  "code_challenge": "Base64url-SHA256-des-Code-Verifiers",
  "code_challenge_method": "S256"
}
```

Die Redirect-URI muss exakt `HANDOFF_REDIRECT_URL` entsprechen; nur PKCE S256 ist
erlaubt. HTTP 201 liefert `authorize_url` und `expires_at`. State entsteht aus
mindestens 32 Zufallsbytes. Das RP speichert diesen Soll-State und den 43–128
Zeichen langen PKCE-Verifier gebunden an die startende RP-Browser-Session und
schickt den Browser zu `authorize_url`. Eine globale State-Suche ersetzt diese
Browser-Session-Bindung nicht sicher. Nur die S256-Challenge verlässt das RP.

### `GET /api/v1/handoffs/authorize?request=…`

Der Browser folgt der oben gelieferten opaken URL. Sie bindet die Transaktion an
die aktuelle MagicLink-Browser-Session. Ein anonymer Browser wird zur normalen
Anmeldung geleitet; ein bereits angemeldeter direkt zum registrierten Callback.
Der Mail-Link muss in diesem selben Browser abgeschlossen werden. Anderswo kann
er dort eine lokale MagicLink-Session erzeugen, doch `data.handoff` bleibt
`null` und das RP erhält keine Identität. `unavailable` bedeutet dagegen, dass
diese Session einen wartenden Request hatte, der ablief oder die Prüfung nicht
bestand. Weil eine bestehende MagicLink-Session sofort autorisieren kann, bleiben
State und PKCE zwingend.

### `POST /api/v1/handoffs/authorize`

Schließt die wartende Transaktion für einen bereits angemeldeten MagicLink-
Browser ab. Session-Cookie und CSRF-Header sind nötig; die Antwort enthält
`redirect` und `expires_at`. Das ist der explizite Retry-/Custom-UI-Endpoint; der
normale Login schließt die Transaktion in `POST /api/v1/exchange` ab. Ein
temporärer Handoff-Fehler liefert `handoff.retryable` mit HTTP 503, `Retry-After`
und `meta.retry_url`; die Anmeldung bleibt erfolgreich und der Magic-Token ist
bereits verbraucht. Dieser URL folgen – niemals den Mail-Token erneut verwenden.
Ein erfolgreicher Retry stellt das bestehende Authorization-Ergebnis wieder her,
solange es gültig ist.

### `POST /api/v1/handoffs/exchange`

```http
Authorization: Bearer <HANDOFF_CLIENT_SECRET>
Content-Type: application/json

{
  "code": "browser-visible-one-time-code",
  "redirect_uri": "https://app.example.com/auth/magic-link",
  "code_verifier": "serverseitiger-ursprünglicher-pkce-verifier"
}
```

Der Callback vergleicht zuerst den zurückgelieferten `state` mit dem serverseitig
gespeicherten Sollwert und ruft dann diesen Server-to-server-Endpoint auf. Ein
gültiger Exchange liefert die normalisierte `email`; der Code ist kurzlebig und
einmalig, Redirect-URI und PKCE-Verifier müssen zur ursprünglichen Transaktion
passen. Abweichender Verifier/Redirect, Ablauf und Replay liefern absichtlich
denselben HTTP 410; die UI darf den verborgenen Grund nicht unterschiedlich
behandeln.

`HANDOFF_CLIENT_SECRET` und PKCE-Verifier gehören weder in Browser, Repository,
Logs noch URLs. Sofort austauschen, eigene RP-Session erstellen, gespeicherten
State/Verifier löschen und auf eine saubere URL ohne `code` oder `state`
weiterleiten. Prüfen und austauschen, bevor HTML gerendert oder Drittskripte
geladen werden; der Callback sollte `Cache-Control: no-store` und
`Referrer-Policy: no-referrer` senden und Query-Strings aus Logs halten. Die
State-Endpunkte bleiben reine Beobachtung und können weder Identität noch Session
ans RP übertragen.

## State-Werte

| State | Terminal | Bedeutung |
| --- | --- | --- |
| `requested` | nein | Anfrage angenommen |
| `waiting` | nein | Aktiver Link oder enumeration-sicherer Decoy |
| `verified` | ja | Token auf dem Link-Gerät verbraucht |
| `expired` | ja | Abgelaufen oder durch neueren Link ersetzt |
| `replayed` | ja | Interner Replay-Zustand |
| `rate_limited` | nein | Budget verbraucht; `Retry-After` beachten |
| `denied` | ja | Interner Allowlist-Zustand, öffentlich neutralisiert |
| `failed` | ja | Ungültiger Übergang |

## Separate UI und CORS

```dotenv
API_ALLOWED_ORIGINS=https://app.example.com
SESSION_SAMESITE=None
AUTH_SUCCESS_URL=https://app.example.com/signed-in
```

Origins werden exakt verglichen; Wildcards gibt es nicht. In Production müssen
Backend, erlaubte Origins und Success-URL HTTPS verwenden. Bei einer UI auf
derselben Site bleibt `Lax` die sicherere Voreinstellung. Clients beachten bei
HTTP 429 sowohl `Retry-After` als auch `meta.poll_after_ms`; Body-Größe und
JSON-Tiefe sind serverseitig begrenzt.
