<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

final class Settings
{
    public const INDEX_NAME_RE = '/^[A-Za-z0-9_]{1,50}$/';
    public const SECRETS = ['opensolr_api_key', 'captcha_secret'];
    public const INSTRUCTIONS_MAX = 4000;

    private const INTS = [
        'max_chars' => [1000, 50, 4000],
        'max_translate_chars' => [10000, 50, 20000],
        'questions_per_conversation' => [20, 1, 100],
        'questions_per_visitor' => [30, 1, 100],
        'window_seconds' => [3600, 60, 2592000],
        'pass_hours' => [240, 1, 8760],
    ];

    private const TEXTS = [
        'opensolr_email' => '',
        'opensolr_api_key' => '',
        'index_name' => '',
        'instructions' => '',
        'title' => 'Ask this site',
        'greeting' => '',
        'placeholder' => 'Ask a question…',
        'captcha_site_key' => '',
        'captcha_secret' => '',
        'launcher_text' => '',
        'accent' => '',
        'admin_language' => '',
    ];

    /** @var array<string, string|int> */
    private array $values;

    public function __construct(private readonly Store $store)
    {
        $this->values = self::defaults();
        foreach ($store->settings() as $key => $value) {
            if (isset(self::INTS[$key])) {
                [, $min, $max] = self::INTS[$key];
                $this->values[$key] = ctype_digit($value) ? max($min, min($max, (int) $value)) : self::INTS[$key][0];
            } elseif (array_key_exists($key, self::TEXTS)) {
                $this->values[$key] = $value;
            } elseif ($key === 'timezone' && self::validTimezone($value)) {
                $this->values[$key] = $value;
            }
        }
    }

    /**
     * @return array<string, string|int>
     */
    public static function defaults(): array
    {
        $values = self::TEXTS;
        foreach (self::INTS as $key => $range) {
            $values[$key] = $range[0];
        }
        $zone = date_default_timezone_get();
        $values['timezone'] = self::validTimezone($zone) ? $zone : 'UTC';
        return $values;
    }

    /**
     * The names of the number settings, in the admin's language.
     *
     * @return array<string, string>
     */
    public static function intLabels(): array
    {
        return [
            'max_chars' => I18n::t('Characters per message'),
            'max_translate_chars' => I18n::t('Characters of a text to translate'),
            'questions_per_conversation' => I18n::t('Questions per conversation'),
            'questions_per_visitor' => I18n::t('Questions per visitor'),
            'window_seconds' => I18n::t('In this many seconds'),
            'pass_hours' => I18n::t('Hours a solved captcha is valid'),
        ];
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function intRanges(): array
    {
        return self::INTS;
    }

    public function str(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? 0);
    }

    /**
     * @return array<string, string|int>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function isConfigured(): bool
    {
        return $this->str('opensolr_email') !== '' && $this->str('opensolr_api_key') !== '' && $this->str('index_name') !== '';
    }

    public function captchaRequired(): bool
    {
        return $this->str('captcha_site_key') !== '' && $this->str('captcha_secret') !== '';
    }

    public static function validTimezone(string $zone): bool
    {
        return in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The submitted settings checked: [values to save, errors]. An empty secret keeps the saved one.
     *
     * @param array<string, string> $input
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public function validate(array $input): array
    {
        $email = trim($input['opensolr_email'] ?? '');
        $key = trim($input['opensolr_api_key'] ?? '');
        $errors = self::accountErrors($email, $key);
        $values = [];
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $values['opensolr_email'] = $email;
        }
        if ($key !== '' && self::validApiKey($key)) {
            $values['opensolr_api_key'] = $key;
        }

        $texts = [
            'instructions' => [self::INSTRUCTIONS_MAX, I18n::t('The instructions')],
            'title' => [100, I18n::t('The title')],
            'greeting' => [1000, I18n::t('The greeting')],
            'placeholder' => [200, I18n::t('The placeholder')],
            'launcher_text' => [40, I18n::t('The text of the chat button')],
        ];
        foreach ($texts as $field => [$max, $label]) {
            $text = self::text($input[$field] ?? '', $field === 'instructions' || $field === 'greeting');
            if ($text === null) {
                $errors[] = I18n::t('{field} must be valid text.', ['field' => $label]);
            } elseif (mb_strlen($text) > $max) {
                $errors[] = I18n::t('{field} can have at most {max} characters.', ['field' => $label, 'max' => number_format($max)]);
            } elseif ($field === 'title' && $text === '') {
                $errors[] = I18n::t('The title is required.');
            } else {
                $values[$field] = $text;
            }
        }
        $accent = trim($input['accent'] ?? '');
        if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $errors[] = I18n::t('The accent colour must be written like #c05520.');
        } else {
            $values['accent'] = strtolower($accent);
        }
        $language = trim($input['admin_language'] ?? '');
        if ($language !== '' && !isset(I18n::LANGUAGES[$language])) {
            $errors[] = I18n::t('Choose a language from the list.');
        } else {
            $values['admin_language'] = $language;
        }

        $labels = self::intLabels();
        foreach (self::INTS as $field => [, $min, $max]) {
            $raw = trim($input[$field] ?? '');
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = I18n::t('{field} must be a whole number from {min} to {max}.', ['field' => $labels[$field], 'min' => number_format($min), 'max' => number_format($max)]);
            } else {
                $values[$field] = (string) (int) $raw;
            }
        }

        $siteKey = trim($input['captcha_site_key'] ?? '');
        if ($siteKey !== '' && !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $siteKey)) {
            $errors[] = I18n::t('The reCAPTCHA site key is not valid.');
        } else {
            $values['captcha_site_key'] = $siteKey;
        }
        $secret = trim($input['captcha_secret'] ?? '');
        if ($secret !== '') {
            if (preg_match('/^[A-Za-z0-9_-]{10,100}$/', $secret)) {
                $values['captcha_secret'] = $secret;
            } else {
                $errors[] = I18n::t('The reCAPTCHA secret key is not valid.');
            }
        }

        $zone = trim($input['timezone'] ?? '');
        if (!self::validTimezone($zone)) {
            $errors[] = I18n::t('Choose a time zone from the list.');
        } else {
            $values['timezone'] = $zone;
        }

        return [$values, $errors];
    }

    /**
     * What is wrong with an Opensolr email and API key (an empty key keeps the saved one).
     *
     * @return list<string>
     */
    public static function accountErrors(string $email, string $key): array
    {
        $errors = [];
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = I18n::t('The Opensolr email is not a valid email address.');
        }
        if ($key !== '' && !self::validApiKey($key)) {
            $errors[] = I18n::t('The Opensolr API key is not valid.');
        }
        return $errors;
    }

    private static function validApiKey(string $key): bool
    {
        return preg_match('/^[\x21-\x7E]{8,200}$/', $key) === 1;
    }

    /**
     * Valid UTF-8 text without control characters (line breaks kept where allowed), trimmed; null when invalid.
     */
    private static function text(string $value, bool $multiline): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value);
        return trim($value);
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        $known = array_flip(array_merge(array_keys(self::TEXTS), array_keys(self::INTS), ['timezone']));
        $values = array_intersect_key($values, $known);
        $this->store->saveSettings($values);
        foreach ($values as $key => $value) {
            $this->values[$key] = isset(self::INTS[$key]) ? (int) $value : $value;
        }
    }
}
