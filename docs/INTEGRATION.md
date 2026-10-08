# Integration

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

## Eigene oder getrennte UI

Nutze die versionierten Endpunkte aus [API.md](API.md). Die UI holt zuerst
`/api/v1/config`, übernimmt CSRF-Token, absolute Endpoint-URLs, State-Katalog und
berechnete Limits und sendet immer Cookies mit.

Bei einer anderen Origin konfigurierst du eine exakte `API_ALLOWED_ORIGINS`,
HTTPS, `SESSION_SAMESITE=None` und üblicherweise `AUTH_SUCCESS_URL`. Teile oder
kopiere keine Session-Cookies zwischen Hosts.

## Anderer Backend-Stack

Die Browser-Session gehört dem MagicLink-Host. Wenn ein anderes Backend seine
eigene serverseitige Identität braucht, ergänze einen separaten, kurzlebigen und
einmalig konsumierbaren Handoff, der auf genau dieses Zielsystem begrenzt ist.
Der E-Mail-Token darf nicht zugleich als Backend-zu-Backend-Credential dienen.

Die mitgelieferte Beispielseite ist nur ein Übergabepunkt. Templates liegen in
`templates/`, Design in `public/assets/`; stabile Logik und Zustände bleiben in
der API.
