# Betrieb und Last

**Deutsch** · [English](OPERATIONS.md)

MagicLink startet ohne Dienstprozess, hat aber einen bewussten Wachstumspfad.

## Response-Layer

1. HTTP-Grenzen prüfen Body-Größe, JSON, Session, CSRF, Origin und Request-
   Budgets.
2. Eine kurze DB-Transaktion legt Link, Audit und verschlüsselte Outbox gemeinsam
   an.
3. HTTP 202 wird abgeschlossen und der Session-Lock freigegeben.
4. Erst danach versucht der Zero-Setup-Modus eine fällige Queue-Zeile.

Unter PHP-FPM schließt `fastcgi_finish_request()` die sichtbare Antwort vor der
Mailzustellung ab. Wenn ein Host frühes Flushen nicht zuverlässig unterstützt
oder das Volumen steigt, `MAIL_AUTO_DISPATCH=false` setzen und den Worker nutzen.
Dann wartet die öffentliche Latenz nie auf SMTP.

## Backpressure vor dem Ausfall

Requests passieren zuerst ein globales festes Zeitfenster-Budget, danach IP- und
Identitätsbudgets. Zusätzlich zählt der Service `pending` plus `sending`, bevor er
mehr Arbeit annimmt. `MAIL_PENDING_MAX=auto` erlaubt 500 auf SQLite und 5000 auf
MySQL; ein expliziter Wert von 10–100000 bleibt unter Betreiberkontrolle. Bei
Kapazität antwortet die API mit HTTP 429 und Retry-Hinweis, statt die Queue
unbegrenzt wachsen zu lassen. Am Cap werden alle Identitäten identisch abgewiesen
– auch mit bereits wartender Zeile. So wird Kapazität nicht zum Account-Orakel.

Das globale Budget schützt die Anwendung. Für volumetrische Angriffe bleiben
CDN/WAF- und Host-Verbindungslimits davor nötig; App-Limits sind kein DDoS-Dienst.

## Shared-Hosting-Cron

```cron
* * * * * /usr/bin/php /home/account/magiclink/bin/worker.php --once >/dev/null 2>&1
*/15 * * * * /usr/bin/php /home/account/magiclink/bin/maintain.php --all >/dev/null 2>&1
```

Ein Lauf beansprucht nur fällige Zeilen. Verlassene Claims werden nach
`MAIL_LOCK_TIMEOUT_SECONDS` wieder verfügbar; Retry-Delays wachsen exponentiell
mit Jitter bis maximal eine Stunde.

## VPS-Worker

```bash
php bin/worker.php --loop
```

Jeder Durchlauf schreibt kompaktes JSON mit `claimed`, `sent`, `retried`,
`failed` und `lost`. systemd oder Supervisor kann den Prozess neu starten.
`--batch=50` und `--sleep=2` überschreiben die begrenzten Defaults.

SQLite nutzt WAL und kurze serialisierte Schreibtransaktionen. Für ein Frontend
und moderate Loginlast ist es am einfachsten. Mehrere PHP-FPM-Knoten, Worker oder
dauerhafte Parallelität sprechen für MySQL. Wenn MySQL ein nicht
vertrauenswürdiges Netz quert, `DB_SSL_CA` und `DB_SSL_MODE=verify_identity`
setzen; Remote-Production bietet keinen TLS-Modus ohne Hostnamenprüfung. MySQL sperrt geclaimte
Zeilen, damit Worker dieselbe Queue-Zeile nicht absichtlich parallel zustellen.

Mehrere Web-Knoten brauchen außerdem gemeinsamen Session-State. Den gemeinsamen
PHP-Handler hostseitig konfigurieren und `SESSION_STORAGE=configured` setzen –
oder Sticky Routing garantieren. Der lokale File-Store ist kein Cross-Node-
Session-Store.

## Zustellsemantik

- Ein Claim hat Ownership-Token und Lease; der Worker erneuert sie um Netzwerk-
  I/O und protokolliert Ownership-Verlust, statt eine fremde Zeile zu ändern.
- Eine SMTP-Verbindung wird im geclaimten Batch wiederverwendet, per `NOOP`
  geprüft und danach geschlossen. Fehlerhafte Verbindungen werden verworfen.
- SMTP 4xx und Transportfehler werden wiederholt; SMTP 5xx sowie Config- oder
  Payload-Fehler beenden die Zeile. `MAIL_MAX_ATTEMPTS` ist die letzte Grenze.
