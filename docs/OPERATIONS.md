# Betrieb und Last

MagicLink startet ohne Dienstprozess, hat aber einen klaren Wachstumspfad.

## Response-Layer

1. HTTP-Grenzen prüfen Body-Größe, JSON, Session, CSRF, Origin und Rate Budget.
2. Eine kurze DB-Transaktion legt Link, Audit und verschlüsselte Outbox gemeinsam an.
3. HTTP 202 wird inklusive fester Länge erzeugt und die Session freigegeben.
4. Erst danach versucht der Zero-Setup-Modus genau eine Mail zuzustellen.

Unter PHP-FPM schließt `fastcgi_finish_request()` die sichtbare Antwort vor der
Zustellung ab. Wenn ein Hosting frühes Flushen nicht sauber unterstützt oder das
Volumen steigt, setze `MAIL_AUTO_DISPATCH=false` und nutze den Worker. Dann hängt
kein öffentlicher Request von SMTP ab.

## Shared-Hosting-Cron

```cron
* * * * * /usr/bin/php /home/account/magiclink/bin/worker.php --once >/dev/null 2>&1
*/15 * * * * /usr/bin/php /home/account/magiclink/bin/maintain.php --all >/dev/null 2>&1
```

Ein Lauf beansprucht nur fertige Queue-Zeilen. Abgebrochene Claims werden nach
`MAIL_LOCK_TIMEOUT_SECONDS` wieder freigegeben; Retry-Delays wachsen exponentiell
bis maximal eine Stunde.

## VPS-Worker

```bash
php bin/worker.php --loop
```

Der Prozess schreibt pro Durchlauf eine kompakte JSON-Zeile. Unter systemd oder
Supervisor kann er automatisch neu gestartet werden. `--batch=50` und
`--sleep=2` überschreiben die sicheren Defaults.

SQLite nutzt WAL und serialisierte kurze Schreibtransaktionen. Für ein einzelnes
Frontend und moderate Loginlast ist das die einfachste Wahl. Bei mehreren
PHP-FPM-Knoten, mehreren Workern oder dauerhaft hoher Parallelität ist MySQL die
bessere gemeinsame Queue. MySQL-Claims sperren die ausgewählten Zeilen, sodass
Worker nicht dieselbe Mail übernehmen.

## State-Polling

- Einzelabfragen folgen `meta.poll_after_ms`.
- Eigene Requests können über `POST /api/v1/states` gebündelt werden.
- `auto` erlaubt 32 IDs auf SQLite und 100 auf MySQL.
- Das Intervall wächst je angefangenen zehn IDs bis höchstens 15 Sekunden.
- Zu frühe Abfragen liefern 429 mit `Retry-After` statt zusätzliche DB-Arbeit.

Ein Client stoppt bei `terminal=true`. `verified` ist eine Beobachtung, keine
Berechtigung für den Polling-Browser.

## Retention und Speicher

Leichte, zufällig verteilte Maintenance-Läufe verhindern, dass eine normale
Installation ohne Cron unbegrenzt wächst. Dedizierte Läufe sind planbarer:

```bash
php bin/maintain.php --all
```

Gelöscht wird in begrenzten Batches: abgelaufene Links, Rate-Zähler, alte Audits
und abgeschlossene Outbox-Einträge. Retention bleibt vollständig in `.env`
steuerbar.

## Abuse-Schichten

- IP-Budget wird vor E-Mail-Validierung verbraucht.
- Identitäts-Budgets verwenden HMACs statt Klartext.
- Tokenversuche sind pro IP und pro Selector begrenzt.
- Unbekannte oder gesperrte Identitäten erhalten denselben öffentlichen Flow.
- State-IDs müssen zur serverseitigen Session gehören.
- Request-Body, JSON-Tiefe, Sessionlaufzeit und bekannte Requests pro Session sind
  hart begrenzt.
- Untrusted `X-Forwarded-For` wird ignoriert.
- Queue-, Audit- und Linkdaten haben Retention.

Das ersetzt keinen vorgelagerten DDoS-Schutz. Für öffentlich stark exponierte
Installationen gehören CDN/WAF-Limits vor PHP, ohne die App-eigenen Limits zu
entfernen.

## Checks und Beobachtung

```bash
php bin/doctor.php
php bin/check.php
```

`doctor` prüft Runtime, Storage, Verschlüsselung und Schema und zeigt Pending-
Queue sowie die effektiv berechneten Batches. `/health` bleibt absichtlich klein
und verrät weder Queuegröße noch Konfiguration.

Backups müssen Datenbank und `storage/app.key` gemeinsam enthalten. Vor Updates
beide sichern; Schemaänderungen sind additiv und werden beim Booten angewandt.
