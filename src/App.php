<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Admin\AdminController;
use Opensolr\ChatBot\Admin\AdminSession;
use Opensolr\ChatBot\Http\EventStream;
use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;

/**
 * The chat bot mounted on one URL path: config: data_dir, base_path, optional trusted_proxies (IPs or CIDRs whose
 * X-Forwarded-For and X-Forwarded-Proto are believed).
 */
final class App
{
    private const ADMIN_ROUTES = ['/admin', '/admin/gate', '/admin/login', '/admin/logout', '/admin/test'];

    private string $dataDir;
    private string $basePath;
    /** @var list<string> */
    private array $trustedProxies;
    private ?string $configError = null;
    private ?Store $store = null;
    private ?Settings $settings = null;

    /**
     * @param array{data_dir?: string, base_path?: string, trusted_proxies?: list<string>} $config
     */
    public function __construct(array $config)
    {
        $this->dataDir = rtrim((string) ($config['data_dir'] ?? ''), '/\\');
        $base = '/' . trim((string) ($config['base_path'] ?? ''), '/');
        $this->basePath = $base === '/' ? '' : $base;
        $this->trustedProxies = array_values(array_filter(array_map('strval', (array) ($config['trusted_proxies'] ?? [])), static fn (string $p): bool => $p !== ''));
        if ($this->dataDir === '') {
            $this->configError = 'The data_dir is required.';
        } elseif (!preg_match('#^(/[A-Za-z0-9._~-]+)*$#', $this->basePath)) {
            $this->configError = 'The base_path is not a valid URL path.';
        }
    }

    /**
     * Answers the current request; every failure ends in an answer, never in an exception.
     */
    public function run(): void
    {
        try {
            $this->handle();
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: ' . $e->getMessage());
            if (!headers_sent()) {
                Response::json(500, ['error' => 'server_error']);
            }
        }
    }

    /**
     * Answers one request and returns once the answer is sent.
     */
    public function handle(?Request $request = null): void
    {
        if ($this->configError !== null) {
            throw new \InvalidArgumentException($this->configError);
        }
        $request ??= Request::fromGlobals($this->basePath, $this->trustedProxies);
        $route = $request->route();
        $method = $request->method();
        $public = new PublicRoutes($request);
        $cookiePath = $this->basePath !== '' ? $this->basePath : '/';

        if ($route === '/widget.js' || $route === '/config' || $route === '/logo') {
            if ($method !== 'GET') {
                Response::json(405, ['error' => 'method_not_allowed'], ['Allow' => 'GET, HEAD']);
                return;
            }
            if ($route === '/logo') {
                (new Logo($this->store()))->serve($request);
            } else {
                $route === '/widget.js' ? $public->widget() : $public->config($this->settings(), new Logo($this->store()));
            }
            return;
        }
        if ($route === '/captcha') {
            if ($method !== 'POST') {
                Response::json(405, ['error' => 'method_not_allowed'], ['Allow' => 'POST']);
                return;
            }
            $public->captcha(new Captcha($this->settings(), $this->store(), $cookiePath));
            return;
        }
        if ($route === '/chat') {
            $out = new EventStream();
            $out->open();
            try {
                $chat = new ChatController($request, $this->store(), $this->settings(), new Captcha($this->settings(), $this->store(), $cookiePath));
            } catch (\Throwable $e) {
                error_log('Opensolr Chat Bot: ' . $e->getMessage());
                $out->error(Assistant::FAILED);
                return;
            }
            $chat->handle($out);
            return;
        }
        if ($route !== null && in_array($route, self::ADMIN_ROUTES, true)) {
            if ($method !== 'GET' && $method !== 'POST') {
                Response::json(405, ['error' => 'method_not_allowed'], ['Allow' => 'GET, POST']);
                return;
            }
            $session = new AdminSession($this->store(), $request, $cookiePath);
            (new AdminController($request, $this->store(), $this->settings(), $session))->handle($route);
            return;
        }
        Response::json(404, ['error' => 'not_found']);
    }

    private function store(): Store
    {
        return $this->store ??= new Store($this->dataDir);
    }

    private function settings(): Settings
    {
        return $this->settings ??= new Settings($this->store());
    }
}
