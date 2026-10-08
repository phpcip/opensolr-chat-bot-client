<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Http;

final class EventStream
{
    private bool $open = false;
    private bool $ended = false;

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        ignore_user_abort(false);
        while (ob_get_level() > 0 && ob_end_clean()) {
        }
        if (!headers_sent()) {
            if (function_exists('ini_set')) {
                ini_set('zlib.output_compression', '0');
            }
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
            header('X-Content-Type-Options: nosniff');
        }
        ob_implicit_flush(true);
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function send(array $event, bool $final = false): bool
    {
        if ($this->ended) {
            return false;
        }
        $this->open();
        $this->ended = $final;
        echo 'data: ' . json_encode($event, Response::JSON_FLAGS) . "\n\n";
        flush();
        return !$this->aborted();
    }

    public function progress(string $text): bool
    {
        return $this->send(['type' => 'progress', 'text' => $text]);
    }

    public function text(string $text): bool
    {
        return $text === '' ? !$this->aborted() : $this->send(['type' => 'text', 'text' => $text]);
    }

    /**
     * @param array{turn?: string, rate?: bool} $extra the token of the recorded answer, and whether it can be rated
     */
    public function done(array $extra = []): void
    {
        $this->send(['type' => 'done'] + $extra, true);
    }

    /**
     * Ends the response for the visitor (PHP-FPM), so work done after the answer does not keep the browser waiting.
     */
    public function finish(): void
    {
        if ($this->ended && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public function error(string $message): void
    {
        $this->send(['type' => 'error', 'message' => $message], true);
    }

    public function captcha(): void
    {
        $this->send(['type' => 'captcha'], true);
    }

    public function limit(string $message): void
    {
        $this->send(['type' => 'limit', 'message' => $message], true);
    }
}
