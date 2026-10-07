<div class="content-block">
    <p class="context"><?= $locale === 'de' ? 'Zugang' : 'Access' ?></p>
    <h1><?= $locale === 'de' ? 'Öffne deine Sitzung aus dem Postfach.' : 'Open your session from your inbox.' ?></h1>
    <p class="lede"><?= $locale === 'de' ? 'Du erhältst einen kurz gültigen Link. Er funktioniert genau einmal und enthält kein Passwort.' : 'You will receive a short-lived link. It works exactly once and contains no password.' ?></p>

    <?php if (!empty($error)): ?>
        <p class="notice is-error" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>

    <form class="auth-form" action="<?= $e($this->config->path('/auth/request')) ?>" method="post">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label for="email"><?= $locale === 'de' ? 'E-Mail-Adresse' : 'Email address' ?></label>
        <div class="field-row">
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required maxlength="254" placeholder="you@example.com">
            <button type="submit"><?= $locale === 'de' ? 'Link senden' : 'Send link' ?></button>
        </div>
    </form>
    <p class="privacy-note"><?= $locale === 'de' ? 'Nicht freigeschaltete Adressen erhalten keine Auskunft darüber, wer Zugang hat.' : 'Unapproved addresses never reveal who has access.' ?></p>
</div>
