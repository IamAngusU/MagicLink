# Konfiguration

`.env` ist die Nutzeroberfläche der Infrastruktur. Sichere Werte sind bereits
gesetzt; jede `auto`-Entscheidung kann explizit überschrieben werden.

## Anwendung und Datenbank

| Variable | Default | Zweck |
| --- | --- | --- |
| `APP_ENV` | `production` | `production`, `local` oder `test` |
| `APP_URL` | erforderlich | kanonische öffentliche Backend-URL, inklusive Unterpfad |
| `APP_NAME` | `Magic Link` | sichtbarer Produktname |
| `APP_LOCALE` | `de` | `de` oder `en` |
| `AUTH_SUCCESS_URL` | leer | absolute Ziel-URL nach erfolgreichem Link; sonst App-Start |
| `APP_KEY` | automatisch | Base64-kodierte 32 Bytes; sonst atomar in `storage/app.key` |
| `DB_DRIVER` | `sqlite` | `sqlite` oder `mysql` |
| `DB_PATH` | `storage/database.sqlite` | SQLite-Datei |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | – | MySQL-Verbindung |

`APP_KEY` und Datenbank gehören gemeinsam ins Backup. Wer beides lesen kann,
kann gespeicherte Identitäten entschlüsseln.

## Zugriff und Lebensdauer

| Variable | Default | Grenze |
| --- | --- | --- |
| `MAGICLINK_ALLOWED_EMAILS` | leer | kommaseparierte exakte Adressen |
| `MAGICLINK_ALLOWED_DOMAINS` | leer | Domains inklusive Subdomains |
| `MAGICLINK_ALLOW_ANY_EMAIL` | `false` | offene Registrierung nur bewusst aktivieren |
| `MAGICLINK_TTL_SECONDS` | `900` | 120–3600 |
| `MAGICLINK_RETENTION_SECONDS` | `604800` | 1 Stunde–365 Tage |
| `AUDIT_RETENTION_SECONDS` | `2592000` | 1 Stunde–365 Tage |

Ohne Allowlist startet die App nicht, außer `MAGICLINK_ALLOW_ANY_EMAIL=true` ist
explizit gesetzt.

## Abuse- und HTTP-Limits

| Variable | Default | Zweck |
| --- | --- | --- |
| `MAGICLINK_IP_LIMIT` | `10` | Anfragen pro IP/Fenster |
| `MAGICLINK_EMAIL_LIMIT` | `5` | Anfragen pro Identität/Fenster |
| `MAGICLINK_RATE_WINDOW` | `3600` | Request-Fenster in Sekunden |
| `MAGICLINK_EXCHANGE_IP_LIMIT` | `60` | Exchanges pro IP/Fenster |
| `MAGICLINK_EXCHANGE_SELECTOR_LIMIT` | `10` | Versuche pro Link/Fenster |
| `MAGICLINK_EXCHANGE_WINDOW` | `900` | Exchange-Fenster |
| `HTTP_MAX_BODY_BYTES` | `16384` | maximale JSON/Form-Größe |
| `MAGICLINK_POLL_AFTER_MS` | `2500` | Basisintervall für State-Polling |

Zähler speichern nur HMAC-Buckets. Ablehnungs-Audits entstehen erst hinter dem
begrenzten Exchange-Budget und können daher nicht unbegrenzt wachsen.

## Mail und Queue

| Variable | Default | Zweck |
| --- | --- | --- |
| `MAIL_TRANSPORT` | `mail` | `mail`, `smtp`, lokal auch `log` |
| `MAIL_FROM_ADDRESS` | erforderlich | Envelope-/From-Adresse |
| `MAIL_FROM_NAME` | App-Name | sichtbarer Absender |
| `SMTP_HOST`, `SMTP_PORT` | – / `587` | Server und Port |
| `SMTP_ENCRYPTION` | `tls` | `tls`, `ssl`, lokal auch `none` |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | leer | optionale Anmeldung |
| `MAIL_AUTO_DISPATCH` | `true` | eine Queue-Position nach HTTP-Antwort bearbeiten |
| `MAIL_WORKER_BATCH` | `auto` | SQLite 25, MySQL 100; Maximum 250 |
| `MAIL_MAX_ATTEMPTS` | `5` | 1–20 mit exponentiellem Retry |
| `MAIL_LOCK_TIMEOUT_SECONDS` | `300` | Recovery abgebrochener Worker |
| `MAIL_RETENTION_SECONDS` | `604800` | fertige Queue-Einträge behalten |

Production verbietet `log` und unverschlüsseltes SMTP. Zertifikate werden
geprüft; SMTP-Zeilen, Antwortgröße und Netzwerkzeit sind begrenzt.

## Sessions, UI und Proxy

| Variable | Default | Zweck |
| --- | --- | --- |
| `SESSION_NAME` | `magiclink_session` | Cookie-Name |
| `SESSION_SAMESITE` | `Lax` | `Lax`, `Strict`, `None` |
| `SESSION_IDLE_SECONDS` | `28800` | Leerlaufgrenze |
| `SESSION_ABSOLUTE_SECONDS` | `604800` | absolute Lebensdauer |
| `API_ALLOWED_ORIGINS` | leer | exakte, kommaseparierte UI-Origins |
| `TRUSTED_PROXIES` | leer | exakte IPs oder CIDRs eigener Proxies |

Forwarding-Header werden ignoriert, solange der direkte Peer nicht in
`TRUSTED_PROXIES` steht. Wildcard-CORS existiert bewusst nicht.

## Automatische Batches

| Variable | SQLite | MySQL | Harte Grenze |
| --- | ---: | ---: | ---: |
| `MAGICLINK_STATE_BATCH_MAX` | 32 | 100 | 100 |
| `MAIL_WORKER_BATCH` | 25 | 100 | 250 |
| `MAINTENANCE_BATCH` | 250 | 1000 | 5000 |

`auto` wird ausschließlich aus dem aktiven Datenbanktreiber abgeleitet – nicht
aus undurchsichtigen Telemetriedaten. Ein Integer überschreibt die Auswahl. Das
Batch-Polling erhöht das Intervall pro angefangenen zehn IDs und deckelt es bei
15 Sekunden.
