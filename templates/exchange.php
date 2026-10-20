<div class="content-block" data-exchange data-selector="<?= $e($selector) ?>" data-endpoint="<?= $e($endpoint) ?>" data-csrf="<?= $e($csrf) ?>">
    <p class="context"><?= $locale === 'de' ? 'Sichere Übergabe' : 'Secure handoff' ?></p>
    <h1 data-exchange-title><?= $locale === 'de' ? 'Link wird geprüft.' : 'Checking your link.' ?></h1>
    <p class="lede" role="status" data-exchange-message><?= $locale === 'de' ? 'Das Geheimnis wird lokal aus dem URL-Fragment gelesen und geschützt an diesen Server übergeben.' : 'The secret is read locally from the URL fragment and sent securely to this server.' ?></p>
    <div data-exchange-confirmation hidden>
        <p class="notice" id="exchange-warning" data-exchange-warning></p>
        <p class="confirmation-identity" id="exchange-identity" data-exchange-identity></p>
        <button type="button" aria-describedby="exchange-identity exchange-warning" data-exchange-confirm><?= $locale === 'de' ? 'Mit dieser E-Mail anmelden' : 'Sign in with this email' ?></button>
        <p><a href="<?= $e($this->config->path('/')) ?>"><?= $locale === 'de' ? 'Abbrechen' : 'Cancel' ?></a></p>
    </div>
    <div class="exchange-meter" aria-hidden="true"><span></span></div>
    <noscript><p class="notice is-error"><?= $locale === 'de' ? 'Für den sicheren Fragment-Austausch muss JavaScript aktiviert sein.' : 'JavaScript is required for the secure fragment exchange.' ?></p></noscript>
</div>
