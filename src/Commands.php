<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The commands of the chat: a message starting with "/" runs one Opensolr lookup directly, answered in Markdown.
 */
final class Commands
{
    private const ALIASES = [
        '?' => 'help', 'commands' => 'help', 'geocode' => 'place', 'find' => 'place', 'currency' => 'rate',
        'exchange' => 'rate', 'zip' => 'postal', 'lang' => 'language', 'sentiment' => 'language',
    ];
    private const SEARCH_LIMIT = 6;
    private const PASSAGE_CHARS = 1200;

    public function __construct(
        private readonly OpensolrApi $api,
        private readonly Settings $settings,
        private readonly string $visitorIp,
        private readonly bool $accountTools = false,
        private readonly string $visitorEmail = '',
    ) {
    }

    /**
     * name => [usage, what it does, example].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function definitions(int $maxTranslateChars, bool $accountTools = false): array
    {
        // The signed-in visitor's own Opensolr account, where the Opensolr API offers it for this chat's index
        $account = $accountTools ? [
            'account' => ['/account', 'Your Opensolr plan: its price and limits, the AI requests used this month, and your indexes with the documents, disk and bandwidth each one uses.', '/account'],
            'index' => ['/index <index name>', 'One of your Opensolr Indexes: its disk, bandwidth and documents against your plan\'s limits, its Solr version and server.', '/index my_index'],
            'ingestion' => ['/ingestion <index name>', 'Your Data Ingestion jobs. Without an index name: every index of your account with its jobs and the errors of the failed ones. With one: that index\'s latest jobs.', '/ingestion my_index'],
            'errors' => ['/errors <index name>', 'The latest errors Solr logged for one of your indexes (kept 7 days): when, on which server, and what happened.', '/errors my_index'],
            'support' => ['/support <words>', 'Your support requests of the last year, newest first, each with whether Opensolr support answered it. With words: only the requests about them.', '/support invoice'],
        ] : [];
        $base = [
            'search' => ['/search <words>', 'Searches this site and lists the pages, products and PDF pages that match, each with its link.', '/search robotic lawn mower'],
            'time' => ['/time <place, coordinates or IP address>', 'The local date and time right now at a place, at coordinates or where an IP address is. Without anything after it: your own local time.', '/time Tokyo'],
            'rate' => ['/rate <amount> <currency> to <currency>', 'Today\'s exchange rate between currencies (three-letter codes), and the amount converted. Add a date as YYYY-MM-DD for a past day.', '/rate 100 EUR to RON'],
            'vat' => ['/vat <country codes>', 'The standard and the reduced VAT rates of one or more European countries or the United Kingdom (two-letter codes, separated by commas).', '/vat RO, DE'],
            'vatcheck' => ['/vatcheck <VAT number>', 'Checks whether an EU or UK VAT number is valid, and gives the company name and address it is registered to.', '/vatcheck IE6388047V'],
            'distance' => ['/distance <place> to <place>', 'The straight-line distance between two places, addresses or coordinates, in kilometres and miles.', '/distance Paris to Berlin'],
            'place' => ['/place <place, address or coordinates>', 'Finds a place or a street address and gives its coordinates, region and country; give coordinates (latitude,longitude) to learn what is there. Each answer has a map link.', '/place Santa Clara, California, USA'],
            'postal' => ['/postal <postal code or town>', 'Finds postal codes and the street addresses that have them: a code, a code and a town, or a town and a country.', '/postal 10115 Berlin'],
            'ip' => ['/ip <IP address>', 'Where an IP address is: country, region, city, time zone and a map link. Without anything after it: your own approximate location.', '/ip 8.8.8.8'],
            'language' => ['/language <text>', 'Detects the language of a text and measures its sentiment (positive, negative or neutral; measured on English text).', '/language Acest produs este excelent'],
            'translate' => ['/translate <from>-<to> <text>', self::t('Translates a text of up to @max characters into another language. Write the two languages as codes from the table of language codes, joined by a hyphen (en-es: from English into Spanish), or only the language to translate into (es), and the language of the text is recognized.', ['@max' => number_format($maxTranslateChars)]), '/translate en-es What is this?'],
            'help' => ['/help', 'This list of commands.', '/help'],
        ];
        // The account commands come right after /search
        return ['search' => $base['search']] + $account + $base;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function commandList(): array
    {
        return self::definitions($this->settings->int('max_translate_chars'), $this->accountTools);
    }

    /**
     * A /translate message taken apart: {from (code, null = recognize it), to, text}, or {error}; null when the
     * message is not a /translate command.
     *
     * @return array{from?: ?string, to?: string, text?: string, error?: string}|null
     */
    public function translation(string $message): ?array
    {
        if (!preg_match('/^\/translate(?:\s+(.*))?$/isu', trim($message), $m)) {
            return null;
        }
        if (!preg_match('/^(\S+)\s+(.+)$/su', trim($m[1] ?? ''), $parts)) {
            return ['error' => self::t('Write the languages and the text after the command, for example: `@example`', ['@example' => $this->commandList()['translate'][2]])];
        }
        $pair = self::languagePair(strtolower($parts[1]));
        if ($pair === null) {
            return ['error' => self::t('@codes is not a language code, nor two codes joined by a hyphen (en-es). The table of language codes is in the list of commands (the ? button).', ['@codes' => self::clean($parts[1])])];
        }
        $text = trim($parts[2]);
        // Quotation marks around the whole text are not part of it
        if (preg_match('/^["\x{201C}\x{201E}\x{00AB}](.*)["\x{201D}\x{201C}\x{00BB}]$/su', $text, $quoted) && trim($quoted[1]) !== '') {
            $text = trim($quoted[1]);
        }
        return ['from' => $pair[0], 'to' => $pair[1], 'text' => $text];
    }

