<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

final class Store
{
    private const SCHEMA_VERSION = 2;
    private const PURGE_EVERY = 300;
    private const PURGE_BATCH = 2000;
    private const PURGE_BATCHES = 25;
    public const CONVERSATION_SECONDS = 86400;
    // Browsers keep a cookie at most 400 days; the admin cookie is renewed on use, so only a session unused that long is gone
    public const ADMIN_SESSION_SECONDS = 400 * 86400;
    public const LOGIN_FAILURE_SECONDS = 900;
    public const HISTORY_DAYS = 30;

    private ?\PDO $pdo = null;
    private ?string $secret = null;
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
        if ($this->dataDir === '') {
            throw new \InvalidArgumentException('The data_dir is required.');
        }
    }

    /** The data folder. */
    public function dir(): string
    {
        return $this->dataDir;
    }

    public function file(): string
    {
        return $this->path('chat.sqlite');
    }

    /** A file of the data folder. */
    public function path(string $name): string
    {
        return $this->dataDir . DIRECTORY_SEPARATOR . basename($name);
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0700, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException('The data_dir cannot be created: ' . $this->dataDir);
        }
        $pdo = new \PDO('sqlite:' . $this->file(), null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate($pdo);
        return $this->pdo = $pdo;
    }

    private function migrate(\PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version === self::SCHEMA_VERSION) {
            return;
        }
        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException('The database was made by a newer version of this package.');
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version < 1) {
                foreach (self::schemaV1() as $sql) {
                    $pdo->exec($sql);
                }
                $insert = $pdo->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (?, ?)');
                $insert->execute(['signing_secret', bin2hex(random_bytes(32))]);
                $pdo->exec('PRAGMA user_version = 1');
            }
            if ($version < 2) {
                foreach (self::schemaV2() as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v')->execute(['fts', self::createSearch($pdo) ? '1' : '0']);
                $pdo->exec('PRAGMA user_version = 2');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    private static function schemaV1(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS conversations (k TEXT PRIMARY KEY, questions INTEGER NOT NULL, updated INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS conversations_updated ON conversations (updated)',
            'CREATE TABLE IF NOT EXISTS visitor_hits (id INTEGER PRIMARY KEY, k TEXT NOT NULL, ts INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS visitor_hits_k_ts ON visitor_hits (k, ts)',
            'CREATE INDEX IF NOT EXISTS visitor_hits_ts ON visitor_hits (ts)',
            'CREATE TABLE IF NOT EXISTS locks (k TEXT PRIMARY KEY, token TEXT NOT NULL, expires INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS locks_expires ON locks (expires)',
            'CREATE TABLE IF NOT EXISTS login_failures (id INTEGER PRIMARY KEY, k TEXT NOT NULL, ts INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS login_failures_k_ts ON login_failures (k, ts)',
            'CREATE INDEX IF NOT EXISTS login_failures_ts ON login_failures (ts)',
            'CREATE TABLE IF NOT EXISTS admin_sessions (k TEXT PRIMARY KEY, csrf TEXT NOT NULL, flash TEXT NOT NULL DEFAULT \'\', seen INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS admin_sessions_seen ON admin_sessions (seen)',
        ];
    }

    /**
     * The conversations (chats), each message and its answer (turns), the counters per day (counts) and their sums
     * over the days kept (tally), the visitors' IP addresses (visitors), their places (geo) and the country names.
     *
     * @return list<string>
     */
    private static function schemaV2(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS chats (id INTEGER PRIMARY KEY, k TEXT NOT NULL UNIQUE, started INTEGER NOT NULL, updated INTEGER NOT NULL,'
            . ' ip TEXT NOT NULL, email TEXT NOT NULL DEFAULT \'\', country TEXT NOT NULL DEFAULT \'\', city TEXT NOT NULL DEFAULT \'\','
            . ' page TEXT NOT NULL DEFAULT \'\', lang TEXT NOT NULL DEFAULT \'\', first TEXT NOT NULL DEFAULT \'\', questions INTEGER NOT NULL DEFAULT 0,'
            . ' failed INTEGER NOT NULL DEFAULT 0, unlinked INTEGER NOT NULL DEFAULT 0, bad INTEGER NOT NULL DEFAULT 0, clicked INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS chats_updated ON chats (updated)',
            'CREATE INDEX IF NOT EXISTS chats_signed ON chats (updated) WHERE email > \'\'',
            'CREATE INDEX IF NOT EXISTS chats_failed ON chats (updated) WHERE failed > 0',
            'CREATE INDEX IF NOT EXISTS chats_unlinked ON chats (updated) WHERE unlinked > 0',
            'CREATE INDEX IF NOT EXISTS chats_bad ON chats (updated) WHERE bad > 0',
            'CREATE INDEX IF NOT EXISTS chats_clicked ON chats (updated) WHERE clicked > 0',
            'CREATE INDEX IF NOT EXISTS chats_email ON chats (email, updated)',
            'CREATE INDEX IF NOT EXISTS chats_ip ON chats (ip, updated)',
            'CREATE INDEX IF NOT EXISTS chats_country ON chats (country, updated)',
            'CREATE TABLE IF NOT EXISTS turns (id INTEGER PRIMARY KEY, chat_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, ts INTEGER NOT NULL,'
            . ' day INTEGER NOT NULL, kind TEXT NOT NULL, outcome TEXT NOT NULL, page TEXT NOT NULL DEFAULT \'\', question TEXT NOT NULL,'
            . ' answer TEXT NOT NULL DEFAULT \'\', error TEXT NOT NULL DEFAULT \'\', searches TEXT NOT NULL DEFAULT \'\', links TEXT NOT NULL DEFAULT \'\','
            . ' clicked TEXT NOT NULL DEFAULT \'\', unlinked INTEGER NOT NULL DEFAULT 0, first_ms INTEGER NOT NULL DEFAULT 0, total_ms INTEGER NOT NULL DEFAULT 0,'
            . ' rating INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS turns_chat ON turns (chat_id, id)',
            'CREATE INDEX IF NOT EXISTS turns_ts ON turns (ts)',
            'CREATE INDEX IF NOT EXISTS turns_page ON turns (page, chat_id)',
            'CREATE INDEX IF NOT EXISTS turns_unlinked ON turns (ts) WHERE unlinked = 1',
            'CREATE TABLE IF NOT EXISTS counts (dim TEXT NOT NULL, day INTEGER NOT NULL, val TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (dim, day, val)) WITHOUT ROWID',
            'CREATE TABLE IF NOT EXISTS tally (dim TEXT NOT NULL, val TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (dim, val)) WITHOUT ROWID',
            'CREATE INDEX IF NOT EXISTS tally_top ON tally (dim, n)',
            'CREATE TABLE IF NOT EXISTS visitors (ip TEXT PRIMARY KEY, country TEXT NOT NULL DEFAULT \'\', last INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS visitors_last ON visitors (last, country)',
            'CREATE TABLE IF NOT EXISTS geo (ip TEXT PRIMARY KEY, country TEXT NOT NULL, city TEXT NOT NULL, ts INTEGER NOT NULL)',
            'CREATE INDEX IF NOT EXISTS geo_ts ON geo (ts)',
            'CREATE TABLE IF NOT EXISTS countries (code TEXT PRIMARY KEY, name TEXT NOT NULL)',
        ];
    }

    /**
     * The full-text index of the questions, the answers and the searches, kept by triggers; false when this SQLite
     * has no FTS5.
     */
    private static function createSearch(\PDO $pdo): bool
    {
        try {
            $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS turns_fts USING fts5(question, answer, searches, content='turns', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");
        } catch (\PDOException $e) {
            return false;
        }
        $pdo->exec('CREATE TRIGGER IF NOT EXISTS turns_fts_insert AFTER INSERT ON turns BEGIN'
            . ' INSERT INTO turns_fts (rowid, question, answer, searches) VALUES (new.id, new.question, new.answer, new.searches); END');
        $pdo->exec('CREATE TRIGGER IF NOT EXISTS turns_fts_delete AFTER DELETE ON turns BEGIN'
            . " INSERT INTO turns_fts (turns_fts, rowid, question, answer, searches) VALUES ('delete', old.id, old.question, old.answer, old.searches); END");
        return true;
    }

    /** The questions and answers can be searched by their words (SQLite has FTS5). */
    public function canSearch(): bool
    {
        return $this->meta('fts') === '1';
    }

    public function meta(string $key): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT v FROM meta WHERE k = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->pdo()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v')->execute([$key, $value]);
    }

    public function deleteMeta(string $key): void
    {
        $this->pdo()->prepare('DELETE FROM meta WHERE k = ?')->execute([$key]);
    }

    public function signingSecret(): string
    {
        if ($this->secret === null) {
            $secret = $this->meta('signing_secret');
            if ($secret === null || strlen($secret) < 64) {
                throw new \RuntimeException('The signing secret is missing from the database.');
            }
            $this->secret = $secret;
        }
        return $this->secret;
    }

    /**
     * A key derived from the signing secret for one purpose.
     */
    public function key(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, $this->signingSecret());
    }

    /**
     * @return array<string, string>
     */
    public function settings(): array
    {
        $rows = $this->pdo()->query('SELECT k, v FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * @param array<string, string> $values
     */
    public function saveSettings(array $values): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v');
            foreach ($values as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function adminPasswordHash(): ?string
    {
        $hash = $this->meta('admin_password_hash');
        return $hash !== null && $hash !== '' ? $hash : null;
    }

    public function setAdminPassword(string $hash): void
    {
        $this->setMeta('admin_password_hash', $hash);
        $this->pdo()->exec('DELETE FROM admin_sessions');
    }

    /**
     * A value kept $ttl seconds; nothing is kept when $produce gives null.
     */
    public function cached(string $key, int $ttl, callable $produce): mixed
    {
        $raw = $this->meta('cache:' . $key);
        if ($raw !== null) {
            $entry = json_decode($raw, true);
            if (is_array($entry) && (int) ($entry['until'] ?? 0) > time() && array_key_exists('value', $entry)) {
                return $entry['value'];
            }
        }
        $value = $produce();
        if ($value !== null) {
            $this->setMeta('cache:' . $key, (string) json_encode(['until' => time() + $ttl, 'value' => $value]));
        }
        return $value;
    }

    /**
     * Deletes expired rows, at most every PURGE_EVERY seconds, in small batches on indexed timestamps.
     */
    public function purgeIfDue(int $hitWindow, string $timezone = 'UTC'): void
    {
        $now = time();
        if ($now - (int) ($this->meta('purged_at') ?? '0') < self::PURGE_EVERY) {
            return;
        }
        $this->setMeta('purged_at', (string) $now);
        $jobs = [
            'DELETE FROM visitor_hits WHERE id IN (SELECT id FROM visitor_hits WHERE ts < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - max(60, $hitWindow),
            'DELETE FROM conversations WHERE rowid IN (SELECT rowid FROM conversations WHERE updated < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::CONVERSATION_SECONDS,
            'DELETE FROM locks WHERE rowid IN (SELECT rowid FROM locks WHERE expires < ? LIMIT ' . self::PURGE_BATCH . ')' => $now,
            'DELETE FROM login_failures WHERE id IN (SELECT id FROM login_failures WHERE ts < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::LOGIN_FAILURE_SECONDS,
            'DELETE FROM admin_sessions WHERE rowid IN (SELECT rowid FROM admin_sessions WHERE seen < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::ADMIN_SESSION_SECONDS,
            'DELETE FROM turns WHERE id IN (SELECT id FROM turns WHERE ts < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::HISTORY_DAYS * 86400,
            'DELETE FROM chats WHERE id IN (SELECT id FROM chats WHERE updated < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::HISTORY_DAYS * 86400,
            'DELETE FROM geo WHERE ip IN (SELECT ip FROM geo WHERE ts < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::HISTORY_DAYS * 86400,
            'DELETE FROM visitors WHERE ip IN (SELECT ip FROM visitors WHERE last < ? LIMIT ' . self::PURGE_BATCH . ')' => $now - self::HISTORY_DAYS * 86400,
        ];
        foreach ($jobs as $sql => $before) {
            $stmt = $this->pdo()->prepare($sql);
            for ($i = 0; $i < self::PURGE_BATCHES; $i++) {
                $stmt->execute([$before]);
                if ($stmt->rowCount() < self::PURGE_BATCH) {
                    break;
                }
            }
        }
        (new Journal($this, $timezone))->expire();
    }
}
