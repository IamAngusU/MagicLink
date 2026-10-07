<div class="content-block">
    <p class="context"><?= $locale === 'de' ? 'Sitzung aktiv' : 'Session active' ?></p>
    <h1><?= $locale === 'de' ? 'Du bist sicher angemeldet.' : 'You are signed in securely.' ?></h1>
    <p class="lede"><?= $locale === 'de' ? 'Diese Beispielseite ist der Übergabepunkt zu deiner eigenen Anwendung.' : 'This example page is the handoff point to your own application.' ?></p>
    <div class="identity-block">
        <span><?= $locale === 'de' ? 'Identität' : 'Identity' ?></span>
        <strong><?= $e($email) ?></strong>
    </div>
    <form action="<?= $e($this->config->path('/auth/logout')) ?>" method="post">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="secondary" type="submit"><?= $locale === 'de' ? 'Sitzung beenden' : 'End session' ?></button>
    </form>
</div>
