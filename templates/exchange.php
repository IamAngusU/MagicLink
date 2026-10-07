<div class="content-block" data-exchange data-selector="<?= $e($selector) ?>" data-endpoint="<?= $e($endpoint) ?>" data-csrf="<?= $e($csrf) ?>">
    <p class="context"><?= $locale === 'de' ? 'Sichere Übergabe' : 'Secure handoff' ?></p>
    <h1><?= $locale === 'de' ? 'Link wird bestätigt.' : 'Confirming your link.' ?></h1>
    <p class="lede" data-exchange-message><?= $locale === 'de' ? 'Das Geheimnis wird lokal aus dem URL-Fragment gelesen und geschützt an diesen Server übergeben.' : 'The secret is read locally from the URL fragment and sent securely to this server.' ?></p>
    <div class="exchange-meter" aria-hidden="true"><span></span></div>
    <noscript><p class="notice is-error"><?= $locale === 'de' ? 'Für den sicheren Fragment-Austausch muss JavaScript aktiviert sein.' : 'JavaScript is required for the secure fragment exchange.' ?></p></noscript>
</div>
