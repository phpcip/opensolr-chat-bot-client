<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;

/**
 * The widget script, its public configuration and the captcha check.
 */
final class PublicRoutes
{
    private const CAPTCHA_BODY_MAX = 16384;

    public function __construct(private readonly Request $request)
    {
    }

    public function widget(): void
    {
        $file = dirname(__DIR__) . '/assets/widget.js';
        $mtime = is_file($file) ? filemtime($file) : false;
        $size = $mtime !== false ? filesize($file) : false;
        if ($mtime === false || $size === false) {
            Response::json(404, ['error' => 'not_found']);
            return;
        }
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'ETag' => $etag,
        ];
        $match = array_map('trim', explode(',', $this->request->header('If-None-Match')));
        if (in_array($etag, $match, true) || in_array('W/' . $etag, $match, true)) {
            Response::send(304, '', $headers, false);
            return;
        }
        Response::send(200, '', $headers + ['Content-Length' => (string) $size], false);
        if (!$this->request->isHead()) {
            readfile($file);
        }
    }

    public function config(Settings $settings): void
    {
        $commands = [];
        foreach (Commands::definitions($settings->int('max_translate_chars')) as $name => [$usage, $description, $example]) {
            $commands[] = ['name' => $name, 'usage' => $usage, 'description' => $description, 'example' => $example];
        }
        $required = $settings->captchaRequired();
        Response::json(200, [
            'title' => $settings->str('title'),
            'greeting' => $settings->str('greeting'),
            'placeholder' => $settings->str('placeholder'),
            'max_chars' => $settings->int('max_chars'),
            'max_translate_chars' => $settings->int('max_translate_chars'),
            'questions_per_conversation' => $settings->int('questions_per_conversation'),
            'captcha' => ['required' => $required, 'site_key' => $required ? $settings->str('captcha_site_key') : ''],
            'commands' => $commands,
            'languages' => Languages::all(),
        ]);
    }

    public function captcha(Captcha $captcha): void
    {
        if (!$this->request->isJson() || !$this->request->sameOrigin()) {
            Response::json(403, ['ok' => false, 'error' => 'This request is not allowed.']);
            return;
        }
        $body = $this->request->body(self::CAPTCHA_BODY_MAX);
        $data = $body !== null ? json_decode($body, true, 4) : null;
        $token = is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{20,4000}$/', $token)) {
            Response::json(400, ['ok' => false, 'error' => 'The captcha was not solved. Please try again.']);
            return;
        }
        if (!$captcha->required()) {
            Response::json(200, ['ok' => true]);
            return;
        }
        $error = $captcha->verify($token, $this->request);
        if ($error !== null) {
            Response::json(200, ['ok' => false, 'error' => $error]);
            return;
        }
        $captcha->issuePass($this->request);
        Response::json(200, ['ok' => true]);
    }
}