- MIME-Header und Bodys werden kodiert. Eine aus dem Link-Selector abgeleitete
  `Message-ID` bleibt über Retries stabil.

Die Zustellung ist **at least once**, nicht exactly once. Hat ein SMTP-Server die
Mail angenommen, aber die Verbindung bricht vor der Bestätigung zum Worker ab,
ist ein Retry sicherer als eine verlorene Login-Mail und kann ein Duplikat
erzeugen. Die stabile `Message-ID` hilft nachgelagerter Deduplizierung, garantiert
sie aber nicht. Jeder Magic-Link-Token bleibt auch in doppelten Mails einmalig.

## State-Polling

- Einzelabfragen folgen `meta.poll_after_ms`.
- Eigene IDs lassen sich über `POST /api/v1/states` bündeln.
- `auto` erlaubt 32 IDs auf SQLite und 100 auf MySQL.
- Das Intervall wächst pro angefangenen zehn IDs bis maximal 15 Sekunden.
- Zu frühes Polling liefert HTTP 429 mit `Retry-After` statt mehr DB-Arbeit.

Bei `terminal=true` stoppen. `verified` ist eine Beobachtung, keine Berechtigung
für den Polling-Browser; State-Endpunkte zeigen weder Identität noch Token.

## Retention und Speicher

Nach jeder Response plant ein einzelner indexierter Due-Check das Cleanup im
konfigurierten Intervall. `MAINTENANCE_BATCH=auto` berechnet aus zugelassenen
Request-/Handoff-Raten einen begrenzten Batch mit 25% Reserve. Die Defaults haben
damit auch ohne Cron Luft; dedizierte Läufe bleiben planbarer:

```bash
php bin/maintain.php --all
```

Cleanup entfernt in begrenzten Batches abgelaufene Links und Handoffs, Rate-
Zähler, alte Audits und abgeschlossene Outbox-Zeilen. Retention bleibt über
`.env` steuerbar. Vor Updates Datenbank und `storage/app.key` gemeinsam sichern;
Schema-Migrationen sind additiv und laufen beim Boot. `storage/sessions`, Key,
Datenbank und eigene Templates bleiben außerhalb des Document Roots.

Für ein kontrolliertes Rollout vor dem Worker-Wechsel `php bin/migrate.php`
ausführen. Der Boot prüft das Schema weiterhin; MySQL serialisiert Migrationen
mit einem DB-Advisory-Lock, damit mehrere Knoten nicht gleichzeitig dieselbe DDL
anwenden.

## Abuse-Schichten

- Globales und IP-Budget werden vor E-Mail-Validierung verbraucht.
- Identitätsbudgets verwenden HMACs statt Klartext.
- Tokenversuche sind pro IP und Selector begrenzt.
- Token-Exchange besitzt zusätzlich ein globales Installationsbudget.
- Unbekannte oder gesperrte Identitäten sehen denselben öffentlichen Request.
- State-IDs müssen zur serverseitigen Session gehören.
- Body, JSON-Tiefe, Sessionlaufzeit und gemerkte Requests sind begrenzt.
- Mitgelieferte Ingress-/PHP-Configs begrenzen Bodys vor dem Parser; Multipart wird abgewiesen.
- File-Sessions besitzen eine begrenzte PHP-GC-Policy; `/health` erzeugt keine Session.
- Nicht vertrauenswürdiges `X-Forwarded-For` wird ignoriert.
- Queue-Kapazität und Retention begrenzen gespeicherte Arbeit.
- RP-Handoff-Starts sind global rate-limited; Transaktionen sind an denselben
  Browser, State und PKCE gebunden, Codes kurzlebig und einmalig.

## Checks und Beobachtung

```bash
php bin/doctor.php
php bin/check.php
php bin/status.php
php bin/status.php --json
```

`doctor` prüft Runtime, Storage, Verschlüsselung und Schema. `status` zeigt
Queue-Zahlen, Alter des ältesten Pending-Eintrags, stale Claims, letzte
Zustellung und Maintenance, aktive Handoffs sowie effektive Batch-/Kapazitätswerte,
ohne sie über HTTP zu veröffentlichen. `/health` verrät bewusst nur Liveness.

Alarmiere bei wachsendem Pending-Alter, stale Claims über mehr als einen Worker-
Zyklus, dauerhafter Queue-Nähe zu `MAIL_PENDING_MAX` oder wiederholten terminalen
Fehlern. Secrets, E-Mail-Adressen, Handoff-Codes und Query-Strings gehören nicht
in Logs oder Metriken.