    /**
     * @return array{0: ?string, 1: string}|null
     */
    private static function languagePair(string $codes): ?array
    {
        $languages = Languages::all();
        if (isset($languages[$codes])) {
            return [null, $codes];
        }
        $parts = explode('-', $codes);
        for ($i = 1; $i < count($parts); $i++) {
            $from = implode('-', array_slice($parts, 0, $i));
            $to = implode('-', array_slice($parts, $i));
            if (isset($languages[$from], $languages[$to])) {
                return [$from, $to];
            }
        }
        return null;
    }

    /**
     * The command a message starts with, aliases resolved; '' when it names no command.
     */
    public static function name(string $message): string
    {
        if (!preg_match('/^\/([a-z?]+)/isu', trim($message), $m)) {
            return '';
        }
        $name = strtolower($m[1]);
        $name = self::ALIASES[$name] ?? $name;
        return isset(self::definitions(0, true)[$name]) ? $name : '';
    }

    /**
     * The answer to a message starting with "/", in Markdown.
     */
    public function run(string $message): string
    {
        if (!preg_match('/^\/([a-z?]+)\s*(.*)$/isu', trim($message), $m)) {
            return $this->help();
        }
        $name = strtolower($m[1]);
        $args = trim($m[2]);
        $name = self::ALIASES[$name] ?? $name;
        $definitions = $this->commandList();
        if (!isset($definitions[$name]) || $name === 'help') {
            return ($name === 'help' ? '' : self::t('There is no command /@name.', ['@name' => $m[1]]) . "\n\n") . $this->help();
        }
        if ($args === '' && !in_array($name, ['time', 'ip', 'account', 'ingestion', 'support'], true)) {
            return self::t('Write what to look up after the command, for example: `@example`', ['@example' => $definitions[$name][2]]);
        }
        // /translate is streamed by the chat; here only what is wrong with it
        if ($name === 'translate') {
            return $this->translation($message)['error'] ?? 'The translation could not be written.';
        }
        if (isset(AccountTools::COMMANDS[$name])) {
            return $this->commandAccount($name, $args);
        }
        return match ($name) {
            'search' => $this->commandSearch($args),
            'time' => $this->commandTime($args),
            'rate' => $this->commandRate($args),
            'vat' => $this->commandVat($args),
            'vatcheck' => $this->commandVatcheck($args),
            'distance' => $this->commandDistance($args),
            'place' => $this->commandPlace($args),
            'postal' => $this->commandPostal($args),
            'ip' => $this->commandIp($args),
            'language' => $this->commandLanguage($args),
        };
    }

    public function help(): string
    {
        $lines = ['**Commands.** Start your message with one of these to look something up at once:', ''];
        foreach ($this->commandList() as $definition) {
            $lines[] = '- `' . $definition[0] . '`: ' . $definition[1] . ' Example: `' . $definition[2] . '`';
        }
        $lines[] = '';
        $lines[] = 'Anything else you write is answered by the assistant from this site.';
        return implode("\n", $lines);
    }

