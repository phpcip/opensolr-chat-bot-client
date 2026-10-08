<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

final class Progress
{
    /**
     * What the visitor sees while the assistant works: a status, or the search or lookup that is running; '' for none.
     *
     * @param array<mixed> $event
     */
    public static function label(array $event): string
    {
        if (($event['type'] ?? '') === 'status') {
            return match ((string) (is_scalar($event['stage'] ?? null) ? $event['stage'] : '')) {
                'reading' => 'Reading your question…',
                'answering' => 'Answering…',
                default => '',
            };
        }
        $args = is_array($event['args'] ?? null) ? $event['args'] : [];
        $arg = static function (string $key) use ($args): string {
            $value = $args[$key] ?? '';
            return self::value(is_scalar($value) ? (string) $value : '');
        };
        $name = is_string($event['name'] ?? null) ? $event['name'] : '';
        $label = match ($name) {
            'opensolr_site_search' => 'Searching for: ' . $arg('query'),
            'opensolr_lexical_search' => 'Searching for the exact words: ' . $arg('query'),
            'opensolr_latest_search' => 'Searching for the latest on: ' . $arg('query'),
            'opensolr_read_document' => 'Reading the document ' . self::value(self::basename($args['document_url'] ?? '')),
            'opensolr_describe_image' => 'Looking at the picture',
            'opensolr_find_place' => 'Looking up: ' . $arg('places'),
            'opensolr_local_time' => 'Checking the local time',
            'opensolr_currency_rates' => 'Checking the exchange rates of ' . $arg('base'),
            'opensolr_vat_rates' => 'Checking the VAT rates of ' . $arg('countries'),
            'opensolr_vat_check' => 'Checking the VAT number ' . $arg('vat_numbers'),
            'opensolr_distance' => 'Measuring the distance from ' . $arg('from') . ' to ' . $arg('to'),
            'opensolr_postal_codes' => 'Looking up postal codes',
            'opensolr_ip_location' => 'Locating the IP address',
            'opensolr_analyze_text' => 'Analysing the text',
            default => null,
        };
        return $label === null ? '' : $label . '…';
    }

    /**
     * What a tool call of the assistant looked for (its words, place, document or codes), for the history; '' when
     * it names nothing.
     *
     * @param array<mixed> $event
     */
    public static function subject(array $event): string
    {
        $args = is_array($event['args'] ?? null) ? $event['args'] : [];
        $arg = static function (string $key) use ($args): string {
            $value = $args[$key] ?? '';
            return is_scalar($value) ? trim((string) $value) : '';
        };
        return match (is_string($event['name'] ?? null) ? $event['name'] : '') {
            'opensolr_site_search', 'opensolr_lexical_search', 'opensolr_latest_search' => $arg('query'),
            'opensolr_read_document' => $arg('document_url'),
            'opensolr_describe_image' => $arg('image_url'),
            'opensolr_find_place', 'opensolr_local_time' => trim($arg('places') . ' ' . $arg('coordinates')),
            'opensolr_postal_codes' => $arg('query') !== '' ? $arg('query') : trim($arg('city') . ' ' . $arg('country')),
            'opensolr_currency_rates' => trim($arg('base') . ' ' . $arg('to')),
            'opensolr_vat_rates' => $arg('countries'),
            'opensolr_vat_check' => $arg('vat_numbers'),
            'opensolr_distance' => trim($arg('from') . ' > ' . $arg('to'), ' >'),
            'opensolr_ip_location' => $arg('ips'),
            default => '',
        };
    }

    private static function basename(mixed $url): string
    {
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        return is_string($path) ? basename($path) : '';
    }

    private static function value(string $text): string
    {
        return self::escape(mb_substr(trim($text), 0, 120));
    }

    /**
     * A value written into a progress line, with the Markdown punctuation escaped.
     */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+!|~<>])/u', '\\\\$1', str_replace(["\r", "\n"], ' ', $text));
    }
}
