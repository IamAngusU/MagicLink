<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Exception\BadRequest;
use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\PayloadTooLarge;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\Http\Request;
use IamAngusU\MagicLink\Http\Response;
use IamAngusU\MagicLink\Mail\OutboxWorker;
use Throwable;

final class App
{
    private bool $deliveryQueued = false;

    public function __construct(
        private Config $config,
        private MagicLinkService $service,
        private StateCatalog $states,
        private OutboxWorker $outbox,
        private MaintenanceService $maintenance,
        private Tuning $tuning,
    ) {}

    public function run(?Request $request = null): never
    {
        $request ??= new Request(
            $this->config->int('HTTP_MAX_BODY_BYTES', 16384),
            $this->config->list('TRUSTED_PROXIES'),
        );
        try {
            $response = $this->route($request);
        } catch (PayloadTooLarge $error) {
            $response = $this->isApi($request)
                ? $this->apiProblem($request, 'request.too_large', $error->getMessage(), 413)
                : $this->errorPage($error->getMessage(), 413);
        } catch (BadRequest $error) {
            $response = $this->isApi($request)
                ? $this->apiProblem($request, 'request.invalid', $error->getMessage(), 400)
                : $this->errorPage($error->getMessage(), 400);
        } catch (Throwable $error) {
            $message = $this->config->string('APP_ENV') === 'local' ? $error->getMessage() : $this->states->message(MagicLinkState::Failed->value);
            $response = $this->isApi($request)
                ? $this->apiProblem($request, 'server.error', $message, 500)
                : $this->errorPage($message, 500);
        }

        $response->withHeaders($this->securityHeaders($request))->send(function (): void {
            if ($this->config->bool('MAIL_AUTO_DISPATCH', true) && ($this->deliveryQueued || random_int(1, 20) === 1)) {
                $this->outbox->run(1);
            }
            if (random_int(1, 100) === 1) {
                $this->maintenance->runIfDue($this->tuning->maintenanceBatch());
            }
        });
    }

