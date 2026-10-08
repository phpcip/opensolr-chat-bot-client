<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The conversations of the last 30 days: filtered and paged in SQLite (newest first, by keyset), one conversation
 * with all its messages, and deleting a conversation or everything of an email or an IP address.
 */
final class History
{
    public const PAGE = 50;
    public const FLAGS = ['failed', 'unlinked', 'bad', 'clicked'];
    private const TURNS_MAX = 500;
    private const DELETE_BY = ['chat' => 'id', 'email' => 'email', 'ip' => 'ip'];

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * The filters of the address, checked: every value bounded, unknown values dropped.
     *
     * @param callable(string, int): string $get one parameter of the query string
     * @return array{days: int, who: string, email: string, country: string, ip: string, page: string, flag: string, q: string, link: string}
     */
    public static function filters(callable $get): array
    {
        $days = (int) $get('days', 3);
        $who = $get('who', 10);
        $country = strtoupper($get('country', 2));
        $ip = $get('ip', 45);
        $flag = $get('flag', 10);
        return [
            'days' => in_array($days, Stats::PERIODS, true) ? $days : Store::HISTORY_DAYS,
            'who' => in_array($who, ['signed', 'anonymous'], true) ? $who : '',
            'email' => Identity::email($get('email', 254)),
            'country' => preg_match('/^(?:[A-Z]{2}|-)$/', $country) ? $country : '',
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'page' => Links::normalize($get('page', 500)) ?? '',
            'flag' => in_array($flag, self::FLAGS, true) ? $flag : '',
            'q' => trim($get('q', 200)),
            'link' => Links::normalize($get('link', 2048)) ?? '',
        ];
    }

    /**
     * One page of conversations matching the filters: [rows, cursor of the next page or ''].
     *
     * @param array{days: int, who: string, email: string, country: string, ip: string, page: string, flag: string, q: string, link: string} $f
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function page(array $f, int $since, string $cursor): array
    {
        $where = ['c.updated >= ?'];
        $args = [$since];
        if ($f['who'] === 'signed') {
            $where[] = 'c.email > \'\'';
        } elseif ($f['who'] === 'anonymous') {
            $where[] = 'c.email = \'\'';
        }
        foreach (['email' => 'c.email', 'ip' => 'c.ip'] as $key => $column) {
            if ($f[$key] !== '') {
                $where[] = $column . ' = ?';
                $args[] = $f[$key];
            }
        }
        if ($f['country'] !== '') {
            $where[] = 'c.country = ?';
            $args[] = $f['country'] === '-' ? '' : $f['country'];
        }
        if ($f['flag'] !== '') {
            $where[] = 'c.' . $f['flag'] . ' > 0';
        }
        if ($f['page'] !== '') {
            $where[] = 'c.id IN (SELECT chat_id FROM turns WHERE page = ?)';
            $args[] = $f['page'];
        }
        $match = $this->store->canSearch() ? self::match($f['q'], $f['link']) : '';
        if ($match !== '') {
            $where[] = 'c.id IN (SELECT t.chat_id FROM turns t WHERE t.id IN (SELECT rowid FROM turns_fts WHERE turns_fts MATCH ?))';
            $args[] = $match;
        }
        if (preg_match('/^(\d{1,12})\.(\d{1,19})$/', $cursor, $m)) {
            $where[] = '(c.updated < ? OR (c.updated = ? AND c.id < ?))';
            array_push($args, (int) $m[1], (int) $m[1], (int) $m[2]);
        }
        $stmt = $this->store->pdo()->prepare('SELECT c.id, c.started, c.updated, c.ip, c.email, c.country, COALESCE(n.name, \'\') AS name, c.city, c.page, c.first,'
            . ' c.questions, c.failed, c.unlinked, c.bad, c.clicked FROM chats c LEFT JOIN countries n ON n.code = c.country'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.updated DESC, c.id DESC LIMIT ' . (self::PAGE + 1));
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $next = '';
        if (count($rows) > self::PAGE) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = $last['updated'] . '.' . $last['id'];
        }
        return [$rows, $next];
    }

    /**
     * The FTS5 query of the words (every word, as written) and of a link (its words in order); '' for none.
     */
    public static function match(string $words, string $link): string
    {
        $quote = static fn (string $w): string => '"' . str_replace('"', '""', $w) . '"';
        $parts = [];
        foreach ((array) preg_split('/[^\p{L}\p{N}]+/u', $words, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $parts[] = $quote((string) $w);
        }
        $tokens = (array) preg_split('/[^\p{L}\p{N}]+/u', $link, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens !== []) {
            $parts[] = $quote(implode(' ', $tokens));
        }
        return implode(' ', array_slice($parts, 0, 32));
    }

    /**
     * One conversation and its messages in order, or null.
     *
     * @return array{chat: array<string, mixed>, turns: list<array<string, mixed>>}|null
     */
    public function conversation(int $id): ?array
    {
        $pdo = $this->store->pdo();
        $stmt = $pdo->prepare('SELECT c.*, COALESCE(n.name, \'\') AS name FROM chats c LEFT JOIN countries n ON n.code = c.country WHERE c.id = ?');
        $stmt->execute([$id]);
        $chat = $stmt->fetch();
        if (!is_array($chat)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, ts, kind, outcome, page, question, answer, error, searches, links, clicked, unlinked, first_ms, total_ms, rating'
            . ' FROM turns WHERE chat_id = ? ORDER BY id LIMIT ' . self::TURNS_MAX);
        $stmt->execute([$id]);
        return ['chat' => $chat, 'turns' => $stmt->fetchAll()];
    }

    /**
     * The countries of the 30 days, for the filter (from the sums of the counters: at most 250 rows).
     *
     * @return list<array{country: string, name: string}>
     */
    public function countries(): array
    {
        return $this->store->pdo()->query('SELECT t.val AS country, COALESCE(n.name, \'\') AS name FROM tally t LEFT JOIN countries n ON n.code = t.val'
            . ' WHERE t.dim = \'country\' AND t.n > 0 ORDER BY name, t.val')->fetchAll();
    }

    /**
     * Deletes a conversation, or every conversation of an email or an IP address (and the place of the address);
     * returns how many conversations were deleted.
     */
    public function delete(string $what, string $value): int
    {
        $column = self::DELETE_BY[$what] ?? null;
        $value = match ($what) {
            'chat' => ctype_digit($value) ? $value : '',
            'email' => Identity::email($value),
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '',
            default => '',
        };
        if ($column === null || $value === '') {
            return 0;
        }
        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare('DELETE FROM turns WHERE chat_id IN (SELECT id FROM chats WHERE ' . $column . ' = ?)')->execute([$value]);
            $stmt = $pdo->prepare('DELETE FROM chats WHERE ' . $column . ' = ?');
            $stmt->execute([$value]);
            $deleted = $stmt->rowCount();
            if ($what === 'ip') {
                $pdo->prepare('DELETE FROM geo WHERE ip = ?')->execute([$value]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return $deleted;
    }
}
