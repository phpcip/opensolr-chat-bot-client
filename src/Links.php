<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The links of an answer, written the way the browser writes them (a link the visitor clicks matches its own).
 */
final class Links
{
    private const URL_MAX = 2048;
    private const FIND = '~https?://[^\s<>"\'`\[\]{}|\\\\^]+~i';

    /**
     * The http(s) links of a text (Markdown links, <links> and bare links), each once, in order.
     *
     * @return list<string>
     */
    public static function extract(string $text, int $max = 50): array
    {
        if (!preg_match_all(self::FIND, $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $raw) {
            $url = self::normalize(self::trimEnd($raw));
            if ($url !== null && !isset($out[$url])) {
                $out[$url] = true;
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Punctuation after a link and the closing parenthesis of a Markdown link are not part of it.
     */
    private static function trimEnd(string $url): string
    {
        while ($url !== '') {
            $last = substr($url, -1);
            if (strpbrk($last, '.,;:!?*_\'"') !== false) {
                $url = substr($url, 0, -1);
                continue;
            }
            if ($last === ')' && substr_count($url, ')') > substr_count($url, '(')) {
                $url = substr($url, 0, -1);
                continue;
            }
            break;
        }
        return $url;
    }

    /**
     * An http(s) URL as the browser writes it (lower-case scheme and host, no default port, a path, no fragment,
     * the characters outside printable ASCII percent-encoded); null when it is not one.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::URL_MAX) {
            return null;
        }
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        if (!is_array($p) || ($scheme !== 'http' && $scheme !== 'https') || $host === '' || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $port = isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443)) ? ':' . $p['port'] : '';
        $path = (string) ($p['path'] ?? '');
        $out = $scheme . '://' . $host . $port . ($path === '' ? '/' : $path) . (isset($p['query']) ? '?' . $p['query'] : '');
        return (string) preg_replace_callback('/[^\x21-\x7E]/', static fn (array $c): string => rawurlencode($c[0]), $out);
    }

    /** The host of the site without "www.", the base its own links are recognized by. */
    public static function siteBase(string $host): string
    {
        $host = strtolower(trim($host));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** The link goes to the site: its host is the site's, or one of its subdomains. */
    public static function onSite(string $url, string $base): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $base !== '' && $host !== '' && ($host === $base || str_ends_with($host, '.' . $base));
    }

    /**
     * The page a question is asked from: the URL of this site without its query and fragment, '' when it is not one.
     */
    public static function page(string $url, string $host): string
    {
        $url = self::normalize($url);
        if ($url === null || strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower($host)) {
            return '';
        }
        $cut = strcspn($url, '?');
        return substr(substr($url, 0, $cut), 0, 500);
    }
}
