<div class="content-block">
    <p class="context"><?= $locale === 'de' ? 'Zustand' : 'State' ?></p>
    <h1><?= $e($title) ?></h1>
    <p class="lede"><?= $e($message) ?></p>
    <a class="primary-link" href="<?= $e($this->config->path('/')) ?>"><?= $locale === 'de' ? 'Neuen Link anfordern' : 'Request a new link' ?></a>
</div>
