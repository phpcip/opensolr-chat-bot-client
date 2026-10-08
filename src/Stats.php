<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The stats of a period (today, 7 or 30 days): the totals and the top lists from the counters per day, the
 * visitors, the countries and the signed-in users from the conversations; every number made by SQLite.
 */
final class Stats
{
    public const PERIODS = [1, 7, 30];
    private const TOP = 20;
    private const USERS = 50;
    private const UNLINKED = 20;

    private \DateTimeZone $zone;

    public function __construct(private readonly Store $store, string $timezone)
    {
        $this->zone = new \DateTimeZone(Settings::validTimezone($timezone) ? $timezone : 'UTC');
    }

    /**
     * [first day yyyymmdd, last day yyyymmdd, from timestamp, until timestamp] of the $days days ending today, $back
     * periods back.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public function range(int $days, int $back = 0): array
    {
        $today = (new \DateTimeImmutable('now', $this->zone))->setTime(0, 0);
        $first = $today->modify(sprintf('%+d days', 1 - $days * ($back + 1)));
        $end = $today->modify(sprintf('%+d days', 1 - $days * $back));
        return [(int) $first->format('Ymd'), (int) $end->modify('-1 day')->format('Ymd'), $first->getTimestamp(), $end->getTimestamp()];
    }

    /**
     * Everything the stats page shows for the period; the previous period beside it when the history still holds it.
     * The 30 days read the sums (tally), a shorter period the counters of its days.
     *
     * @return array<string, mixed>
     */
    public function period(int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 7;
        $now = $this->range($days);
        $whole = $days === Store::HISTORY_DAYS;
        $counters = $this->counters(['kind', 'outcome', 'conv', 'signed', 'searched', 'unlinked', 'rating', 'clicked', 'first'], $now, $whole);
        $out = [
            'days' => $days,
            'totals' => $this->totals($counters) + $this->people($now),
            'previous' => $days * 2 <= Store::HISTORY_DAYS ? $this->totals($this->counters(['kind', 'outcome', 'conv', 'signed', 'searched', 'unlinked', 'rating', 'clicked', 'first'], $this->range($days, 1), false)) : null,
            'countries' => $this->countries($now, $whole),
            'users' => $this->users($now),
            'unlinked' => $this->unlinked($now),
            'daily' => $this->daily(),
        ];
        $out['totals']['countries'] = count(array_filter($out['countries'], static fn (array $c): bool => $c['country'] !== ''));
        foreach (['term', 'cited', 'page', 'click', 'command', 'tool', 'lang'] as $dim) {
            $out['top'][$dim] = $this->top($dim, $now, $whole);
        }
        return $out;
    }

