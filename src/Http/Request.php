<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Http;

final class Request
{
    private ?string $body = null;
    private bool $bodyTooLarge = false;
    private ?string $clientIp = null;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $post
     * @param list<string> $trustedProxies
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly array $server,
        private readonly array $cookies,
        private readonly array $post,
        private readonly string $basePath,
        private readonly array $trustedProxies = [],
        private readonly array $files = [],
    ) {
    }

    /**
     * One uploaded file of a form field: {tmp_name, size}, or null when none was sent.
     *
     * @return array{tmp_name: string, size: int}|null
     */
    public function file(string $name): ?array
    {
        $f = $this->files[$name] ?? null;
        if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return ['tmp_name' => ($f['error'] ?? 1) === UPLOAD_ERR_OK ? $f['tmp_name'] : '', 'size' => (int) ($f['size'] ?? 0)];
    }

    /**
     * @param list<string> $trustedProxies
     */
    public static function fromGlobals(string $basePath, array $trustedProxies = []): self
    {
        return new self($_SERVER, $_COOKIE, $_POST, $basePath, $trustedProxies, $_FILES);
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        return $method === 'HEAD' ? 'GET' : $method;
    }

    public function isHead(): bool
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? '')) === 'HEAD';
    }

    public function path(): string
    {
        $path = parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * [prefix, route]: the URL prefix that reached this app and the route after it; null when the path is not ours.
     *
     * @return array{0: string, 1: string}|null
     */
    private function split(): ?array
    {
        $path = $this->path();
        $prefix = '';
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        if ($script !== '' && str_ends_with($script, '.php') && self::under($path, $script)) {
            $prefix = $script;
            $path = substr($path, strlen($script));
            $path = $path === '' ? '/' : $path;
            if (!self::under($script, $this->basePath)) {
                if (!self::under($path, $this->basePath)) {
                    return null;
                }
                $prefix .= $this->basePath;
                $path = substr($path, strlen($this->basePath));
            }
        } elseif (self::under($path, $this->basePath)) {
            $prefix = $this->basePath;
            $path = substr($path, strlen($this->basePath));
        } else {
            return null;
        }
        $route = '/' . trim($path, '/');
        return [$prefix, $route];
    }

    private static function under(string $path, string $prefix): bool
    {
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public function route(): ?string
    {
        return $this->split()[1] ?? null;
    }

    public function prefix(): string
    {
        return $this->split()[0] ?? $this->basePath;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        }
        $value = $this->server[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** One parameter of the query string, '' when absent. */
    public function query(string $name, int $max = 200): string
    {
        parse_str((string) ($this->server['QUERY_STRING'] ?? ''), $params);
        $value = $params[$name] ?? '';
        return is_string($value) ? substr($value, 0, $max) : '';
    }

    public function cookie(string $name): string
    {
        $value = $this->cookies[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    public function post(string $name, int $max = 100000): string
    {
        $value = $this->post[$name] ?? '';
        return is_string($value) ? substr($value, 0, $max) : '';
    }

    /**
     * The raw body, or null when it is larger than $max bytes.
     */
    public function body(int $max): ?string
    {
        if ($this->body === null && !$this->bodyTooLarge) {
            $raw = file_get_contents('php://input', false, null, 0, $max + 1);
            $raw = is_string($raw) ? $raw : '';
            if (strlen($raw) > $max) {
                $this->bodyTooLarge = true;
            } else {
                $this->body = $raw;
            }
        }
        return $this->bodyTooLarge ? null : $this->body;
    }

    public function isJson(): bool
    {
        return stripos($this->header('Content-Type'), 'application/json') === 0;
    }

    public function host(): string
    {
        return self::bareHost((string) ($this->server['HTTP_HOST'] ?? ($this->server['SERVER_NAME'] ?? '')));
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? '' : substr($host, 1, $end - 1);
        }
        $colon = strrpos($host, ':');
        return ($colon !== false && substr_count($host, ':') === 1) ? substr($host, 0, $colon) : $host;
    }

    /**
     * The Origin (else the Referer) of the request names this request's host.
     */
    public function sameOrigin(): bool
    {
        $origin = $this->header('Origin');
        if ($origin === '' || $origin === 'null') {
            $origin = $this->header('Referer');
        }
        if ($origin === '') {
            return false;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $mine = $this->host();
        return $mine !== '' && hash_equals($mine, self::bareHost(trim($host, '[]')));
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if (($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($this->server['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }
        return $this->fromTrustedProxy() && strtolower($this->header('X-Forwarded-Proto')) === 'https';
    }

    public function userAgent(): string
    {
        return substr($this->header('User-Agent'), 0, 512);
    }

    public function clientIp(): string
    {
        if ($this->clientIp !== null) {
            return $this->clientIp;
        }
        $remote = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));
        $ip = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
        if ($ip !== '' && $this->fromTrustedProxy()) {
            $hops = array_reverse(array_map('trim', explode(',', $this->header('X-Forwarded-For'))));
            foreach ($hops as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                $ip = $hop;
                if (!$this->isTrusted($hop)) {
                    break;
                }
            }
        }
        return $this->clientIp = $ip;
    }

    private function fromTrustedProxy(): bool
    {
        $remote = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));
        return $this->trustedProxies !== [] && filter_var($remote, FILTER_VALIDATE_IP) !== false && $this->isTrusted($remote);
    }

    private function isTrusted(string $ip): bool
    {
        $address = inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->trustedProxies as $entry) {
            [$network, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
            $net = filter_var((string) $network, FILTER_VALIDATE_IP) !== false ? inet_pton((string) $network) : false;
            if ($net === false || strlen($net) !== strlen($address)) {
                continue;
            }
            $max = strlen($net) * 8;
            $bits = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($address, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($address[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
