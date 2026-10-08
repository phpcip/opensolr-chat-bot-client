<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The history of the chat: every message with its answer, in its conversation, the counters per day the stats are
 * read from and their sums over the days kept (a stats page reads counters, never the messages, whatever the
 * traffic).
 */
final class Journal
{
    public const DIMS = ['kind', 'outcome', 'conv', 'country', 'cconv', 'signed', 'term', 'tool', 'command', 'cited', 'page', 'lang', 'searched', 'unlinked', 'first', 'rating', 'click', 'clicked'];
    public const SEARCH_TOOLS = ['opensolr_site_search', 'opensolr_lexical_search', 'opensolr_latest_search'];
    public const KINDS = ['question', 'command', 'translate'];
    public const OUTCOMES = ['answered', 'failed', 'late', 'limited'];
    private const TEXT_MAX = 100000;
    private const QUESTION_MAX = 20000;
    private const VALUE_MAX = 200;
    private const CITED_MAX = 20;
    private const GEO_TIMEOUT = 10;

    private ?\DateTimeZone $zone = null;

    public function __construct(private readonly Store $store, private readonly string $timezone)
    {
    }

    /** The day of a moment in the chat's time zone, as yyyymmdd. */
    public function day(int $ts): int
    {
        $this->zone ??= new \DateTimeZone(Settings::validTimezone($this->timezone) ? $this->timezone : 'UTC');
        return (int) (new \DateTimeImmutable('@' . $ts))->setTimezone($this->zone)->format('Ymd');
    }

