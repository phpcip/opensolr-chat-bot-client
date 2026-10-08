<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The language of the admin: every text is its English sentence, translated by lang/<code>.json when one exists.
 */
final class I18n
{
    public const LANGUAGES = [
        'en' => 'English',
        'ro' => 'Română',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'zh' => '中文',
        'ja' => '日本語',
    ];

    private static string $lang = 'en';
    /** @var array<string, string> */
    private static array $texts = [];

    public static function use(string $lang): void
    {
        self::$lang = isset(self::LANGUAGES[$lang]) ? $lang : 'en';
        self::$texts = [];
        $file = dirname(__DIR__) . '/lang/' . self::$lang . '.json';
        if (self::$lang === 'en' || !is_file($file)) {
            return;
        }
        $data = json_decode((string) file_get_contents($file), true);
        foreach (is_array($data) ? $data : [] as $english => $text) {
            if (is_string($english) && is_string($text) && $text !== '') {
                self::$texts[$english] = $text;
            }
        }
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    /**
     * The language of an Accept-Language header this admin speaks, else English.
     */
    public static function negotiate(string $header): string
    {
        $best = 'en';
        $weight = 0.0;
        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part), 2);
            $code = strtolower(substr(trim($pieces[0]), 0, 2));
            $q = isset($pieces[1]) && preg_match('/q\s*=\s*([0-9.]+)/', $pieces[1], $m) ? (float) $m[1] : 1.0;
            if (isset(self::LANGUAGES[$code]) && $q > $weight) {
                $best = $code;
                $weight = $q;
            }
        }
        return $best;
    }

    /**
     * @param array<string, string|int> $vars values of the {name} placeholders
     */
    public static function t(string $text, array $vars = []): string
    {
        $out = self::$texts[$text] ?? $text;
        foreach ($vars as $name => $value) {
            $out = str_replace('{' . $name . '}', (string) $value, $out);
        }
        return $out;
    }
}