    /**
     * The values of some counters in a period: dim => [value => amount].
     *
     * @param list<string> $dims
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return array<string, array<string, int>>
     */
    private function counters(array $dims, array $r, bool $whole): array
    {
        $in = implode(', ', array_fill(0, count($dims), '?'));
        if ($whole) {
            $stmt = $this->store->pdo()->prepare('SELECT dim, val, n FROM tally WHERE dim IN (' . $in . ')');
            $stmt->execute($dims);
        } else {
            $stmt = $this->store->pdo()->prepare('SELECT dim, val, SUM(n) AS n FROM counts WHERE dim IN (' . $in . ') AND day BETWEEN ? AND ? GROUP BY dim, val');
            $stmt->execute([...$dims, $r[0], $r[1]]);
        }
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['dim']][(string) $row['val']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * The numbers at the top of the page made from the counters.
     *
     * @param array<string, array<string, int>> $c
     * @return array<string, int|float|null>
     */
    private function totals(array $c): array
    {
        $get = static fn (string $dim, string $val): int => $c[$dim][$val] ?? 0;
        $answered = $get('outcome', 'answered');
        $failed = $get('outcome', 'failed') + $get('outcome', 'late');
        $messages = $get('kind', 'question') + $get('kind', 'command') + $get('kind', 'translate');
        $signedIn = $get('signed', '1');
        return [
            'messages' => $messages,
            'questions' => $get('kind', 'question'),
            'commands' => $get('kind', 'command'),
            'translations' => $get('kind', 'translate'),
            'conversations' => $get('conv', '1'),
            'signed_share' => $messages > 0 ? $signedIn / $messages : null,
            'answered' => $answered,
            'answered_share' => $answered + $failed > 0 ? $answered / ($answered + $failed) : null,
            'unlinked_share' => $get('searched', '1') > 0 ? $get('unlinked', '1') / $get('searched', '1') : null,
            'failed' => $failed,
            'limited' => $get('outcome', 'limited'),
            'good' => $get('rating', 'good'),
            'bad' => $get('rating', 'bad'),
            'clicked_share' => $answered > 0 ? $get('clicked', '1') / $answered : null,
            'median_ms' => self::median($c['first'] ?? []),
        ];
    }

    /**
     * The visitors (distinct IP addresses) and the signed-in users (distinct emails) of the period.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return array{visitors: int, users: int}
     */
    private function people(array $r): array
    {
        $pdo = $this->store->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM visitors WHERE last >= ?');
        $stmt->execute([$r[2]]);
        $visitors = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT email) FROM chats WHERE email > \'\' AND updated >= ?');
        $stmt->execute([$r[2]]);
        return ['visitors' => $visitors, 'users' => (int) $stmt->fetchColumn()];
    }

    /**
     * The median time to the first word of the answers, from the counter of 100 ms steps; null without answers.
     *
     * @param array<string, int> $steps step => answers
     */
    private static function median(array $steps): ?int
    {
        $total = array_sum($steps);
        if ($total <= 0) {
            return null;
        }
        ksort($steps, SORT_NUMERIC);
        $seen = 0;
        foreach ($steps as $step => $n) {
            $seen += $n;
            if ($seen * 2 >= $total) {
                return (int) $step * 100;
            }
        }
        return null;
    }

    /**
     * The values of one counter in the period, the largest first.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return list<array{val: string, n: int}>
     */
    private function top(string $dim, array $r, bool $whole): array
    {
        if ($whole) {
            $stmt = $this->store->pdo()->prepare('SELECT val, n FROM tally WHERE dim = ? AND n > 0 ORDER BY n DESC LIMIT ' . self::TOP);
            $stmt->execute([$dim]);
        } else {
            $stmt = $this->store->pdo()->prepare('SELECT val, SUM(n) AS n FROM counts WHERE dim = ? AND day BETWEEN ? AND ? GROUP BY val HAVING SUM(n) > 0 ORDER BY n DESC LIMIT ' . self::TOP);
            $stmt->execute([$dim, $r[0], $r[1]]);
        }
        return array_map(static fn (array $row): array => ['val' => (string) $row['val'], 'n' => (int) $row['n']], $stmt->fetchAll());
    }

    /**
     * Messages, conversations (started) and visitors per country, the most messages first (at most 250 rows).
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return list<array{country: string, name: string, conversations: int, visitors: int, messages: int}>
     */
    private function countries(array $r, bool $whole): array
    {
        $pdo = $this->store->pdo();
        $c = $this->counters(['country', 'cconv'], $r, $whole);
        $stmt = $pdo->prepare('SELECT country, COUNT(*) AS n FROM visitors WHERE last >= ? AND country > \'\' GROUP BY country');
        $stmt->execute([$r[2]]);
        $visitors = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        $names = $pdo->query('SELECT code, name FROM countries')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (array_keys(($c['country'] ?? []) + ($c['cconv'] ?? []) + $visitors) as $code) {
            $code = (string) $code;
            $out[] = [
                'country' => $code,
                'name' => (string) ($names[$code] ?? ''),
                'conversations' => $c['cconv'][$code] ?? 0,
                'visitors' => (int) ($visitors[$code] ?? 0),
                'messages' => $c['country'][$code] ?? 0,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['messages'], $b['visitors'], $a['country']] <=> [$a['messages'], $a['visitors'], $b['country']]);
        return $out;
    }

    /**
     * The signed-in users of the period, the most recent first, with the country of their last conversation.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return list<array{email: string, country: string, conversations: int, messages: int, last: int}>
     */
    private function users(array $r): array
    {
        // SQLite takes the bare column (country) from the row of MAX(updated)
        $stmt = $this->store->pdo()->prepare('SELECT email, country, COUNT(*) AS conversations, SUM(questions) AS messages, MAX(updated) AS last'
            . ' FROM chats WHERE email > \'\' AND updated >= ? GROUP BY email ORDER BY last DESC LIMIT ' . self::USERS);
        $stmt->execute([$r[2]]);
        return array_map(static fn (array $row): array => [
            'email' => (string) $row['email'], 'country' => (string) $row['country'], 'conversations' => (int) $row['conversations'],
            'messages' => (int) $row['messages'], 'last' => (int) $row['last'],
        ], $stmt->fetchAll());
    }

    /**
     * The latest questions the assistant searched the site for and answered with no link to it.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $r
     * @return list<array{chat_id: int, ts: int, question: string}>
     */
    private function unlinked(array $r): array
    {
        $stmt = $this->store->pdo()->prepare('SELECT chat_id, ts, question FROM turns WHERE unlinked = 1 AND ts >= ? AND ts < ? ORDER BY ts DESC LIMIT ' . self::UNLINKED);
        $stmt->execute([$r[2], $r[3]]);
        return array_map(static fn (array $row): array => ['chat_id' => (int) $row['chat_id'], 'ts' => (int) $row['ts'], 'question' => (string) $row['question']], $stmt->fetchAll());
    }

    /**
     * Answered, failed and refused messages per day of the whole history (30 days), oldest first, days without
     * messages included.
     *
     * @return list<array{day: int, answered: int, failed: int, limited: int}>
     */
    private function daily(): array
    {
        $r = $this->range(Store::HISTORY_DAYS);
        $stmt = $this->store->pdo()->prepare('SELECT day, val, SUM(n) AS n FROM counts WHERE dim = \'outcome\' AND day BETWEEN ? AND ? GROUP BY day, val');
        $stmt->execute([$r[0], $r[1]]);
        $by = [];
        foreach ($stmt->fetchAll() as $row) {
            $by[(int) $row['day']][(string) $row['val']] = (int) $row['n'];
        }
        $out = [];
        $day = (new \DateTimeImmutable('@' . $r[2]))->setTimezone($this->zone);
        for ($i = 0; $i < Store::HISTORY_DAYS; $i++) {
            $key = (int) $day->format('Ymd');
            $v = $by[$key] ?? [];
            $out[] = ['day' => $key, 'answered' => $v['answered'] ?? 0, 'failed' => ($v['failed'] ?? 0) + ($v['late'] ?? 0), 'limited' => $v['limited'] ?? 0];
            $day = $day->modify('+1 day');
        }
        return $out;
    }
}