    /**
     * Records one message and its answer; returns the token the visitor's browser rates and reports clicks with.
     *
     * @param array{
     *     key: string, ip: string, email: string, page: string, lang: string, site: string, kind: string,
     *     command: string, question: string, answer: string, error: string, outcome: string,
     *     searches: list<array{0: string, 1: string}>, first_ms: int, total_ms: int
     * } $t
     */
    public function record(array $t): string
    {
        $now = time();
        $day = $this->day($now);
        $kind = in_array($t['kind'], self::KINDS, true) ? $t['kind'] : 'question';
        $outcome = in_array($t['outcome'], self::OUTCOMES, true) ? $t['outcome'] : 'failed';
        $answered = $outcome === 'answered';
        $links = Links::extract($t['answer']);
        $base = Links::siteBase($t['site']);
        $cited = array_slice(array_values(array_filter($links, static fn (string $url): bool => Links::onSite($url, $base))), 0, self::CITED_MAX);
        $searched = false;
        $searches = [];
        foreach ($t['searches'] as [$tool, $words]) {
            $tool = self::value($tool);
            $words = self::value($words);
            $searches[] = [$tool, $words];
            $searched = $searched || in_array($tool, self::SEARCH_TOOLS, true);
        }
        $unlinked = $kind === 'question' && $answered && $searched && $cited === [] ? 1 : 0;
        $failed = $outcome === 'failed' || $outcome === 'late' ? 1 : 0;
        $question = mb_substr($t['question'], 0, self::QUESTION_MAX);
        $token = bin2hex(random_bytes(16));

        $counts = [];
        $add = static function (string $dim, string $val, int $n = 1) use (&$counts): void {
            $counts[$dim . "\0" . $val] = ($counts[$dim . "\0" . $val] ?? 0) + $n;
        };
        $add('kind', $kind);
        $add('outcome', $outcome);
        $add('signed', $t['email'] !== '' ? '1' : '0');
        if ($t['page'] !== '') {
            $add('page', $t['page']);
        }
        if ($kind !== 'question' && $t['command'] !== '') {
            $add('command', $t['command']);
        }
        foreach ($searches as [$tool, $words]) {
            $add('tool', str_starts_with($tool, 'opensolr_') ? substr($tool, 9) : $tool);
            if ($words !== '' && in_array($tool, self::SEARCH_TOOLS, true)) {
                $add('term', mb_strtolower($words));
            }
        }
        foreach ($cited as $url) {
            $add('cited', $url);
        }
        if ($kind === 'question' && $answered && $searched) {
            $add('searched', '1');
            if ($unlinked === 1) {
                $add('unlinked', '1');
            }
        }
        if ($answered && $kind !== 'command' && $t['first_ms'] > 0) {
            $add('first', (string) intdiv($t['first_ms'], 100));
        }

        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $find = $pdo->prepare('SELECT id, country FROM chats WHERE k = ?');
            $find->execute([$t['key']]);
            $row = $find->fetch();
            if (!is_array($row)) {
                $geo = $pdo->prepare('SELECT country, city FROM geo WHERE ip = ?');
                $geo->execute([$t['ip']]);
                $place = $geo->fetch() ?: ['country' => '', 'city' => ''];
                $country = (string) $place['country'];
                $pdo->prepare('INSERT INTO chats (k, started, updated, ip, email, country, city, page, lang, first, questions, failed, unlinked)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                    ->execute([$t['key'], $now, $now, $t['ip'], $t['email'], $country, $place['city'], $t['page'], $t['lang'], mb_substr($question, 0, 300), $failed, $unlinked]);
                $chat = (int) $pdo->lastInsertId();
                $add('conv', '1');
                if ($country !== '') {
                    $add('cconv', $country);
                }
                if ($t['lang'] !== '') {
                    $add('lang', $t['lang']);
                }
            } else {
                $chat = (int) $row['id'];
                $country = (string) $row['country'];
                $pdo->prepare('UPDATE chats SET updated = ?, questions = questions + 1, failed = failed + ?, unlinked = unlinked + ?,'
                    . ' email = CASE WHEN ? <> \'\' THEN ? ELSE email END WHERE id = ?')
                    ->execute([$now, $failed, $unlinked, $t['email'], $t['email'], $chat]);
            }
            // A country not known yet is counted by locate() once it is
            if ($country !== '') {
                $add('country', $country);
            }
            if ($t['ip'] !== '') {
                $pdo->prepare('INSERT INTO visitors (ip, country, last) VALUES (?, ?, ?) ON CONFLICT (ip) DO UPDATE SET last = excluded.last,'
                    . ' country = CASE WHEN visitors.country = \'\' THEN excluded.country ELSE visitors.country END')->execute([$t['ip'], $country, $now]);
            }
            $pdo->prepare('INSERT INTO turns (chat_id, token, ts, day, kind, outcome, page, question, answer, error, searches, links, unlinked, first_ms, total_ms)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $chat, $token, $now, $day, $kind, $outcome, $t['page'], $question, mb_substr($t['answer'], 0, self::TEXT_MAX),
                    mb_substr($t['error'], 0, 500), $searches ? (string) json_encode($searches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                    $links ? (string) json_encode($links, JSON_UNESCAPED_SLASHES) : '', $unlinked, max(0, $t['first_ms']), max(0, $t['total_ms']),
                ]);
            $this->count($pdo, $day, $counts);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return $token;
    }

    /**
     * A message refused by the limits of the chat, counted for the stats only.
     */
    public function refused(string $kind, string $email): void
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'question';
        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->count($pdo, $this->day(time()), ['kind' . "\0" . $kind => 1, 'outcome' . "\0" . 'limited' => 1, 'signed' . "\0" . ($email !== '' ? '1' : '0') => 1]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Adds the counters of one day, and their sums over the days kept, in two statements.
     *
     * @param array<string, int> $counts "dim\0value" => amount
     */
    private function count(\PDO $pdo, int $day, array $counts): void
    {
        if ($counts === []) {
            return;
        }
        $daily = [];
        $sums = [];
        foreach ($counts as $key => $n) {
            [$dim, $val] = explode("\0", $key, 2);
            array_push($daily, $dim, $day, $val, $n);
            array_push($sums, $dim, $val, $n);
        }
        $pdo->prepare('INSERT INTO counts (dim, day, val, n) VALUES ' . implode(', ', array_fill(0, count($counts), '(?, ?, ?, ?)'))
            . ' ON CONFLICT (dim, day, val) DO UPDATE SET n = n + excluded.n')->execute($daily);
        $pdo->prepare('INSERT INTO tally (dim, val, n) VALUES ' . implode(', ', array_fill(0, count($counts), '(?, ?, ?)'))
            . ' ON CONFLICT (dim, val) DO UPDATE SET n = n + excluded.n')->execute($sums);
    }

    /** The first day of the 30 days kept: the counters of the days before it leave the sums and are deleted. */
    public function firstDay(): int
    {
        return $this->day(time() - (Store::HISTORY_DAYS - 1) * 86400);
    }

    /**
     * Takes the days that left the 30 days out of the sums and deletes their counters, one day of one counter per
     * transaction (usually one day a day).
     */
    public function expire(): void
    {
        $pdo = $this->store->pdo();
        $first = $this->firstDay();
        $days = $pdo->prepare('SELECT DISTINCT day FROM counts WHERE dim = ? AND day < ?');
        $back = $pdo->prepare('INSERT INTO tally (dim, val, n) SELECT dim, val, -n FROM counts WHERE dim = ? AND day = ? ON CONFLICT (dim, val) DO UPDATE SET n = n + excluded.n');
        $drop = $pdo->prepare('DELETE FROM counts WHERE dim = ? AND day = ?');
        $empty = $pdo->prepare('DELETE FROM tally WHERE dim = ? AND n <= 0');
        foreach (self::DIMS as $dim) {
            $days->execute([$dim, $first]);
            foreach ($days->fetchAll(\PDO::FETCH_COLUMN) as $day) {
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $back->execute([$dim, $day]);
                    $drop->execute([$dim, $day]);
                    $empty->execute([$dim]);
                    $pdo->exec('COMMIT');
                } catch (\Throwable $e) {
                    $pdo->exec('ROLLBACK');
                    throw $e;
                }
            }
        }
    }

    /** A value of a counter or a search: one line, at most VALUE_MAX characters. */
    private static function value(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::VALUE_MAX);
    }