    /**
     * @param array<string, scalar> $replacements
     */
    private static function t(string $text, array $replacements = []): string
    {
        return strtr($text, array_map('strval', $replacements));
    }

    /**
     * Text from a lookup or the API, made safe for Markdown on one line.
     */
    private static function clean(mixed $value): string
    {
        return is_scalar($value) ? trim(str_replace(['[', ']', '`', '*', "\n"], ['(', ')', "'", '', ' '], (string) $value)) : '';
    }

    /**
     * @param array<mixed> $entry
     */
    private static function missing(string $key, array $entry): string
    {
        return '- ' . self::clean($key) . ': nothing found' . (isset($entry['msg']) ? ' (' . self::clean($entry['msg']) . ')' : '');
    }

    /**
     * One lookup with its answer decoded (status removed, shaped), or the reason there is none.
     *
     * @param array<string, scalar> $params
     * @return array<mixed>|string
     */
    private function lookup(string $endpoint, array $params, ?callable $shape = null, int $timeout = 60): array|string
    {
        try {
            $data = $this->api->call($endpoint, $params, $timeout);
        } catch (\Throwable $e) {
            return self::t('The lookup failed: @message', ['@message' => $e->getMessage()]);
        }
        unset($data['status'], $data['contract_version']);
        return $shape !== null ? $shape($data) : $data;
    }

    /**
     * @return list<array<mixed>>|array<mixed>
     */
    private static function entries(mixed $results): array
    {
        return is_array($results) ? $results : [];
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function withMaps(array $data): array
    {
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $lat = $entry['lat'] ?? ($entry['latitude'] ?? null);
            $lon = $entry['lon'] ?? ($entry['longitude'] ?? null);
            if (is_numeric($lat) && is_numeric($lon)) {
                $data['results'][$key]['map'] = 'https://maps.google.com/?q=' . rawurlencode((float) $lat . ',' . (float) $lon);
            }
        }
        return $data;
    }

    private function date(int $timestamp): string
    {
        $zone = new \DateTimeZone(Settings::validTimezone($this->settings->str('timezone')) ? $this->settings->str('timezone') : 'UTC');
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('j F Y');
    }

    private function commandSearch(string $args): string
    {
        try {
            $found = $this->api->call('vdb_search', [
                'index_name' => $this->api->indexName(),
                'keys' => $args,
                'limit' => self::SEARCH_LIMIT,
                'offset' => 0,
                'passages' => self::PASSAGE_CHARS,
                'site' => 1,
            ], 60);
        } catch (\RuntimeException $e) {
            return self::t('The site search failed: @message', ['@message' => $e->getMessage()]);
        }
        $results = array_values(self::entries($found['results'] ?? null));
        if (!$results) {
            return self::t('Nothing on this site matches "@words".', ['@words' => $args]);
        }
        $count = is_numeric($found['num_found'] ?? null) ? (int) $found['num_found'] : count($results);
        $lines = [self::t('**@count results** for "@words":', ['@count' => $count, '@words' => $args]), ''];
        foreach ($results as $i => $result) {
            $result = is_array($result) ? $result : [];
            $price = is_numeric($result['price'] ?? null) ? ' · ' . number_format((float) $result['price'], 2) . (!empty($result['currency']) ? ' ' . self::clean($result['currency']) : '') : '';
            $ts = is_string($result['date'] ?? null) && $result['date'] !== '' ? strtotime($result['date']) : false;
            $date = $ts ? ' · ' . $this->date($ts) : '';
            $title = is_scalar($result['title'] ?? null) ? strip_tags((string) $result['title']) : '';
            $uri = is_scalar($result['uri'] ?? null) ? (string) $result['uri'] : '';
            $lines[] = ($i + 1) . '. [' . self::clean($title) . '](' . $uri . ')' . $price . $date;
            $passage = is_scalar($result['passage'] ?? null) ? (string) $result['passage'] : '';
            if ($passage === '') {
                $passage = is_scalar($result['content'] ?? null) ? (string) $result['content'] : '';
            }
            $text = self::clean(html_entity_decode(strip_tags($passage), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $lines[] = '   ' . mb_substr($text, 0, 220) . (mb_strlen($text) > 220 ? '…' : '');
            }
            foreach (array_slice(self::entries($result['pages'] ?? null), 0, 3) as $page) {
                $page = is_array($page) ? $page : [];
                $label = ((int) ($page['page'] ?? 0) > 0) ? self::t('PDF page @n', ['@n' => (int) $page['page']]) : 'Attached document';
                $lines[] = '   - [' . $label . '](' . (is_scalar($page['uri'] ?? null) ? (string) $page['uri'] : '') . ')';
            }
        }
        return implode("\n", $lines);
    }

    private function commandTime(string $args): string
    {
        if ($args === '' || filter_var($args, FILTER_VALIDATE_IP) !== false) {
            $params = ['ips' => $args !== '' ? $args : $this->visitorIp];
        } elseif (preg_match('/^-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/', $args)) {
            $params = ['coords' => str_replace(' ', '', $args)];
        } else {
            $params = ['places' => $args];
        }
        try {
            $data = $this->api->call('local_time', $params, 30);
        } catch (\RuntimeException $e) {
            return self::t('The lookup failed: @message', ['@message' => $e->getMessage()]);
        }
        $lines = [];
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $key = (string) $key;
            if (empty($entry['found'])) {
                $lines[] = self::missing($key, $entry);
                continue;
            }
            $where = implode(', ', array_filter([self::clean($entry['place'] ?? $key), self::clean($entry['country'] ?? '')]));
            $time = is_scalar($entry['time'] ?? null) ? substr((string) $entry['time'], 0, 5) : '';
            $lines[] = '- **' . ($where !== '' ? $where : self::clean($key)) . '**: ' . self::clean($entry['date'] ?? '') . ', **' . $time . '** (' . self::clean($entry['timezone'] ?? '') . ', UTC' . self::clean($entry['utc_offset'] ?? '') . ')';
        }
        return $lines ? implode("\n", $lines) : 'Nothing found.';
    }

