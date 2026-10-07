<div class="content-block" data-waiting data-state-url="<?= $e($stateUrl) ?>" data-home-url="<?= $e($homeUrl) ?>">
    <p class="context"><?= $locale === 'de' ? 'Link unterwegs' : 'Link on its way' ?></p>
    <h1><?= $locale === 'de' ? 'Lass diese Seite offen.' : 'Keep this page open.' ?></h1>
    <p class="lede" data-state-message><?= $e($stateMessage) ?></p>
    <div class="delivery-address">
        <span><?= $locale === 'de' ? 'Gesendet an' : 'Sent to' ?></span>
        <strong><?= $e($maskedEmail) ?></strong>
    </div>
    <div class="live-line" aria-live="polite">
        <i></i><span data-countdown data-expires-at="<?= $e($expiresAt) ?>"><?= $locale === 'de' ? 'Warte auf Bestätigung' : 'Waiting for confirmation' ?></span>
    </div>
    <a class="text-link" href="<?= $e($homeUrl) ?>"><?= $locale === 'de' ? 'Andere Adresse verwenden' : 'Use another address' ?></a>
</div>
