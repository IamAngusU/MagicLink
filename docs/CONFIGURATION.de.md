# Konfiguration

**Deutsch** · [English](CONFIGURATION.md)

`.env` ist die Nutzeroberfläche der Infrastruktur. Sichere Defaults sind gesetzt;
jede `auto`-Entscheidung lässt sich explizit überschreiben.

## Anwendung und Datenbank

| Variable | Default | Zweck |
| --- | --- | --- |
| `APP_ENV` | `production` | `production`, `local` oder `test` |
| `APP_URL` | erforderlich | kanonische öffentliche Backend-URL inklusive Unterpfad |
| `APP_NAME` | `Magic Link` | sichtbarer Produktname |
| `APP_LOCALE` | `de` | `de` oder `en` |
| `AUTH_SUCCESS_URL` | leer | absolutes Ziel nach erfolgreichem Link, sonst App-Start |
| `APP_KEY` | automatisch | Base64-kodierte 32 Bytes, sonst atomar in `storage/app.key` |
| `DB_DRIVER` | `sqlite` | `sqlite` oder `mysql` |
| `DB_PATH` | `storage/database.sqlite` | SQLite-Datei, relativ zum App-Root oder absolut |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | — | MySQL-Verbindung |
| `DB_SSL_MODE` | `auto` | `auto`, `verify_identity` oder `disabled` |
| `DB_SSL_CA` | leer | optionale lokale CA-Datei für verifiziertes MySQL-TLS |

`APP_KEY` und Datenbank gehören gemeinsam ins Backup. Wer beides lesen kann,
kann gespeicherte Identitäten entschlüsseln. Bei `auto` wählt eine vorhandene CA
`verify_identity`; ohne CA bleibt die Verbindung nur dort unverschlüsselt, wo die
Policy es erlaubt. Remote-MySQL in Production schlägt ohne TLS und CA geschlossen
fehl. `verify_identity` prüft Zertifikat und Hostname. `disabled` ist nur dort
zulässig, wo die Production-Policy eine lokale Datenbankverbindung erlaubt.
MySQL nutzt den `mysqlnd`-PDO-Backend; andere Client-Backends werden abgewiesen,
damit TLS-Capability-Stripping nicht von einer ungetesteten Fallback-Policy abhängt.

## Optionaler Server-Handoff

| Variable | Default | Zweck |
| --- | --- | --- |
| `HANDOFF_REDIRECT_URL` | leer | eine registrierte, exakt passende HTTPS-Callback-URL |
| `HANDOFF_CLIENT_SECRET` | leer | gemeinsames Base64-kodiertes 32-Byte-Server-Credential |
| `HANDOFF_TRANSACTION_TTL_SECONDS` | `900` | RP-Transaktion, 120–3600 Sekunden |
| `HANDOFF_CODE_TTL_SECONDS` | `60` | Einmalcode, 30–300 Sekunden |
| `HANDOFF_INIT_LIMIT` | `1000` | gestartete RP-Transaktionen pro Fenster |
| `HANDOFF_INIT_WINDOW` | `600` | Startfenster in Sekunden |
| `HANDOFF_RETENTION_SECONDS` | `86400` | abgelaufene Handoff-Zeilen bis zur Bereinigung |

