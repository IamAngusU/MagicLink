<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\Http\Request;
use IamAngusU\MagicLink\Http\Response;
use Throwable;

final class App
{
    public function __construct(
        private Config $config,
        private MagicLinkService $service,
        private Crypto $crypto,
        private StateCatalog $states,
    ) {}

    public function run(?Request $request = null): never
    {
        $request ??= new Request();
        try {
            $response = $this->route($request);
        } catch (Throwable $error) {
            $response = $this->render('error', [
                'title' => $this->config->locale() === 'de' ? 'Anmeldung nicht möglich' : 'Unable to sign in',
                'message' => $this->config->string('APP_ENV') === 'local' ? $error->getMessage() : $this->states->message(MagicLinkState::Failed->value),
                'stage' => 'failed',
            ], 500);
        }
        $response->withHeaders($this->securityHeaders())->send();
    }

    private function route(Request $request): Response
    {
        $path = $request->path();
        $base = $this->config->basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $key = $request->method() . ' ' . rtrim($path, '/');
        if ($key === 'GET ') {
            $key = 'GET /';
        }

        return match ($key) {
            'GET /' => $this->home(),
            'POST /auth/request' => $this->requestLink($request),
            'GET /auth/wait' => $this->waitPage($request),
            'GET /auth/state' => $this->state($request),
            'GET /auth/check' => $this->checkPage($request),
            'POST /auth/exchange' => $this->exchange($request),
            'POST /auth/logout' => $this->logout($request),
            'GET /health' => Response::json(['ok' => true, 'service' => 'magic-link']),
            default => $this->render('error', [
                'title' => '404',
                'message' => $this->config->locale() === 'de' ? 'Diese Seite gibt es nicht.' : 'This page does not exist.',
                'stage' => 'failed',
            ], 404),
        };
    }

    private function home(): Response
    {
        $email = Session::email();
        if ($email !== null) {
            return $this->render('dashboard', [
                'title' => $this->config->locale() === 'de' ? 'Sitzung aktiv' : 'Session active',
                'email' => $email,
                'csrf' => Session::csrf(),
                'stage' => 'verified',
            ]);
        }
        return $this->render('login', [
            'title' => $this->config->locale() === 'de' ? 'Sicher anmelden' : 'Sign in securely',
            'csrf' => Session::csrf(),
            'stage' => 'requested',
        ]);
    }

