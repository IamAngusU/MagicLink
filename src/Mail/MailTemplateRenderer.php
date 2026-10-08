<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use RuntimeException;

final class MailTemplateRenderer
{
    private const SUBJECT_TEMPLATE_MAX_BYTES = 512;
    private const BODY_TEMPLATE_MAX_BYTES = 131072;
    private const RENDERED_SUBJECT_MAX_BYTES = 512;
    private const RENDERED_BODY_MAX_BYTES = 262144;

    /** @var list<string> */
    private const SUBJECT_PLACEHOLDERS = ['app_name', 'expires_minutes'];

    /** @var list<string> */
    private const BODY_PLACEHOLDERS = ['app_name', 'expires_minutes', 'magic_link', 'recipient'];

    /** @var array<string,array{subject:string,plain:string,html:string}> */
    private array $templateCache = [];

    public function __construct(private Config $config) {}

    /** @return array{subject:string,plain:string,html:string} */
    public function render(string $email, string $url, int $expiresAt, ?string $locale = null): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new RuntimeException('Mail template recipient is invalid.');
        }
        if (!str_starts_with($url, $this->config->baseUrl() . '/')) {
            throw new RuntimeException('Mail template URL does not belong to APP_URL.');
        }

        $locale ??= $this->config->locale();
        if (!in_array($locale, ['de', 'en'], true)) {
            throw new RuntimeException('Mail template locale must be de or en.');
        }

        $appName = $this->config->string('APP_NAME');
        if (preg_match('//u', $appName) !== 1) {
            throw new RuntimeException('APP_NAME must be valid UTF-8.');
        }

        $variables = [
            'app_name' => $appName,
            'expires_minutes' => (string) max(1, (int) ceil(($expiresAt - time()) / 60)),
            'magic_link' => $url,
            'recipient' => $email,
        ];
        $templates = $this->templates($locale);

        $subject = trim($this->renderTemplate('subject', $templates['subject'], $variables, false));
        if ($subject === '' || strlen($subject) > self::RENDERED_SUBJECT_MAX_BYTES || preg_match('/[\r\n\x00]/', $subject)) {
            throw new RuntimeException('Rendered mail subject must be one non-empty line of at most 512 bytes.');
        }

        return [
            'subject' => $subject,
            'plain' => $this->renderTemplate('plain', $templates['plain'], $variables, false),
            'html' => $this->renderTemplate('html', $templates['html'], $variables, true),
        ];
    }

    /** @return array{subject:string,plain:string,html:string} */
    private function templates(string $locale): array
    {
        $directory = $this->config->string('MAIL_TEMPLATE_DIR');
        $cacheKey = ($directory === '' ? 'built-in' : $directory) . '|' . $locale;
        if (isset($this->templateCache[$cacheKey])) {
            return $this->templateCache[$cacheKey];
        }
        if ($directory === '') {
            return $this->cacheTemplates($cacheKey, $this->builtInTemplates($locale));
        }

        $base = $this->resolveTemplateDirectory($directory);
        $localeDirectory = realpath($base . DIRECTORY_SEPARATOR . $locale);
        if ($localeDirectory === false || !is_dir($localeDirectory) || !$this->isWithin($localeDirectory, $base)) {
            throw new RuntimeException('MAIL_TEMPLATE_DIR must contain an ' . $locale . ' directory.');
        }

        return $this->cacheTemplates($cacheKey, [
            'subject' => $this->readTemplate($localeDirectory, 'subject.txt', self::SUBJECT_TEMPLATE_MAX_BYTES),
            'plain' => $this->readTemplate($localeDirectory, 'plain.txt', self::BODY_TEMPLATE_MAX_BYTES),
            'html' => $this->readTemplate($localeDirectory, 'html.html', self::BODY_TEMPLATE_MAX_BYTES),
        ]);
    }

    private function resolveTemplateDirectory(string $configured): string
    {
        if (strlen($configured) > 240 || str_contains($configured, "\0") || str_contains($configured, '://')
            || preg_match('~^(?:[A-Za-z]:)?[\\\\/]~', $configured)) {
            throw new RuntimeException('MAIL_TEMPLATE_DIR must be a relative local directory inside the application root.');
        }
        $segments = preg_split('~[\\\\/]+~', $configured) ?: [];
        if ($segments === [] || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException('MAIL_TEMPLATE_DIR must be a relative local directory without dot segments.');
        }

        $root = realpath($this->config->root());
        $candidate = realpath($this->config->root() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
        if ($root === false || $candidate === false || !is_dir($candidate) || $this->samePath($candidate, $root) || !$this->isWithin($candidate, $root)) {
            throw new RuntimeException('MAIL_TEMPLATE_DIR must resolve to an existing directory inside the application root.');
        }
        $public = realpath($root . DIRECTORY_SEPARATOR . 'public');
        if ($public !== false && ($this->samePath($candidate, $public) || $this->isWithin($candidate, $public))) {
            throw new RuntimeException('MAIL_TEMPLATE_DIR must not be inside the public document root.');
        }
        return $candidate;
    }

    private function readTemplate(string $directory, string $name, int $maximumBytes): string
    {
        $path = realpath($directory . DIRECTORY_SEPARATOR . $name);
        if ($path === false || !is_file($path) || !$this->isWithin($path, $directory)) {
            throw new RuntimeException('Mail template is missing or leaves MAIL_TEMPLATE_DIR: ' . $name);
        }
        $contents = file_get_contents($path, false, null, 0, $maximumBytes + 1);
        if ($contents === false) {
            throw new RuntimeException('Mail template could not be read: ' . $name);
        }
        if (strlen($contents) > $maximumBytes) {
            throw new RuntimeException('Mail template exceeds its size limit: ' . $name);
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('Mail template must be valid UTF-8 without null bytes: ' . $name);
        }
        return $contents;
    }

    /** @param array{subject:string,plain:string,html:string} $templates @return array{subject:string,plain:string,html:string} */
    private function cacheTemplates(string $cacheKey, array $templates): array
    {
        $this->validateTemplate('subject', $templates['subject']);
        $this->validateTemplate('plain', $templates['plain']);
        $this->validateTemplate('html', $templates['html']);
        return $this->templateCache[$cacheKey] = $templates;
    }

    private function validateTemplate(string $kind, string $template): void
    {
        preg_match_all('/{{([a-z][a-z0-9_]*)}}/', $template, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));
        $allowed = $kind === 'subject' ? self::SUBJECT_PLACEHOLDERS : self::BODY_PLACEHOLDERS;
        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $allowed, true)) {
                throw new RuntimeException('Mail template contains an unsupported placeholder: ' . $placeholder);
            }
        }
        $withoutPlaceholders = preg_replace('/{{([a-z][a-z0-9_]*)}}/', '', $template);
        if ($withoutPlaceholders === null || str_contains($withoutPlaceholders, '{{') || str_contains($withoutPlaceholders, '}}')) {
            throw new RuntimeException('Mail template contains invalid placeholder syntax.');
        }
        if ($kind !== 'subject' && !in_array('magic_link', $placeholders, true)) {
            throw new RuntimeException($kind . ' mail template must contain {{magic_link}}.');
        }
    }

    /** @param array<string,string> $variables */
    private function renderTemplate(string $kind, string $template, array $variables, bool $escapeHtml): string
    {
        $allowed = $kind === 'subject' ? self::SUBJECT_PLACEHOLDERS : self::BODY_PLACEHOLDERS;
        $replacements = [];
        foreach ($allowed as $placeholder) {
            $value = $variables[$placeholder];
            $replacements['{{' . $placeholder . '}}'] = $escapeHtml
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
                : $value;
        }
        $rendered = strtr($template, $replacements);
        if ($kind !== 'subject' && strlen($rendered) > self::RENDERED_BODY_MAX_BYTES) {
            throw new RuntimeException('Rendered ' . $kind . ' mail exceeds 256 KiB.');
        }
        return $rendered;
    }

    private function isWithin(string $path, string $directory): bool
    {
        $path = $this->normalizedPath($path);
        $directory = rtrim($this->normalizedPath($directory), '/');
        return str_starts_with($path, $directory . '/');
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim($this->normalizedPath($left), '/') === rtrim($this->normalizedPath($right), '/');
    }

    private function normalizedPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    /** @return array{subject:string,plain:string,html:string} */
    private function builtInTemplates(string $locale): array
    {
        if ($locale === 'de') {
            return [
                'subject' => 'Dein sicherer Anmeldelink',
                'plain' => <<<'PLAIN'
Öffne diesen Link, um dich bei {{app_name}} anzumelden:

{{magic_link}}

Der Link ist {{expires_minutes}} Minuten gültig und kann einmal verwendet werden.
Wenn du ihn nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.
PLAIN,
                'html' => <<<'HTML'
<!doctype html><html lang="de"><body style="margin:0;background:#eef4f3;color:#142024;font-family:Arial,sans-serif"><div style="max-width:560px;margin:0 auto;padding:48px 24px"><div style="background:#fff;border:1px solid #cbd8d6;padding:36px"><p style="margin:0 0 28px;font-size:13px;color:#43615f">{{app_name}}</p><h1 style="font-size:30px;line-height:1.15;margin:0 0 16px">Dein sicherer Anmeldelink</h1><p style="font-size:16px;line-height:1.6;margin:0 0 28px">Öffne den Link, um dich anzumelden. Er ist einmal verwendbar und läuft nach {{expires_minutes}} Minuten ab.</p><p style="margin:0"><a href="{{magic_link}}" style="display:inline-block;background:#087982;color:#fff;text-decoration:none;padding:14px 20px;border-radius:4px">Sicher anmelden</a></p><p style="font-size:12px;line-height:1.5;color:#607775;margin:28px 0 0;word-break:break-all"><a href="{{magic_link}}" style="color:#43615f">{{magic_link}}</a></p><p style="font-size:12px;line-height:1.5;color:#607775;margin:16px 0 0">{{recipient}}</p><p style="font-size:12px;line-height:1.5;color:#607775;margin:12px 0 0">Wenn du diesen Link nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.</p></div></div></body></html>
HTML,
            ];
        }

        return [
            'subject' => 'Your secure sign-in link',
            'plain' => <<<'PLAIN'
Open this link to sign in to {{app_name}}:

{{magic_link}}

The link is valid for {{expires_minutes}} minutes and can be used once.
If you did not request it, you can safely ignore this email.
PLAIN,
            'html' => <<<'HTML'
<!doctype html><html lang="en"><body style="margin:0;background:#eef4f3;color:#142024;font-family:Arial,sans-serif"><div style="max-width:560px;margin:0 auto;padding:48px 24px"><div style="background:#fff;border:1px solid #cbd8d6;padding:36px"><p style="margin:0 0 28px;font-size:13px;color:#43615f">{{app_name}}</p><h1 style="font-size:30px;line-height:1.15;margin:0 0 16px">Your secure sign-in link</h1><p style="font-size:16px;line-height:1.6;margin:0 0 28px">Open the link to sign in. It can be used once and expires after {{expires_minutes}} minutes.</p><p style="margin:0"><a href="{{magic_link}}" style="display:inline-block;background:#087982;color:#fff;text-decoration:none;padding:14px 20px;border-radius:4px">Sign in securely</a></p><p style="font-size:12px;line-height:1.5;color:#607775;margin:28px 0 0;word-break:break-all"><a href="{{magic_link}}" style="color:#43615f">{{magic_link}}</a></p><p style="font-size:12px;line-height:1.5;color:#607775;margin:16px 0 0">{{recipient}}</p><p style="font-size:12px;line-height:1.5;color:#607775;margin:12px 0 0">If you did not request this link, you can safely ignore this email.</p></div></div></body></html>
HTML,
        ];
    }
}
