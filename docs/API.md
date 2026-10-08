# Headless API v1

Die mitgelieferte Oberfläche verwendet dieselbe API wie eine eigene UI. Alle
Antworten haben eine stabile Hülle:

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

Bei Fehlern ist `data` null und `error` enthält `message` sowie optional
`retry_after`. Clients verzweigen auf `code`, nicht auf übersetzten Text.

## Browser-Flow

Alle Aufrufe senden Cookies (`credentials: "include"`). Schreibende Requests
verwenden den CSRF-Token aus `GET /api/v1/config` als Header
`X-CSRF-Token`. Ein optionales `X-Request-ID` mit 8–64 sicheren Zeichen wird in
Antwort und Logs korreliert; andernfalls erzeugt der Server eine ID.

1. `GET /api/v1/config`
2. `POST /api/v1/requests` mit `{ "email": "you@example.com" }`
3. `GET /api/v1/state?id=…` nach `meta.poll_after_ms`
4. Der Mail-Link öffnet `/auth/check`; das Fragment wird dort per
   `POST /api/v1/exchange` verbraucht.
5. Nur dieser Link-Browser ist danach angemeldet. Der Request-Browser sieht
   `verified`, erhält aber weder Identität noch Sitzung.

Diese Trennung ist eine Sicherheitsgrenze. Ein Client darf aus `verified` niemals
lokal eine angemeldete Sitzung ableiten.

## Endpunkte

### `GET /api/v1/config`

Liefert App-Name, Sprache, CSRF-Token, absolute Endpoint-URLs, State-Katalog,
Fähigkeiten und die aktuell berechneten Limits. Keine geheimen Config-Werte
werden ausgegeben.

### `POST /api/v1/requests`

```json
{ "email": "you@example.com" }
```

Antwortet mit HTTP 202 und `id`, `state`, `masked_email`, `expires_at`. Erlaubte
und nicht erlaubte Identitäten erhalten dieselbe öffentliche Form und denselben
Queue-Pfad; nur erlaubte Einträge werden zugestellt.

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

Der Request muss zur aktuellen Browser-Session gehören. Die Antwort enthält
absichtlich keine E-Mail-Adresse und authentifiziert den Polling-Browser nicht.

### `POST /api/v1/states`

```json
{ "ids": ["ml_…", "ml_…"] }
```

Fasst eigene State-Abfragen in einem SQL-Read zusammen. Die Obergrenze wird als
`limits.state_batch_max` in `/config` veröffentlicht. Alle IDs müssen der
aktuellen Session gehören. Das empfohlene Poll-Intervall wächst automatisch mit
der Batchgröße.

### `POST /api/v1/exchange`

```json
{ "id": "ml_…", "token": "fragment-secret" }
```

Verbraucht den Token atomar und rotiert die Session-ID. Normalerweise ruft die
mitgelieferte `/auth/check`-Seite diesen Endpoint auf, nachdem sie das Fragment
aus der Adresszeile entfernt hat.

### `GET /api/v1/session`

Liefert `authenticated` und – nur für die tatsächlich angemeldete Session – die
normalisierte `email`.

### `POST /api/v1/logout`

Beendet die Session. CSRF-Header ist erforderlich.

## State-Codes

| Code | Terminal | Bedeutung |
| --- | --- | --- |
| `requested` | nein | Anfrage angenommen |
| `waiting` | nein | Link aktiv oder enumeration-sicherer Decoy |
| `verified` | ja | Token wurde auf dem Link-Gerät verbraucht |
| `expired` | ja | abgelaufen oder durch neueren Link ersetzt |
| `replayed` | ja | interner Replay-Zustand |
| `rate_limited` | nein | Budget verbraucht; `Retry-After` beachten |
| `denied` | ja | interner Allowlist-Zustand, öffentlich neutralisiert |
| `failed` | ja | ungültige Übergabe |

## Separate UI und CORS

```dotenv
API_ALLOWED_ORIGINS=https://app.example.com
SESSION_SAMESITE=None
AUTH_SUCCESS_URL=https://app.example.com/signed-in
```

Origins werden exakt verglichen, Wildcards sind nicht erlaubt. In Production
müssen Backend, erlaubte Origins und Success-URL HTTPS verwenden. `SameSite=None`
ist nur mit HTTPS gültig. Bei einer UI auf derselben Site bleibt `Lax` die bessere
Voreinstellung.

Browser sollten bei HTTP 429 sowohl `Retry-After` als auch
`meta.poll_after_ms` respektieren. Maximale Request-Größe und JSON-Tiefe sind
serverseitig begrenzt.