    private function commandRate(string $args): string
    {
        if (!preg_match('/^(?:(\d+(?:[.,]\d+)?)\s*)?([a-z]{3})(?:\s*(?:to|in|->|=|\/)?\s*([a-z]{3}(?:\s*,\s*[a-z]{3})*))?(?:\s+(\d{4}-\d{2}-\d{2}))?$/i', $args, $m)) {
            return 'Write it like this: `/rate 100 EUR to RON` (amount optional, three-letter currency codes).';
        }
        $amount = isset($m[1]) && $m[1] !== '' ? (float) str_replace(',', '.', $m[1]) : 1.0;
        $params = array_filter([
            'base' => strtoupper($m[2]),
            'to' => strtoupper((string) preg_replace('/\s+/', '', $m[3] ?? '')),
            'date' => $m[4] ?? '',
        ], static fn (string $v): bool => $v !== '');
        if ($amount > 0) {
            $params['amount'] = (string) $amount;
        }
        $data = $this->lookup('currency_rates', $params);
        if (!is_array($data)) {
            return $data;
        }
        $base = self::clean($data['base'] ?? strtoupper($m[2]));
        $converted = is_array($data['converted'] ?? null) ? $data['converted'] : [];
        $lines = [];
        foreach (self::entries($data['rates'] ?? null) as $currency => $rate) {
            $rate = is_numeric($rate) ? (float) $rate : 0.0;
            $value = is_numeric($converted[$currency] ?? null) ? (float) $converted[$currency] : $amount * $rate;
            $lines[] = '- **' . rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.') . ' ' . $base . ' = ' . number_format($value, 2, '.', ',') . ' ' . self::clean($currency) . '** (1 ' . $base . ' = ' . $rate . ' ' . self::clean($currency) . ')';
        }
        if (count($lines) > 12) {
            $lines = array_slice($lines, 0, 12);
            $lines[] = '…and more: name the currencies you want after "to".';
        }
        foreach (self::entries($data['unknown'] ?? null) as $currency) {
            $lines[] = '- ' . self::clean($currency) . ': no rate';
        }
        return implode("\n", $lines) . "\n\n" . self::t('Rates of @date.', ['@date' => self::clean($data['date'] ?? '')]);
    }