    private function requestLink(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->render('error', [
                'title' => $this->config->locale() === 'de' ? 'Sitzung abgelaufen' : 'Session expired',
                'message' => $this->config->locale() === 'de' ? 'Lade die Seite neu und fordere den Link noch einmal an.' : 'Reload the page and request the link again.',
                'stage' => 'failed',
            ], 419);
        }
        try {
            $result = $this->service->request((string) ($input['email'] ?? ''), Session::binding(), $request->ip());
            $_SESSION['last_magic_selector'] = $result['selector'];
            $_SESSION['last_magic_masked_email'] = $result['masked_email'];
            return Response::redirect($this->config->path('/auth/wait') . '?id=' . rawurlencode($result['selector']));
        } catch (RateLimited $error) {
            return $this->render('error', [
                'title' => $this->config->locale() === 'de' ? 'Kurz warten' : 'Please wait',
                'message' => $this->states->message(MagicLinkState::RateLimited->value),
                'stage' => 'failed',
            ], 429);
        } catch (\InvalidArgumentException $error) {
            return $this->render('login', [
                'title' => $this->config->locale() === 'de' ? 'Sicher anmelden' : 'Sign in securely',
                'csrf' => Session::csrf(),
                'error' => $error->getMessage(),
                'stage' => 'requested',
            ], 422);
        }
    }

    private function waitPage(Request $request): Response
    {
        $selector = $request->query('id');
        if (!hash_equals((string) ($_SESSION['last_magic_selector'] ?? ''), $selector)) {
            return $this->invalidPage();
        }
        try {
            $state = $this->service->state($selector, Session::binding());
            if ($state['verified'] && is_string($state['email'])) {
                Session::authenticate($state['email']);
                return Response::redirect($this->config->path('/'));
            }
            return $this->render('waiting', [
                'title' => $this->config->locale() === 'de' ? 'Postfach prüfen' : 'Check your inbox',
                'selector' => $selector,
                'maskedEmail' => (string) ($_SESSION['last_magic_masked_email'] ?? ''),
                'stateUrl' => $this->config->path('/auth/state') . '?id=' . rawurlencode($selector),
                'homeUrl' => $this->config->path('/'),
                'stateMessage' => $this->states->message($state['state']),
                'expiresAt' => $state['expires_at'],
                'stage' => 'waiting',
            ]);
        } catch (InvalidLink) {
            return $this->invalidPage();
        }
    }

    private function state(Request $request): Response
    {
        try {
            $state = $this->service->state($request->query('id'), Session::binding());
            if ($state['verified'] && is_string($state['email'])) {
                Session::authenticate($state['email']);
            }
            return Response::json(['ok' => true, 'state' => $state['state'], 'message' => $this->states->message($state['state']), 'verified' => $state['verified'], 'redirect' => $state['verified'] ? $this->config->path('/') : null]);
        } catch (InvalidLink) {
            return Response::json(['ok' => false, 'state' => MagicLinkState::Failed->value, 'message' => $this->states->message(MagicLinkState::Failed->value)], 404);
        }
    }

    private function checkPage(Request $request): Response
    {
        $selector = $request->query('id');
        if (!preg_match('/^ml_[a-f0-9]{24}$/D', $selector)) {
            return $this->invalidPage();
        }
        return $this->render('exchange', [
            'title' => $this->config->locale() === 'de' ? 'Link bestätigen' : 'Confirm link',
            'selector' => $selector,
            'csrf' => Session::csrf(),
            'endpoint' => $this->config->path('/auth/exchange'),
            'stage' => 'waiting',
        ]);
    }

    private function exchange(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, $request->header('X-CSRF-Token') ?: (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return Response::json(['ok' => false, 'state' => MagicLinkState::Failed->value, 'message' => $this->states->message(MagicLinkState::Failed->value)], 419);
        }
        try {
            $result = $this->service->exchange((string) ($input['id'] ?? ''), (string) ($input['token'] ?? ''), $request->ip());
            Session::authenticate($result['email']);
            return Response::json(['ok' => true, 'state' => $result['state'], 'message' => $this->states->message($result['state']), 'redirect' => $this->config->path('/')]);
        } catch (InvalidLink $error) {
            return Response::json(['ok' => false, 'state' => MagicLinkState::Failed->value, 'message' => $this->states->message(MagicLinkState::Failed->value)], 410);
        }
    }

    private function logout(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->invalidPage();
        }
        Session::logout();
        return Response::redirect($this->config->path('/'));
    }

    private function guardPost(Request $request, string $token): void
    {
        if ($token === '' || !hash_equals(Session::csrf(), $token)) {
            throw new \RuntimeException('Invalid CSRF token.');
        }
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $expected = parse_url($this->config->baseUrl());
        $expectedOrigin = ($expected['scheme'] ?? '') . '://' . ($expected['host'] ?? '') . (isset($expected['port']) ? ':' . $expected['port'] : '');
        if ($origin !== '' && !hash_equals($expectedOrigin, rtrim($origin, '/'))) {
            throw new \RuntimeException('Origin check failed.');
        }
        if ($origin === '' && $referer !== '' && !str_starts_with($referer, $this->config->baseUrl() . '/')) {
            throw new \RuntimeException('Referer check failed.');
        }
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data, int $status = 200): Response
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $appName = $this->config->string('APP_NAME');
        $locale = $this->config->locale();
        $assetBase = $this->config->path('/assets');
        extract($data, EXTR_SKIP);
        ob_start();
        require $this->config->root() . '/templates/' . $view . '.php';
        $content = (string) ob_get_clean();
        ob_start();
        require $this->config->root() . '/templates/layout.php';
        return Response::html((string) ob_get_clean(), $status);
    }

    private function invalidPage(): Response
    {
        return $this->render('error', [
            'title' => $this->config->locale() === 'de' ? 'Link nicht verfügbar' : 'Link unavailable',
            'message' => $this->states->message(MagicLinkState::Failed->value),
            'stage' => 'failed',
        ], 410);
    }

    /** @return array<string,string> */
    private function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store, private',
        ];
    }
}