    private function route(Request $request): Response
    {
        $path = $this->relativePath($request->path());
        if ($request->method() === 'OPTIONS' && str_starts_with($path, '/api/')) {
            return $this->preflight($request);
        }
        $origin = $request->header('Origin');
        if (($path === '/auth/state' || $path === '/auth/exchange' || str_starts_with($path, '/api/')) && $origin !== '' && !$this->originAllowed($origin)) {
            return $this->apiProblem($request, 'cors.denied', 'Origin is not allowed.', 403);
        }
        $key = $request->method() . ' ' . rtrim($path, '/');
        if ($key === 'GET ') {
            $key = 'GET /';
        }

        return match ($key) {
            'GET /' => $this->home(),
            'POST /auth/request' => $this->requestLink($request),
            'GET /auth/wait' => $this->waitPage($request),
            'GET /auth/state' => $this->apiState($request),
            'GET /auth/check' => $this->checkPage($request),
            'POST /auth/exchange' => $this->apiExchange($request),
            'POST /auth/logout' => $this->logout($request),
            'GET /api/v1/config' => $this->apiConfig($request),
            'POST /api/v1/requests' => $this->apiRequest($request),
            'GET /api/v1/state' => $this->apiState($request),
            'POST /api/v1/states' => $this->apiStates($request),
            'POST /api/v1/exchange' => $this->apiExchange($request),
            'GET /api/v1/session' => $this->apiSession($request),
            'POST /api/v1/logout' => $this->apiLogout($request),
            'GET /health' => Response::json(['ok' => true, 'service' => 'magic-link', 'version' => 'v1']),
            default => $this->isApi($request)
                ? $this->apiProblem($request, 'route.not_found', 'Endpoint not found.', 404)
                : $this->render('error', [
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
            return $this->errorPage($this->config->locale() === 'de' ? 'Lade die Seite neu und fordere den Link noch einmal an.' : 'Reload the page and request the link again.', 419);
        }
        try {
            $result = $this->service->request((string) ($input['email'] ?? ''), Session::binding(), $request->ip());
            $this->rememberRequest($result);
            return Response::redirect($this->config->path('/auth/wait') . '?id=' . rawurlencode($result['selector']));
        } catch (RateLimited $error) {
            return $this->errorPage($this->states->message(MagicLinkState::RateLimited->value), 429);
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
        if (!Session::ownsRequest($selector)) {
            return $this->invalidPage();
        }
        try {
            $state = $this->service->state($selector, Session::binding());
            return $this->render('waiting', [
                'title' => $this->config->locale() === 'de' ? 'Postfach prüfen' : 'Check your inbox',
                'selector' => $selector,
                'maskedEmail' => (string) ($_SESSION['last_magic_masked_email'] ?? ''),
                'stateUrl' => $this->config->path('/api/v1/state') . '?id=' . rawurlencode($selector),
                'homeUrl' => $this->config->path('/'),
                'stateMessage' => $this->states->message($state['state']),
                'stateCode' => $state['state'],
                'expiresAt' => $state['expires_at'],
                'pollAfterMs' => $this->tuning->pollAfterMs(),
                'stage' => $state['verified'] ? 'verified' : 'waiting',
            ]);
        } catch (InvalidLink) {
            return $this->invalidPage();
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
            'endpoint' => $this->config->path('/api/v1/exchange'),
            'stage' => 'waiting',
        ]);
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

    private function apiConfig(Request $request): Response
    {
        $states = [];
        foreach (MagicLinkState::cases() as $state) {
            $states[$state->value] = ['message' => $this->states->message($state->value), 'terminal' => in_array($state, [MagicLinkState::Verified, MagicLinkState::Expired, MagicLinkState::Replayed, MagicLinkState::Denied, MagicLinkState::Failed], true)];
        }
        return $this->apiSuccess($request, 'config.ready', [
            'app' => ['name' => $this->config->string('APP_NAME'), 'locale' => $this->config->locale()],
            'csrf_token' => Session::csrf(),
            'endpoints' => [
                'request' => $this->config->url('/api/v1/requests'),
                'state' => $this->config->url('/api/v1/state'),
                'states' => $this->config->url('/api/v1/states'),
                'exchange' => $this->config->url('/api/v1/exchange'),
                'session' => $this->config->url('/api/v1/session'),
                'logout' => $this->config->url('/api/v1/logout'),
            ],
            'features' => ['batch_states' => true, 'queued_mail' => true, 'cross_device_session_grant' => false],
            'defaults' => ['ttl_seconds' => $this->config->int('MAGICLINK_TTL_SECONDS', 900), 'poll_after_ms' => $this->tuning->pollAfterMs()],
            'limits' => ['state_batch_max' => $this->tuning->stateBatchMax(), 'body_bytes' => $this->config->int('HTTP_MAX_BODY_BYTES', 16384)],
            'states' => $states,
        ]);
    }

    private function apiRequest(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, $request->header('X-CSRF-Token') ?: (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->apiProblem($request, 'request.csrf_failed', 'Reload the client configuration and try again.', 419);
        }
        try {
            $result = $this->service->request((string) ($input['email'] ?? ''), Session::binding(), $request->ip());
            $this->rememberRequest($result);
            return $this->apiSuccess($request, 'request.accepted', [
                'id' => $result['selector'],
                'state' => $result['state'],
                'message' => $this->states->message($result['state']),
                'masked_email' => $result['masked_email'],
                'expires_at' => $result['expires_at'],
            ], 202, ['poll_after_ms' => $this->tuning->pollAfterMs()]);
        } catch (RateLimited $error) {
            return $this->apiProblem($request, 'request.rate_limited', $this->states->message(MagicLinkState::RateLimited->value), 429, $error->retryAfter);
        } catch (\InvalidArgumentException $error) {
            return $this->apiProblem($request, 'request.invalid_email', $error->getMessage(), 422);
        }
    }

    private function apiState(Request $request): Response
    {
        $selector = $request->query('id');
        if (!Session::ownsRequest($selector)) {
            return $this->apiProblem($request, 'state.not_found', $this->states->message(MagicLinkState::Failed->value), 404);
        }
        $retryMs = Session::claimStatePoll(max(500, (int) floor($this->tuning->pollAfterMs() * 0.6)));
        if ($retryMs > 0) {
            return $this->apiProblem($request, 'state.too_soon', $this->states->message(MagicLinkState::RateLimited->value), 429, max(1, (int) ceil($retryMs / 1000)), ['poll_after_ms' => $this->tuning->pollAfterMs()]);
        }
        try {
            $state = $this->service->state($selector, Session::binding());
            return $this->apiSuccess($request, 'magic_link.' . $state['state'], $this->presentState($selector, $state), 200, ['poll_after_ms' => $state['terminal'] ? null : $this->tuning->pollAfterMs()]);
        } catch (InvalidLink) {
            return $this->apiProblem($request, 'state.not_found', $this->states->message(MagicLinkState::Failed->value), 404);
        }
    }

    private function apiStates(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, $request->header('X-CSRF-Token') ?: (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->apiProblem($request, 'request.csrf_failed', 'Reload the client configuration and try again.', 419);
        }
        $ids = $input['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > $this->tuning->stateBatchMax()) {
            return $this->apiProblem($request, 'batch.invalid', 'ids must be a non-empty list within the advertised batch limit.', 422);
        }
        $selectors = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                return $this->apiProblem($request, 'batch.invalid', 'Every id must be a string.', 422);
            }
            $selectors[] = $id;
        }
        $selectors = array_values(array_unique($selectors));
        if (!Session::ownsRequests($selectors)) {
            return $this->apiProblem($request, 'state.not_found', $this->states->message(MagicLinkState::Failed->value), 404);
        }
        $pollAfter = $this->tuning->pollAfterMs(count($selectors));
        $retryMs = Session::claimStatePoll(max(500, (int) floor($pollAfter * 0.6)));
        if ($retryMs > 0) {
            return $this->apiProblem($request, 'state.too_soon', $this->states->message(MagicLinkState::RateLimited->value), 429, max(1, (int) ceil($retryMs / 1000)), ['poll_after_ms' => $pollAfter]);
        }
        try {
            $states = $this->service->states($selectors, Session::binding());
            $items = [];
            foreach ($selectors as $selector) {
                $items[] = $this->presentState($selector, $states[$selector]);
            }
            $terminal = array_reduce($items, static fn (bool $carry, array $item): bool => $carry && (bool) $item['terminal'], true);
            return $this->apiSuccess($request, 'states.ready', ['items' => $items], 200, ['poll_after_ms' => $terminal ? null : $pollAfter]);
        } catch (InvalidLink) {
            return $this->apiProblem($request, 'state.not_found', $this->states->message(MagicLinkState::Failed->value), 404);
        }
    }

    private function apiExchange(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, $request->header('X-CSRF-Token') ?: (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->apiProblem($request, 'request.csrf_failed', 'Reload the confirmation page and try again.', 419);
        }
        try {
            $result = $this->service->exchange((string) ($input['id'] ?? ''), (string) ($input['token'] ?? ''), $request->ip());
            Session::authenticate($result['email']);
            return $this->apiSuccess($request, 'magic_link.verified', [
                'state' => $result['state'],
                'message' => $this->states->message($result['state']),
                'redirect' => $this->successUrl(),
            ]);
        } catch (RateLimited $error) {
            return $this->apiProblem($request, 'exchange.rate_limited', $this->states->message(MagicLinkState::RateLimited->value), 429, $error->retryAfter);
        } catch (InvalidLink) {
            return $this->apiProblem($request, 'exchange.invalid', $this->states->message(MagicLinkState::Failed->value), 410);
        }
    }

    private function apiSession(Request $request): Response
    {
        $email = Session::email();
        return $this->apiSuccess($request, $email === null ? 'session.anonymous' : 'session.authenticated', [
            'authenticated' => $email !== null,
            'email' => $email,
        ]);
    }

    private function apiLogout(Request $request): Response
    {
        $input = $request->input();
        try {
            $this->guardPost($request, $request->header('X-CSRF-Token') ?: (string) ($input['_csrf'] ?? ''));
        } catch (\RuntimeException) {
            return $this->apiProblem($request, 'request.csrf_failed', 'Reload the client configuration and try again.', 419);
        }
        Session::logout();
        return $this->apiSuccess($request, 'session.logged_out', ['authenticated' => false]);
    }

    private function preflight(Request $request): Response
    {
        $origin = rtrim($request->header('Origin'), '/');
        if ($origin === '' || !$this->originAllowed($origin)) {
            return $this->apiProblem($request, 'cors.denied', 'Origin is not allowed.', 403);
        }
        return Response::noContent($this->corsHeaders($request));
    }

    /** @param array{selector:string,masked_email:string} $result */
    private function rememberRequest(array $result): void
    {
        Session::rememberRequest($result['selector']);
        $_SESSION['last_magic_selector'] = $result['selector'];
        $_SESSION['last_magic_masked_email'] = $result['masked_email'];
        $this->deliveryQueued = true;
    }

    /** @param array{state:string,expires_at:int,verified:bool,terminal:bool} $state */
    private function presentState(string $selector, array $state): array
    {
        return [
            'id' => $selector,
            'state' => $state['state'],
            'message' => $this->states->message($state['state']),
            'verified' => $state['verified'],
            'terminal' => $state['terminal'],
            'expires_at' => $state['expires_at'],
            'authenticated' => Session::email() !== null,
        ];
    }

    private function guardPost(Request $request, string $token): void
    {
        if ($token === '' || !hash_equals(Session::csrf(), $token)) {
            throw new \RuntimeException('Invalid CSRF token.');
        }
        $origin = rtrim($request->header('Origin'), '/');
        if ($origin !== '' && !$this->originAllowed($origin)) {
            throw new \RuntimeException('Origin check failed.');
        }
        $referer = $request->header('Referer');
        if ($origin === '' && $referer !== '') {
            $refererOrigin = $this->origin((string) preg_replace('/[#?].*$/', '', $referer));
            if ($refererOrigin === '' || !$this->originAllowed($refererOrigin)) {
                throw new \RuntimeException('Referer check failed.');
            }
        }
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $meta */
    private function apiSuccess(Request $request, string $code, array $data, int $status = 200, array $meta = []): Response
    {
        return Response::json([
            'ok' => true,
            'code' => $code,
            'data' => $data,
            'error' => null,
            'meta' => $this->apiMeta($request, $meta),
        ], $status, ['X-Request-ID' => $request->requestId()]);
    }

    /** @param array<string,mixed> $meta */
    private function apiProblem(Request $request, string $code, string $message, int $status, ?int $retryAfter = null, array $meta = []): Response
    {
        $headers = ['X-Request-ID' => $request->requestId()];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }
        return Response::json([
            'ok' => false,
            'code' => $code,
            'data' => null,
            'error' => ['message' => $message, 'retry_after' => $retryAfter],
            'meta' => $this->apiMeta($request, $meta),
        ], $status, $headers);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function apiMeta(Request $request, array $extra = []): array
    {
        return $extra + [
            'api_version' => 'v1',
            'request_id' => $request->requestId(),
            'server_time' => gmdate(DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data, int $status = 200): Response
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $appName = $this->config->string('APP_NAME');
        $locale = $this->config->locale();
        $assetBase = $this->config->path('/assets');
        $assetVersion = trim((string) @file_get_contents($this->config->root() . '/VERSION')) ?: 'dev';
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

    private function errorPage(string $message, int $status): Response
    {
        return $this->render('error', [
            'title' => $this->config->locale() === 'de' ? 'Anmeldung nicht möglich' : 'Unable to sign in',
            'message' => $message,
            'stage' => 'failed',
        ], $status);
    }

    /** @return array<string,string> */
    private function securityHeaders(Request $request): array
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cache-Control' => 'no-store, private',
        ];
        if ($this->config->string('APP_ENV') === 'production') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers + $this->corsHeaders($request);
    }

    /** @return array<string,string> */
    private function corsHeaders(Request $request): array
    {
        $origin = rtrim($request->header('Origin'), '/');
        if ($origin === '' || !$this->originAllowed($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, X-Request-ID',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }

    private function originAllowed(string $origin): bool
    {
        $canonical = $this->origin($origin);
        return $canonical !== '' && in_array($canonical, $this->allowedOrigins(), true);
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        return array_values(array_unique(array_filter([
            $this->origin($this->config->baseUrl()),
            ...array_map(fn (string $origin): string => $this->origin($origin), $this->config->list('API_ALLOWED_ORIGINS')),
        ])));
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function relativePath(string $path): string
    {
        $base = $this->config->basePath();
        if ($base === '') {
            return $path;
        }
        if ($path === $base) {
            return '/';
        }
        if (!str_starts_with($path, $base . '/')) {
            return '/__outside_base_path__';
        }
        return substr($path, strlen($base)) ?: '/';
    }

    private function successUrl(): string
    {
        return $this->config->string('AUTH_SUCCESS_URL') ?: $this->config->path('/');
    }

    private function isApi(Request $request): bool
    {
        return str_starts_with($this->relativePath($request->path()), '/api/') || $this->relativePath($request->path()) === '/auth/state' || $this->relativePath($request->path()) === '/auth/exchange';
    }
}
