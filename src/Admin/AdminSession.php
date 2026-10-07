<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;
use Opensolr\ChatBot\Store;

/**
 * The admin's session (own cookie, kept in SQLite), its CSRF token, the login form token and the login throttling.
 */
final class AdminSession
{
    public const COOKIE = 'opensolr_chat_admin';
    public const LOGIN_COOKIE = 'opensolr_chat_login';
    private const FAILURES_MAX = 5;

    /** @var array{k: string, csrf: string, flash: string, seen: int}|null */
    private ?array $row = null;
    private string $secret;

    public function __construct(
        private readonly Store $store,
        private readonly Request $request,
        private readonly string $cookiePath,
    ) {
        $this->secret = $store->key('admin');
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function isValid(): bool
    {
        if ($this->row !== null) {
            return true;
        }
        $id = $this->request->cookie(self::COOKIE);
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return false;
        }
        $stmt = $this->store->pdo()->prepare('SELECT k, csrf, flash, seen FROM admin_sessions WHERE k = ?');
        $stmt->execute([self::hash($id)]);
        $row = $stmt->fetch();
        $now = time();
        if (!is_array($row) || (int) $row['seen'] < $now - Store::ADMIN_IDLE_SECONDS) {
            return false;
        }
        if ((int) $row['seen'] < $now - 60) {
            $this->store->pdo()->prepare('UPDATE admin_sessions SET seen = ? WHERE k = ?')->execute([$now, $row['k']]);
        }
        $this->row = ['k' => (string) $row['k'], 'csrf' => (string) $row['csrf'], 'flash' => (string) $row['flash'], 'seen' => $now];
        return true;
    }

    /**
     * A new session (a new id at every login); the previous one of this browser is removed.
     */
    public function start(): void
    {
        $this->forget();
        $id = bin2hex(random_bytes(32));
        $row = ['k' => self::hash($id), 'csrf' => bin2hex(random_bytes(32)), 'flash' => '', 'seen' => time()];
        $this->store->pdo()->prepare('INSERT INTO admin_sessions (k, csrf, flash, seen) VALUES (?, ?, ?, ?)')->execute([$row['k'], $row['csrf'], '', $row['seen']]);
        $this->row = $row;
        Response::cookie(self::COOKIE, $id, 0, $this->cookiePath, $this->request->isHttps(), 'Strict');
    }

    public function destroy(): void
    {
        $this->forget();
        Response::cookie(self::COOKIE, '', 1, $this->cookiePath, $this->request->isHttps(), 'Strict');
    }

    private function forget(): void
    {
        $id = $this->request->cookie(self::COOKIE);
        if (preg_match('/^[a-f0-9]{64}$/', $id)) {
            $this->store->pdo()->prepare('DELETE FROM admin_sessions WHERE k = ?')->execute([self::hash($id)]);
        }
        $this->row = null;
    }

    public function csrf(): string
    {
        return $this->row['csrf'] ?? '';
    }

    public function checkCsrf(string $token): bool
    {
        return $this->row !== null && $token !== '' && hash_equals($this->row['csrf'], $token);
    }

    public function setFlash(string $message): void
    {
        if ($this->row !== null) {
            $this->store->pdo()->prepare('UPDATE admin_sessions SET flash = ? WHERE k = ?')->execute([$message, $this->row['k']]);
        }
    }

    public function takeFlash(): string
    {
        $flash = $this->row['flash'] ?? '';
        if ($flash !== '' && $this->row !== null) {
            $this->setFlash('');
            $this->row['flash'] = '';
        }
        return $flash;
    }

    /**
     * The token of the login form, bound to a random cookie of this browser.
     */
    public function loginToken(): string
    {
        $nonce = $this->request->cookie(self::LOGIN_COOKIE);
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            $nonce = bin2hex(random_bytes(16));
            Response::cookie(self::LOGIN_COOKIE, $nonce, 0, $this->cookiePath, $this->request->isHttps(), 'Strict');
        }
        return hash_hmac('sha256', 'login|' . $nonce, $this->secret);
    }

    public function checkLoginToken(string $token): bool
    {
        $nonce = $this->request->cookie(self::LOGIN_COOKIE);
        return preg_match('/^[a-f0-9]{32}$/', $nonce) === 1 && $token !== '' && hash_equals(hash_hmac('sha256', 'login|' . $nonce, $this->secret), $token);
    }

    private function visitor(): string
    {
        return hash_hmac('sha256', 'login-failures|' . $this->request->clientIp(), $this->secret);
    }

    public function throttled(): bool
    {
        $stmt = $this->store->pdo()->prepare('SELECT COUNT(*) FROM login_failures WHERE k = ? AND ts > ?');
        $stmt->execute([$this->visitor(), time() - Store::LOGIN_FAILURE_SECONDS]);
        return (int) $stmt->fetchColumn() >= self::FAILURES_MAX;
    }

    public function recordFailure(): void
    {
        $this->store->pdo()->prepare('INSERT INTO login_failures (k, ts) VALUES (?, ?)')->execute([$this->visitor(), time()]);
    }

    public function clearFailures(): void
    {
        $this->store->pdo()->prepare('DELETE FROM login_failures WHERE k = ?')->execute([$this->visitor()]);
    }
}
