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

    /**
     * The visitor's lock for $ttl seconds: its token, or null while another question of the visitor is answered.
     */
    public function acquire(string $visitorKey, int $ttl): ?string
    {
        $pdo = $this->store->pdo();
        $now = time();
        $pdo->prepare('DELETE FROM locks WHERE k = ? AND expires <= ?')->execute([$visitorKey, $now]);
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO locks (k, token, expires) VALUES (?, ?, ?)');
        $stmt->execute([$visitorKey, $token, $now + $ttl]);
        return $stmt->rowCount() === 1 ? $token : null;
    }

    public function release(string $visitorKey, string $token): void
    {
        $this->store->pdo()->prepare('DELETE FROM locks WHERE k = ? AND token = ?')->execute([$visitorKey, $token]);
    }
}
