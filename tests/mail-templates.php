<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Mail\BatchMailer;
use IamAngusU\MagicLink\Mail\ContextualMailer;
use IamAngusU\MagicLink\Mail\DeliveryContext;
use IamAngusU\MagicLink\Mail\MagicLinkMessage;
use IamAngusU\MagicLink\Mail\Mailer;
use IamAngusU\MagicLink\Mail\MailTemplateRenderer;

$root = dirname(__DIR__);
require $root . '/autoload.php';

final class TemplateCaptureMailer implements Mailer
{
    /** @var array{to:string,subject:string,html:string,plain:string}|null */
    public ?array $message = null;

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        $this->message = compact('to', 'subject', 'html', 'plain');
    }
}

final class TemplateContextMailer implements ContextualMailer, BatchMailer
{
    public ?DeliveryContext $context = null;
    public bool $finished = false;

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        throw new RuntimeException('Context-aware delivery unexpectedly used the legacy path.');
    }

    public function sendWithContext(string $to, string $subject, string $html, string $plain, DeliveryContext $context): void
    {
        $this->context = $context;
    }

    public function finishBatch(): void
    {
        $this->finished = true;
    }
}

function templateExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function templateExpectFailure(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        templateExpect(str_contains($error->getMessage(), $contains), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure containing: ' . $contains);
}

/** @param array<string,string> $overrides */
function templateConfig(string $root, array $overrides = []): Config
{
    return Config::fromArray($root, $overrides + [
        'APP_ENV' => 'test',
        'APP_URL' => 'http://127.0.0.1:8080',
        'APP_NAME' => 'Acme <HQ>',
        'APP_LOCALE' => 'en',
        'MAIL_TRANSPORT' => 'log',
        'MAIL_FROM_ADDRESS' => 'no-reply@example.com',
        'MAGICLINK_ALLOWED_EMAILS' => 'owner@example.com',
    ]);
}

/** @param array{subject:string,plain:string,html:string} $templates */
function writeTemplatePack(string $root, array $templates): void
{
    $directory = $root . '/templates/en';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($directory . '/subject.txt', $templates['subject']);
    file_put_contents($directory . '/plain.txt', $templates['plain']);
    file_put_contents($directory . '/html.html', $templates['html']);
    clearstatcache();
}

function removeTemplateTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

$temporary = sys_get_temp_dir() . '/magic-link-mail-template-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);

try {
    $url = 'http://127.0.0.1:8080/verify?id=ml_preview#token=a&next="unsafe"';
    $builtIn = (new MailTemplateRenderer(templateConfig($temporary)))->render('owner@example.com', $url, time() + 300);
    templateExpect($builtIn['subject'] === 'Your secure sign-in link', 'Built-in English subject changed unexpectedly.');
    templateExpect(str_contains($builtIn['plain'], 'Acme <HQ>'), 'Plain text must preserve printable application text.');
    templateExpect(str_contains($builtIn['html'], 'Acme &lt;HQ&gt;'), 'HTML application text must be escaped.');
    templateExpect(str_contains($builtIn['html'], '&amp;next=&quot;unsafe&quot;'), 'HTML link attributes must be escaped.');

    $valid = [
        'subject' => '{{app_name}} sign-in ({{expires_minutes}} min)',
        'plain' => "Use {{magic_link}} for {{recipient}}.\n",
        'html' => '<a href="{{magic_link}}">{{app_name}} / {{recipient}}</a>',
    ];
    writeTemplatePack($temporary, $valid);
    $customConfig = templateConfig($temporary, ['MAIL_TEMPLATE_DIR' => 'templates']);
    $custom = (new MailTemplateRenderer($customConfig))->render('owner@example.com', $url, time() + 300);
    templateExpect(str_starts_with($custom['subject'], 'Acme <HQ> sign-in'), 'Custom subject must render allowed variables.');
    templateExpect(str_contains($custom['html'], 'href="http://127.0.0.1:8080/verify?id=ml_preview#token=a&amp;next=&quot;unsafe&quot;"'), 'Custom HTML URL must be context escaped.');
    $capture = new TemplateCaptureMailer();
    (new MagicLinkMessage($customConfig, $capture))->send('owner@example.com', $url, time() + 300);
    templateExpect($capture->message !== null && $capture->message['subject'] === $custom['subject'], 'MagicLinkMessage must deliver rendered templates.');
    $contextMailer = new TemplateContextMailer();
    $contextMessage = new MagicLinkMessage($customConfig, $contextMailer);
    $deliveryContext = new DeliveryContext('preview@magiclink.local');
    $contextMessage->send('owner@example.com', $url, time() + 300, $deliveryContext);
    $contextMessage->finishBatch();
    templateExpect($contextMailer->context === $deliveryContext, 'MagicLinkMessage must retain stable delivery context.');
    templateExpect($contextMailer->finished, 'MagicLinkMessage must close batch-capable mailers.');

    writeTemplatePack($temporary, array_replace($valid, ['plain' => '{{unknown}} {{magic_link}}']));
    templateExpectFailure(fn () => (new MailTemplateRenderer($customConfig))->render('owner@example.com', $url, time() + 300), 'unsupported placeholder');

    writeTemplatePack($temporary, array_replace($valid, ['plain' => 'No link here.']));
    templateExpectFailure(fn () => (new MailTemplateRenderer($customConfig))->render('owner@example.com', $url, time() + 300), 'must contain {{magic_link}}');

    writeTemplatePack($temporary, array_replace($valid, ['subject' => "Injected\r\nBcc: victim@example.com"]));
    templateExpectFailure(fn () => (new MailTemplateRenderer($customConfig))->render('owner@example.com', $url, time() + 300), 'one non-empty line');

    writeTemplatePack($temporary, array_replace($valid, ['plain' => str_repeat('x', 131073) . '{{magic_link}}']));
    templateExpectFailure(fn () => (new MailTemplateRenderer($customConfig))->render('owner@example.com', $url, time() + 300), 'size limit');

    $outside = dirname($temporary) . '/outside-mail-templates-' . bin2hex(random_bytes(3));
    mkdir($outside, 0700, true);
    templateExpectFailure(
        fn () => (new MailTemplateRenderer(templateConfig($temporary, ['MAIL_TEMPLATE_DIR' => '../' . basename($outside)])))->render('owner@example.com', $url, time() + 300),
        'without dot segments',
    );
    rmdir($outside);

    templateExpectFailure(
        fn () => (new MailTemplateRenderer(templateConfig($temporary, ['MAIL_TEMPLATE_DIR' => 'https://example.com/templates'])))->render('owner@example.com', $url, time() + 300),
        'relative local directory',
    );

    mkdir($temporary . '/public/templates/en', 0700, true);
    foreach (['subject.txt', 'plain.txt', 'html.html'] as $file) {
        copy($temporary . '/templates/en/' . $file, $temporary . '/public/templates/en/' . $file);
    }
    templateExpectFailure(
        fn () => (new MailTemplateRenderer(templateConfig($temporary, ['MAIL_TEMPLATE_DIR' => 'public/templates'])))->render('owner@example.com', $url, time() + 300),
        'public document root',
    );

    echo "Mail template tests passed.\n";
} finally {
    removeTemplateTree($temporary);
}