Redirect und Secret müssen gemeinsam gesetzt sein. Production verlangt HTTPS;
die URL darf keine Credentials, kein Fragment und keine vorhandenen `code`- oder
`state`-Query-Keys enthalten. Secret erzeugen:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Es liegt nur in den privaten Umgebungen beider Server. Das Relying-Party-Backend
(RP) startet jede Transaktion mit unvorhersehbarem `state` und PKCE-S256-
Challenge und bindet Soll-State und Verifier an die startende RP-Browser-Session.
Sein Callback prüft `state`, tauscht den browserseitig sichtbaren Code mit
serverseitigem Verifier und exakter Redirect-URI und leitet danach sauber weiter.
Das ist ein OAuth-ähnlicher Handoff für ein E-Mail-Identity-RP, kein OAuth-/OIDC-
Provider oder Autorisierungssystem. Details:
[Integration](INTEGRATION.de.md#getrenntes-backend-rp-initiierter-handoff).

## Zugriff und Lebensdauer

| Variable | Default | Grenze |
| --- | --- | --- |
| `MAGICLINK_ALLOWED_EMAILS` | leer | kommaseparierte exakte Adressen |
| `MAGICLINK_ALLOWED_DOMAINS` | leer | Domains inklusive Subdomains |
| `MAGICLINK_ALLOW_ANY_EMAIL` | `false` | offene Registrierung bewusst aktivieren |
| `MAGICLINK_TTL_SECONDS` | `900` | 120–3600 Sekunden |
| `MAGICLINK_RETENTION_SECONDS` | `604800` | 1 Stunde–365 Tage |
| `AUDIT_RETENTION_SECONDS` | `2592000` | 1 Stunde–365 Tage |
| `MAINTENANCE_INTERVAL_SECONDS` | `300` | deterministisches Cleanup-Intervall, 60–86400 Sekunden |

Ohne Allowlist startet die App nicht, außer `MAGICLINK_ALLOW_ANY_EMAIL=true` ist
explizit gesetzt.

## Abuse- und HTTP-Limits

| Variable | Default | Zweck |
| --- | --- | --- |
| `MAGICLINK_GLOBAL_LIMIT` | `1000` | Requests der gesamten Installation pro Fenster |
| `MAGICLINK_IP_LIMIT` | `10` | Anfragen pro IP/Fenster |
| `MAGICLINK_EMAIL_LIMIT` | `5` | Anfragen pro Identität/Fenster |
| `MAGICLINK_RATE_WINDOW` | `3600` | Request-Fenster in Sekunden |
| `MAGICLINK_EXCHANGE_IP_LIMIT` | `60` | Exchanges pro IP/Fenster |
| `MAGICLINK_EXCHANGE_SELECTOR_LIMIT` | `10` | Versuche pro Link/Fenster |
| `MAGICLINK_EXCHANGE_GLOBAL_LIMIT` | `1000` | Exchanges der gesamten Installation/Fenster |
| `MAGICLINK_EXCHANGE_WINDOW` | `900` | Exchange-Fenster |
| `HTTP_MAX_BODY_BYTES` | `16384` | maximale JSON-/Form-Größe |
| `MAGICLINK_POLL_AFTER_MS` | `2500` | Basisintervall fürs State-Polling |

Das globale Budget wird vor der E-Mail-Validierung verbraucht; IP- und
Identitätsbudget grenzen Abuse weiter ein. Zähler speichern HMAC-Buckets statt
Identitäten im Klartext.

Die mitgelieferten Apache-, Nginx- und `.user.ini`-Beispiele erzwingen das
16-KiB-Default vor PHPs Form-Parser. MagicLink akzeptiert JSON und URL-encoded
Forms, aber keine Multipart-Uploads. Erhöhst du `HTTP_MAX_BODY_BYTES`, müssen
auch `LimitRequestBody`, `client_max_body_size`, `post_max_size` und
`upload_max_filesize` steigen; die kleinste Schicht gewinnt.

## Mail und Queue

| Variable | Default | Zweck |
| --- | --- | --- |
| `MAIL_TRANSPORT` | `mail` | `mail`, `smtp`, außerhalb Production auch `log` |
| `MAIL_FROM_ADDRESS` | erforderlich | Envelope-/From-Adresse |
| `MAIL_FROM_NAME` | App-Name | sichtbarer Absender |
| `MAIL_TEMPLATE_DIR` | leer | optionales lokales Template-Pack relativ zum App-Root |
| `SMTP_HOST`, `SMTP_PORT` | — / `587` | SMTP-Server und Port |
| `SMTP_ENCRYPTION` | `tls` | `tls`, `ssl`, außerhalb Production auch `none` |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | leer | optionale Anmeldung |
| `MAIL_AUTO_DISPATCH` | `true` | eine Queue-Zeile nach der HTTP-Antwort verarbeiten |
| `MAIL_WORKER_BATCH` | `auto` | SQLite 25, MySQL 100; Maximum 250 |
| `MAIL_PENDING_MAX` | `auto` | Backpressure ab 500 Pending/Sending auf SQLite, 5000 auf MySQL |
| `MAIL_MAX_ATTEMPTS` | `5` | 1–20 Zustellversuche |
| `MAIL_LOCK_TIMEOUT_SECONDS` | `300` | Claim-Lease und Recovery abgebrochener Worker |
| `MAIL_RETENTION_SECONDS` | `604800` | fertige Queue-Zeilen behalten |

Production verbietet `log` und unverschlüsseltes SMTP. TLS-Zertifikat und
Hostname werden geprüft; SMTP nutzt ausschließlich TLS 1.2 oder neuer.
Antwortgröße, Zeilenzahl und Netzwerkzeit sind begrenzt.

### Eigene Mail-Templates

Die eingebauten deutschen und englischen Mails funktionieren ohne Config. Für
eigenes Branding `resources/mail-templates` nach `storage/mail-templates`
kopieren, die Kopie bearbeiten und setzen:

```dotenv
MAIL_TEMPLATE_DIR=storage/mail-templates
```

Jede Sprache benötigt `subject.txt`, `plain.txt` und `html.html`. Templates sind
reines UTF-8, niemals PHP. Im Body sind `{{app_name}}`,
`{{expires_minutes}}`, `{{magic_link}}` und `{{recipient}}` erlaubt; der Betreff
akzeptiert nur die ersten beiden. Beide Bodys müssen `{{magic_link}}` enthalten.
Unbekannte oder kaputte Platzhalter, Steuerzeichen im Header und zu große
Ausgaben schlagen geschlossen fehl; in HTML eingesetzte Werte werden escaped.

Das Verzeichnis muss innerhalb der Installation, außerhalb von `public/` liegen
und darf weder Upload-Ziel noch für nicht vertrauenswürdige Nutzer beschreibbar
sein. Laufende Worker cachen das validierte Pack und werden nach Änderungen neu
gestartet. Die Vorschau nutzt einen klar ungültigen Dummy-Link und versendet
nichts:

```bash
php bin/mail-preview.php --locale=de --format=html > preview.html
php bin/mail-preview.php --locale=en --format=plain
```

## Sessions, UI und Proxies

| Variable | Default | Zweck |
| --- | --- | --- |
| `SESSION_NAME` | `magiclink_session` | Cookie-Name |
| `SESSION_STORAGE` | `files` | `files` oder der hostseitig `configured` Handler |
| `SESSION_SAVE_PATH` | `storage/sessions` | eigenes PHP-Session-Verzeichnis |
| `SESSION_SAMESITE` | `Lax` | `Lax`, `Strict` oder `None` |
| `SESSION_IDLE_SECONDS` | `28800` | Leerlaufgrenze |
| `SESSION_ABSOLUTE_SECONDS` | `604800` | absolute Lebensdauer |
| `API_ALLOWED_ORIGINS` | leer | exakte, kommaseparierte UI-Origins |
| `TRUSTED_PROXIES` | leer | exakte IPs oder CIDRs eigener Proxies |

Mit `files` liegt ein relativer Session-Pfad unter dem App-Root; MagicLink erstellt
ihn mit restriktiven Rechten, setzt die PHP-Garbage-Collection selbst und verlangt
Schreibbarkeit. `/health` bleibt stateless und erzeugt keine Session-Datei. Der
Pfad bleibt außerhalb des Document Roots. Bei mehreren Web-Knoten ohne Sticky Sessions setzt du
`SESSION_STORAGE=configured` und konfigurierst host-/PHP-seitig einen gemeinsamen
Session-Handler, etwa Redis; MagicLink lässt ihn dann unverändert. Lokale Dateien
sind nur mit Sticky Routing sicher. Forwarding-Header werden ignoriert, solange
der direkte Peer nicht vertrauenswürdig ist. Wildcard-CORS gibt es bewusst nicht.

## Automatische Größen

| Variable | SQLite | MySQL | Harte Grenze |
| --- | ---: | ---: | ---: |
| `MAGICLINK_STATE_BATCH_MAX` | 32 | 100 | 1–100 |
| `MAIL_WORKER_BATCH` | 25 | 100 | 1–250 |
| `MAIL_PENDING_MAX` | 500 | 5000 | 10–100000 |
| `MAINTENANCE_BATCH` | 1563* | 1563* | 10–5000 |

`auto` ist deterministisch und nutzt keine Telemetrie. State-, Worker- und Queue-
Werte hängen vom Datenbanktreiber ab. Maintenance wird aus Request-/Handoff-
Budgets und Cleanup-Intervall mit 25% Reserve berechnet und auf 10–5000 begrenzt;
`*` zeigt die Defaults. Ein Integer überschreibt jeden Auto-Wert. Batch-Polling
wächst pro angefangenen zehn IDs und endet bei 15 Sekunden.
