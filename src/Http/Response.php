<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Http;

final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG;

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): void
    {
        self::send($status, (string) json_encode($data, self::JSON_FLAGS), ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(int $status, string $html, array $headers = []): void
    {
        self::send($status, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, array $headers = []): void
    {
        self::send(303, '', ['Location' => $location, 'Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function send(int $status, string $body, array $headers, bool $withBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            foreach ($headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
        }
        if ($withBody) {
            echo $body;
        }
    }

    public static function cookie(string $name, string $value, int $expires, string $path, bool $secure, string $sameSite): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $path,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
}
