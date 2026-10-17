<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * Questions per conversation, questions per visitor in a time window, one question at a time per visitor.
 */
final class Limits
{
    private string $secret;

    public function __construct(private readonly Store $store)
    {
        $this->secret = $store->key('limits');
    }

    /**
     * The key a visitor (or a visitor's conversation) is counted under; the IP address itself is never stored.
     */
    public function key(string ...$parts): string
    {
        return hash_hmac('sha256', implode('|', $parts), $this->secret);
    }

    public function conversationQuestions(string $conversationKey): int
    {
        $stmt = $this->store->pdo()->prepare('SELECT questions FROM conversations WHERE k = ? AND updated >= ?');
        $stmt->execute([$conversationKey, time() - Store::CONVERSATION_SECONDS]);
        return (int) $stmt->fetchColumn();
    }

    public function visitorQuestions(string $visitorKey, int $window): int
    {
        $stmt = $this->store->pdo()->prepare('SELECT COUNT(*) FROM visitor_hits WHERE k = ? AND ts > ?');
        $stmt->execute([$visitorKey, time() - $window]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Counts one question of the visitor, and of the conversation when it is given.
     */
    public function register(string $visitorKey, ?string $conversationKey): void
    {
        $pdo = $this->store->pdo();
        $now = time();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO visitor_hits (k, ts) VALUES (?, ?)')->execute([$visitorKey, $now]);
            if ($conversationKey !== null) {
                $pdo->prepare(
                    'INSERT INTO conversations (k, questions, updated) VALUES (?, 1, ?) ON CONFLICT (k) DO UPDATE SET'
                    . ' questions = CASE WHEN conversations.updated >= ? THEN conversations.questions + 1 ELSE 1 END,'
                    . ' updated = excluded.updated'
                )->execute([$conversationKey, $now, $now - Store::CONVERSATION_SECONDS]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** The locks this request holds: token => [open file, path]. */
    private array $held = [];

    /**
     * The visitor's lock: its token, or null while another question of the visitor is answered. It is a lock on a file,
     * which the system frees when the process that holds it ends, however it ends (a restart, a killed worker).
     */
    public function acquire(string $visitorKey): ?string
    {
        $dir = $this->store->dir() . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The locks directory cannot be created.');
        }
        $path = $dir . DIRECTORY_SEPARATOR . $visitorKey . '.lock';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $fh = fopen($path, 'c');
            if ($fh === false) {
                throw new \RuntimeException('The lock file cannot be opened.');
            }
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                fclose($fh);
                return null;
            }
            // A file its holder removed between this open and this lock is no longer the lock: open it again
            clearstatcache(true, $path);
            $now = @stat($path);
            if ($now !== false && $now['ino'] === fstat($fh)['ino']) {
                $token = bin2hex(random_bytes(16));
                $this->held[$token] = [$fh, $path];
                return $token;
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return null;
    }

    public function release(string $visitorKey, string $token): void
    {
        if (!isset($this->held[$token])) {
            return;
        }
        [$fh, $path] = $this->held[$token];
        unset($this->held[$token]);
        @unlink($path);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
