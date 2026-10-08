<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;

/**
 * reCAPTCHA v2 checked on the server, then a signed pass cookie for pass_hours.
 */
final class Captcha
{
    public const COOKIE = 'opensolr_chat_pass';
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private string $secret;

    public function __construct(
        private readonly Settings $settings,
        Store $store,
        private readonly string $cookiePath,
    ) {
        $this->secret = $store->key('captcha_pass');
    }

    public function required(): bool
    {
        return $this->settings->captchaRequired();
    }

    private function sign(int $ts, Request $request): string
    {
        return hash_hmac('sha256', $ts . '|' . $request->clientIp() . '|' . $request->userAgent(), $this->secret);
    }

    public function hasValidPass(Request $request): bool
    {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $request->cookie(self::COOKIE), $m)) {
            return false;
        }
        $ts = (int) $m[1];
        $age = time() - $ts;
        if ($age < -60 || $age >= $this->settings->int('pass_hours') * 3600) {
            return false;
        }
        return hash_equals($this->sign($ts, $request), $m[2]);
    }

    public function issuePass(Request $request): void
    {
        $ts = time();
        Response::cookie(self::COOKIE, $ts . '.' . $this->sign($ts, $request), $ts + $this->settings->int('pass_hours') * 3600, $this->cookiePath, $request->isHttps(), 'Lax');
    }

    /**
     * Checks a reCAPTCHA response with Google: null when it is solved, else the reason.
     */
    public function verify(string $token, Request $request): ?string
    {
        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $this->settings->str('captcha_secret'), 'response' => $token, 'remoteip' => $request->clientIp()]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $data = is_string($response) && $status === 200 ? json_decode($response, true) : null;
        if (!is_array($data)) {
            return I18n::t('The captcha could not be checked. Please try again.');
        }
        if (($data['success'] ?? false) !== true) {
            return I18n::t('The captcha was not solved. Please try again.');
        }
        $host = is_string($data['hostname'] ?? null) ? strtolower($data['hostname']) : '';
        if ($host !== '' && !hash_equals($request->host(), $host)) {
            return I18n::t('The captcha was solved for another site.');
        }
        return null;
    }
}