    private function commandVat(string $args): string
    {
        $data = $this->lookup('vat_rates', ['country_codes' => $args]);
        if (!is_array($data)) {
            return $data;
        }
        $lines = [];
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            if (empty($entry['found'])) {
                $lines[] = self::missing((string) $key, $entry);
                continue;
            }
            $standard = is_numeric($entry['standard_rate'] ?? null) ? (float) $entry['standard_rate'] : 0.0;
            $lines[] = '**' . self::clean($entry['country'] ?? $key) . '** (' . self::clean($entry['country_code'] ?? '') . '): standard rate **' . $standard . '%**';
            $groups = [];
            foreach (self::entries($entry['reduced_rates'] ?? null) as $category => $rate) {
                $groups[(string) (is_numeric($rate) ? (float) $rate : 0.0)][] = self::clean($category);
            }
            krsort($groups, SORT_NUMERIC);
            foreach ($groups as $rate => $categories) {
                $lines[] = '- ' . $rate . '%: ' . implode(', ', $categories);
            }
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }

    private function commandVatcheck(string $args): string
    {
        $data = $this->lookup('vat_check', ['vat_numbers' => str_replace(',', ';', $args)]);
        if (!is_array($data)) {
            return $data;
        }
        $lines = [];
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            if (!isset($entry['valid'])) {
                $lines[] = self::missing((string) $key, $entry);
                continue;
            }
            $line = '- **' . self::clean($key) . '** ' . ($entry['valid'] ? 'is valid' : 'is not valid');
            $details = array_filter([self::clean($entry['name'] ?? ''), self::clean($entry['address'] ?? '')]);
            $lines[] = $line . ($details ? ': ' . implode(', ', $details) : '.');
        }
        return implode("\n", $lines);
    }

    private function commandDistance(string $args): string
    {
        $parts = preg_split('/\s+to\s+|\s*\|\s*|\s+-\s+/i', $args, 2);
        if (!is_array($parts) || count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return 'Write it like this: `/distance Paris to Berlin`.';
        }
        $data = $this->lookup('geo_distance', ['from' => trim($parts[0]), 'to' => trim($parts[1])]);
        if (!is_array($data)) {
            return $data;
        }
        $results = self::entries($data['results'] ?? null);
        $result = is_array($results[0] ?? null) ? $results[0] : [];
        if (empty($result['found'])) {
            return self::t('One of the places was not found (@msg).', ['@msg' => self::clean($result['msg'] ?? '')]);
        }
        $name = static fn (mixed $end, string $query): string => implode(', ', array_filter([
            self::clean(is_array($end) ? ($end['place'] ?? $query) : $query),
            self::clean(is_array($end) ? ($end['country'] ?? '') : ''),
        ]));
        $km = is_numeric($result['km'] ?? null) ? (float) $result['km'] : 0.0;
        $mi = is_numeric($result['mi'] ?? null) ? (float) $result['mi'] : 0.0;
        return '**' . $name($result['from'] ?? null, trim($parts[0])) . '** → **' . $name($result['to'] ?? null, trim($parts[1])) . '**: **' . number_format($km, 1, '.', ',') . ' km** (' . number_format($mi, 1, '.', ',') . ' miles), in a straight line.';
    }

    private function commandPlace(string $args): string
    {
        $data = $this->lookup('address_geo', ['addresses' => $args], self::withMaps(...));
        if (!is_array($data)) {
            return $data;
        }
        $lines = [];
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            if (empty($entry['found'])) {
                $lines[] = self::missing((string) $key, $entry);
                continue;
            }
            $where = implode(', ', array_unique(array_filter([self::clean($entry['place'] ?? ''), self::clean($entry['locality'] ?? ''), self::clean($entry['region'] ?? ''), self::clean($entry['country'] ?? '')])));
            $extra = isset($entry['postal_code']) ? ', ' . self::t('postal code @code', ['@code' => self::clean($entry['postal_code'])]) : '';
            $lat = is_numeric($entry['lat'] ?? null) ? (float) $entry['lat'] : 0.0;
            $lon = is_numeric($entry['lon'] ?? null) ? (float) $entry['lon'] : 0.0;
            $lines[] = '- **' . $where . '**' . $extra . ': ' . $lat . ', ' . $lon . (isset($entry['map']) && is_string($entry['map']) ? ' ([map](' . $entry['map'] . '))' : '');
        }
        return implode("\n", $lines);
    }

    private function commandPostal(string $args): string
    {
        $data = $this->lookup('postal_codes', ['query' => $args, 'limit' => 8]);
        if (!is_array($data)) {
            return $data;
        }
        $lines = [];
        foreach (self::entries($data['postal_codes'] ?? null) as $row) {
            $row = is_array($row) ? $row : [];
            $lines[] = '- **' . self::clean($row['postal_code'] ?? '') . '** ' . implode(', ', array_filter([self::clean($row['place'] ?? ''), self::clean($row['region'] ?? ''), self::clean($row['country'] ?? '')]));
        }
        $addresses = self::entries($data['addresses'] ?? null);
        if ($addresses) {
            $lines[] = '';
            $lines[] = 'Street addresses:';
            foreach (array_slice($addresses, 0, 5) as $row) {
                $row = is_array($row) ? $row : [];
                $lines[] = '- ' . trim(self::clean($row['street'] ?? '') . ' ' . self::clean($row['number'] ?? '')) . ', ' . self::clean($row['postal_code'] ?? '') . ' ' . self::clean($row['place'] ?? '');
            }
        }
        return $lines ? implode("\n", $lines) : 'Nothing found.';
    }

    private function commandIp(string $args): string
    {
        $data = $this->lookup('ip_geo', ['ips' => $args !== '' ? $args : $this->visitorIp], self::withMaps(...));
        if (!is_array($data)) {
            return $data;
        }
        $lines = [];
        foreach (self::entries($data['results'] ?? null) as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            if (empty($entry['found'])) {
                $lines[] = self::missing((string) $key, $entry);
                continue;
            }
            $where = implode(', ', array_filter([self::clean($entry['city'] ?? ''), self::clean($entry['region'] ?? ''), self::clean($entry['country_name'] ?? '')]));
            $zone = isset($entry['timezone']) ? ', ' . self::t('time zone @tz', ['@tz' => self::clean($entry['timezone'])]) : '';
            $map = isset($entry['map']) && is_string($entry['map']) ? ' ([map](' . $entry['map'] . '))' : '';
            $lines[] = '- **' . self::clean($entry['ip'] ?? $key) . '**: ' . ($where !== '' ? $where : self::clean($entry['country_code'] ?? '')) . $zone . $map;
        }
        return implode("\n", $lines);
    }

    private function commandLanguage(string $args): string
    {
        $data = $this->lookup('enrich_text', [
            'index_name' => $this->api->indexName(),
            'items' => (string) json_encode([['id' => '1', 'description' => $args]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ], static function (array $data): array {
            $results = is_array($data['results'] ?? null) ? $data['results'] : [];
            $item = is_array($results[0] ?? null) ? $results[0] : [];
            return [
                'language' => $item['meta_detected_language'] ?? null,
                'locale' => $item['meta_og_locale'] ?? null,
                'sentiment' => [
                    'positive' => $item['sent_pos'] ?? null,
                    'negative' => $item['sent_neg'] ?? null,
                    'neutral' => $item['sent_neu'] ?? null,
                    'compound' => $item['sent_com'] ?? null,
                ],
            ];
        });
        if (!is_array($data)) {
            return $data;
        }
        $compound = is_numeric($data['sentiment']['compound'] ?? null) ? (float) $data['sentiment']['compound'] : 0.0;
        $mood = $compound >= 0.05 ? 'positive' : ($compound <= -0.05 ? 'negative' : 'neutral');
        $code = is_scalar($data['language'] ?? null) ? strtolower(trim((string) $data['language'])) : '';
        // A text too short or too loosely written for the language detector: the chat model recognizes it
        if ($code === '') {
            $code = $this->modelLanguage($args);
        }
        $names = Languages::all();
        $language = $code === '' ? '?' : self::clean($code) . (isset($names[$code]) ? ' (' . self::clean($names[$code][0]) . ')' : '');
        return '- Language: **' . $language . '**' . "\n" . '- Sentiment: **' . $mood . '** (' . self::t('compound score @c, from -1 to 1', ['@c' => $compound]) . ')';
    }

    /**
     * The language of a text as the Opensolr chat model names it: its two-letter code, '' when there is none.
     */
    private function modelLanguage(string $text): string
    {
        try {
            $answer = $this->api->chat([
                'messages' => [
                    ['role' => 'system', 'content' => 'Name the language the user\'s text is written in, as its two-letter ISO 639-1 code in lowercase, and nothing else.'],
                    ['role' => 'user', 'content' => mb_substr($text, 0, 2000)],
                ],
                'max_tokens' => 8,
            ], 60);
        } catch (\Throwable $e) {
            return '';
        }
        $content = $answer['choices'][0]['message']['content'] ?? '';
        $reply = is_string($content) ? strtolower(trim($content)) : '';
        return preg_match('/^[^a-z]*([a-z]{2})\b/', $reply, $m) ? $m[1] : '';
    }

    /** An account command: the visitor's own account, read by the Opensolr API, in Markdown. */
    private function commandAccount(string $name, string $args): string
    {
        $params = [];
        $arg = trim($args, " \t\"'`");
        if (str_contains($arg, '@') && !str_contains(trim($arg), ' ')) {
            $params['email'] = $arg;
        } elseif ($arg !== '') {
            $params[$name === 'support' ? 'words' : 'index_name'] = $arg;
        }
        $data = AccountTools::run($this->api, $name, $this->visitorEmail, $params);
        if (is_string($data)) {
            return self::clean($data);
        }
        return match ($name) {
            'account' => self::accountText($data),
            'index' => self::indexText($data),
            'ingestion' => self::ingestionText($data),
            'errors' => self::errorsText($data),
            'support' => self::supportText($data),
        };
    }

    private static function mb(mixed $value): string
    {
        return number_format((float) $value, 2) . ' MB';
    }

    private static function yes(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /** @param array<mixed> $d */
    private static function accountText(array $d): string
    {
        $l = (array) ($d['limits'] ?? []);
        $lines = ['**' . self::clean($d['email'] ?? '') . '**: plan ' . self::clean($d['plan'] ?? '')
            . (($d['price'] ?? '') !== '' ? ', ' . self::clean($d['price']) . ' every ' . self::clean($d['recurrence'] ?? '') : ''), ''];
        $lines[] = '- Indexes: ' . (int) ($d['indexes_total'] ?? 0) . ' of ' . (int) ($l['indexes'] ?? 0);
        $lines[] = '- Disk per index: ' . self::mb($l['disk_per_index_mb'] ?? 0);
        $lines[] = '- Bandwidth per index each month: ' . self::mb($l['bandwidth_per_index_mb_per_month'] ?? 0);
        $lines[] = '- AI requests this month: ' . number_format((int) ($d['ai_requests_used_this_month'] ?? 0)) . ' of ' . number_format((int) ($l['ai_requests_per_month'] ?? 0));
        $lines[] = '- AI and vectors: ' . self::yes($l['vectors_and_ai'] ?? false) . ', Web Crawler: ' . self::yes($l['web_crawler'] ?? false)
            . ', Analytics: ' . self::yes($l['analytics'] ?? false) . ', Backup: ' . self::yes($l['backup'] ?? false);
        $indexes = (array) ($d['indexes'] ?? []);
        if ($indexes) {
            $lines[] = '';
            $lines[] = '| Index | Documents | Disk | Bandwidth | Server |';
            $lines[] = '|---|---|---|---|---|';
            foreach ($indexes as $i) {
                $lines[] = '| ' . self::clean($i['index_name'] ?? '') . ' | ' . number_format((int) ($i['documents'] ?? 0)) . ' | ' . self::mb($i['disk_used_mb'] ?? 0)
                    . ' | ' . self::mb($i['bandwidth_used_mb'] ?? 0) . ' | ' . self::clean($i['server'] ?? '') . ' |';
            }
        }
        return implode("\n", $lines);
    }

    /** @param array<mixed> $d */
    private static function indexText(array $d): string
    {
        return implode("\n", [
            '**' . self::clean($d['index_name'] ?? '') . '**: Solr ' . self::clean($d['solr_version'] ?? '') . ' on ' . self::clean($d['server'] ?? ''),
            '',
            '- Disk: ' . self::mb($d['disk_used_mb'] ?? 0) . ' of ' . self::mb($d['disk_limit_mb'] ?? 0),
            '- Bandwidth this month: ' . self::mb($d['bandwidth_used_mb'] ?? 0) . ' of ' . self::mb($d['bandwidth_limit_mb'] ?? 0),
            '- Documents: ' . number_format((int) ($d['indexed_docs'] ?? 0)),
            '- AI requests this month: ' . number_format((int) ($d['ai_requests_used'] ?? 0)) . ' of ' . number_format((int) ($d['max_ai_requests'] ?? 0)),
            '- Created: ' . self::clean($d['created'] ?? '') . ', last written: ' . self::clean($d['last_written'] ?? ''),
        ]);
    }

    /** @param array<mixed> $job */
    private static function jobLine(array $job): string
    {
        $line = '- ' . self::clean($job['submitted'] ?? '') . ' · ' . self::clean($job['state'] ?? '') . ' · ' . (int) ($job['written'] ?? 0) . ' of '
            . (int) ($job['documents'] ?? 0) . ' written, ' . (int) ($job['failed'] ?? 0) . ' failed · job ' . self::clean($job['job_id'] ?? '');
        return ($job['error'] ?? '') !== '' && $job['error'] !== null ? $line . "\n  " . self::clean($job['error']) : $line;
    }

    /** @param array<mixed> $counts */
    private static function counts(mixed $counts): string
    {
        if (!is_array($counts)) {
            return 'no Data Ingestion jobs in the last 30 days';
        }
        $parts = [];
        foreach ($counts as $state => $n) {
            if ((int) $n > 0) {
                $parts[] = (int) $n . ' ' . str_replace('_', ' ', (string) $state);
            }
        }
        return $parts ? implode(', ', $parts) : 'no Data Ingestion jobs in the last 30 days';
    }

    /** @param array<mixed> $d */
    private static function ingestionText(array $d): string
    {
        $lines = [];
        if (isset($d['indexes'])) {
            foreach ((array) $d['indexes'] as $i) {
                $lines[] = '- **' . self::clean($i['index_name'] ?? '') . '**: ' . self::counts($i['jobs_by_state'] ?? null);
                foreach ((array) ($i['latest_failed_jobs'] ?? []) as $job) {
                    $lines[] = '  ' . self::jobLine((array) $job);
                }
            }
            return $lines ? implode("\n", $lines) . "\n\nJobs are kept 30 days." : 'Your account has no indexes.';
        }
        if (isset($d['job_id'])) {
            return self::jobLine($d) . (($d['result'] ?? '') !== '' ? "\n\n" . self::clean($d['result']) : '');
        }
        $lines[] = '**' . self::clean($d['index_name'] ?? '') . '**: ' . self::counts($d['jobs_by_state'] ?? null);
        foreach ((array) ($d['latest_jobs'] ?? []) as $job) {
            $lines[] = self::jobLine((array) $job);
        }
        return implode("\n", $lines) . "\n\nJobs are kept 30 days.";
    }

    /** @param array<mixed> $d */
    private static function supportText(array $d): string
    {
        $list = (array) ($d['matching'] ?? []);
        if (!$list) {
            return 'You have no support requests in the last year. Write to us on https://opensolr.com/contact or at help@opensolr.com.';
        }
        $lines = [];
        if (($d['note'] ?? '') !== '') {
            $lines[] = self::clean($d['note']);
            $lines[] = '';
        }
        foreach ($list as $r) {
            $lines[] = '- **' . self::clean($r['subject'] ?? '') . '** · opened ' . self::clean($r['opened'] ?? '') . ' · ' . self::clean($r['status'] ?? '');
            $last = (array) ($r['last_message'] ?? []);
            if ($last) {
                $lines[] = '  Last, ' . self::clean($last['date'] ?? '') . ', from ' . self::clean($last['from'] ?? '') . ': ' . mb_substr(self::clean($last['text'] ?? ''), 0, 240);
            }
        }
        $lines[] = '';
        $lines[] = 'Ask the assistant about any of them for the whole thread.';
        return implode("\n", $lines);
    }

    /** @param array<mixed> $d */
    private static function errorsText(array $d): string
    {
        $errors = (array) ($d['latest_errors'] ?? []);
        if (!$errors) {
            return 'Solr logged no errors for **' . self::clean($d['index_name'] ?? '') . '** in the last 7 days.';
        }
        $lines = ['**' . self::clean($d['index_name'] ?? '') . '**: ' . number_format((int) ($d['errors_found'] ?? 0)) . ' errors in the last 7 days, the latest:', ''];
        foreach ($errors as $e) {
            $lines[] = '- ' . self::clean($e['timestamp'] ?? '') . ' · ' . self::clean($e['level'] ?? '') . ' · ' . self::clean($e['server'] ?? '');
            $what = ($e['message'] ?? '') !== '' ? $e['message'] : ($e['user_friendly'] ?? '');
            $lines[] = '  ' . mb_substr(self::clean($what), 0, 400);
        }
        return implode("\n", $lines);
    }
}