    /**
     * The country and the city of an IP address, looked up once (with ip_geo) after the answer was sent, and
     * written on its conversations. A failed lookup keeps nothing, so the next question asks again.
     */
    public function locate(string $ip, OpensolrApi $api): void
    {
        // A private or reserved address has no country to look up
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return;
        }
        $known = $this->store->pdo()->prepare('SELECT country, city FROM geo WHERE ip = ?');
        $known->execute([$ip]);
        $place = $known->fetch();
        if (is_array($place)) {
            if ($place['country'] !== '') {
                $this->assign($ip, (string) $place['country'], (string) $place['city']);
            }
            return;
        }
        try {
            $data = $api->call('ip_geo', ['ips' => $ip], self::GEO_TIMEOUT);
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: the place of a visitor could not be looked up: ' . $e->getMessage());
            return;
        }
        $entry = null;
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        foreach ($results as $key => $item) {
            if (is_array($item) && (string) ($item['ip'] ?? $key) === $ip) {
                $entry = $item;
                break;
            }
        }
        if ($entry === null) {
            return;
        }
        $code = strtoupper(is_string($entry['country_code'] ?? null) ? $entry['country_code'] : '');
        $code = !empty($entry['found']) && preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
        $city = $code !== '' && is_string($entry['city'] ?? null) ? self::value($entry['city']) : '';
        $name = $code !== '' && is_string($entry['country_name'] ?? null) ? self::value($entry['country_name']) : '';
        $pdo = $this->store->pdo();
        $pdo->prepare('INSERT INTO geo (ip, country, city, ts) VALUES (?, ?, ?, ?) ON CONFLICT (ip) DO UPDATE SET country = excluded.country, city = excluded.city, ts = excluded.ts')
            ->execute([$ip, $code, $city, time()]);
        if ($code !== '' && $name !== '') {
            $pdo->prepare('INSERT INTO countries (code, name) VALUES (?, ?) ON CONFLICT (code) DO UPDATE SET name = excluded.name')->execute([$code, $name]);
        }
        if ($code !== '') {
            $this->assign($ip, $code, $city);
        }
    }

    /**
     * Writes the country on the conversations of an address that do not have it yet, and counts their messages and
     * conversations for it on their own days.
     */
    private function assign(string $ip, string $code, string $city): void
    {
        $pdo = $this->store->pdo();
        $missing = $pdo->prepare('SELECT 1 FROM chats WHERE ip = ? AND country = \'\' LIMIT 1');
        $missing->execute([$ip]);
        if ($missing->fetchColumn() === false) {
            return;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT t.day, COUNT(*) AS n, SUM(t.id = (SELECT MIN(f.id) FROM turns f WHERE f.chat_id = c.id)) AS started'
                . ' FROM chats c JOIN turns t ON t.chat_id = c.id WHERE c.ip = ? AND c.country = \'\' GROUP BY t.day');
            $stmt->execute([$ip]);
            foreach ($stmt->fetchAll() as $row) {
                $counts = ['country' . "\0" . $code => (int) $row['n']];
                if ((int) $row['started'] > 0) {
                    $counts['cconv' . "\0" . $code] = (int) $row['started'];
                }
                $this->count($pdo, (int) $row['day'], $counts);
            }
            $pdo->prepare('UPDATE chats SET country = ?, city = ? WHERE ip = ? AND country = \'\'')->execute([$code, $city, $ip]);
            $pdo->prepare('UPDATE visitors SET country = ? WHERE ip = ?')->execute([$code, $ip]);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * The visitor's rating of an answer: 1 good, -1 bad, 0 none. False when the token is not one of an answer.
     */
    public function rate(string $token, int $rating): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !in_array($rating, [-1, 0, 1], true)) {
            return false;
        }
        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $find = $pdo->prepare('SELECT id, chat_id, day, rating FROM turns WHERE token = ? AND kind <> \'command\' AND outcome = \'answered\'');
            $find->execute([$token]);
            $turn = $find->fetch();
            if (!is_array($turn)) {
                $pdo->exec('ROLLBACK');
                return false;
            }
            $old = (int) $turn['rating'];
            if ($old !== $rating) {
                $pdo->prepare('UPDATE turns SET rating = ? WHERE id = ?')->execute([$rating, $turn['id']]);
                $bad = ($rating === -1 ? 1 : 0) - ($old === -1 ? 1 : 0);
                if ($bad !== 0) {
                    $pdo->prepare('UPDATE chats SET bad = bad + ? WHERE id = ?')->execute([$bad, $turn['chat_id']]);
                }
                $counts = [];
                if ($old !== 0) {
                    $counts['rating' . "\0" . ($old === 1 ? 'good' : 'bad')] = -1;
                }
                if ($rating !== 0) {
                    $counts['rating' . "\0" . ($rating === 1 ? 'good' : 'bad')] = 1;
                }
                $this->count($pdo, (int) $turn['day'], $counts);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return true;
    }

    /**
     * A link of an answer opened by the visitor, counted once per answer and link. False when the token is not one
     * of an answer or the link is not in it.
     */
    public function click(string $token, string $url): bool
    {
        $url = Links::normalize($url);
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || $url === null) {
            return false;
        }
        $pdo = $this->store->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $find = $pdo->prepare('SELECT id, chat_id, day, links, clicked FROM turns WHERE token = ?');
            $find->execute([$token]);
            $turn = $find->fetch();
            $links = is_array($turn) ? json_decode((string) $turn['links'], true) : null;
            if (!is_array($links) || !in_array($url, $links, true)) {
                $pdo->exec('ROLLBACK');
                return false;
            }
            $clicked = json_decode((string) $turn['clicked'], true);
            $clicked = is_array($clicked) ? $clicked : [];
            if (!in_array($url, $clicked, true)) {
                $first = $clicked === [];
                $clicked[] = $url;
                $pdo->prepare('UPDATE turns SET clicked = ? WHERE id = ?')->execute([(string) json_encode($clicked, JSON_UNESCAPED_SLASHES), $turn['id']]);
                $counts = ['click' . "\0" . $url => 1];
                if ($first) {
                    $counts['clicked' . "\0" . '1'] = 1;
                    $pdo->prepare('UPDATE chats SET clicked = clicked + 1 WHERE id = ?')->execute([$turn['chat_id']]);
                }
                $this->count($pdo, (int) $turn['day'], $counts);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return true;
    }
}
