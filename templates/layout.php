<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= $e($title ?? $appName) ?> · <?= $e($appName) ?></title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/app.css">
    <script src="<?= $e($assetBase) ?>/app.js" defer></script>
</head>
<body>
<main class="shell">
    <aside class="route-panel" aria-label="<?= $locale === 'de' ? 'Anmeldestatus' : 'Sign-in status' ?>">
        <a class="wordmark" href="<?= $e($this->config->path('/')) ?>" aria-label="<?= $e($appName) ?>">
            <span aria-hidden="true">[</span><?= $e($appName) ?><span aria-hidden="true">]</span>
        </a>
        <ol class="route" data-current-stage="<?= $e($stage ?? 'requested') ?>">
            <li class="<?= in_array($stage ?? '', ['requested', 'waiting', 'verified'], true) ? 'is-reached' : '' ?>">
                <span></span><strong><?= $locale === 'de' ? 'Angefordert' : 'Requested' ?></strong>
            </li>
            <li class="<?= in_array($stage ?? '', ['waiting', 'verified'], true) ? 'is-reached' : '' ?>">
                <span></span><strong><?= $locale === 'de' ? 'Postfach' : 'Inbox' ?></strong>
            </li>
            <li class="<?= ($stage ?? '') === 'verified' ? 'is-reached' : '' ?>">
                <span></span><strong><?= $locale === 'de' ? 'Bestätigt' : 'Verified' ?></strong>
            </li>
        </ol>
        <p class="route-note"><?= $locale === 'de' ? 'Kein Passwort. Ein Link, eine Verwendung, eine kurze Laufzeit.' : 'No password. One link, one use, one short lifetime.' ?></p>
    </aside>
    <section class="content-panel">
        <?= $content ?>
    </section>
</main>
</body>
</html>
