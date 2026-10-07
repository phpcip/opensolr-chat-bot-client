<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

final class Languages
{
    /**
     * Drupal's standard language list: code => [English name, native name].
     */
    private const STANDARD = [
        'af' => ['Afrikaans', 'Afrikaans'],
        'am' => ['Amharic', 'አማርኛ'],
        'ar' => ['Arabic', 'العربية'],
        'ast' => ['Asturian', 'Asturianu'],
        'az' => ['Azerbaijani', 'Azərbaycanca'],
        'be' => ['Belarusian', 'Беларуская'],
        'bg' => ['Bulgarian', 'Български'],
        'bn' => ['Bengali', 'বাংলা'],
        'bo' => ['Tibetan', 'བོད་སྐད་'],
        'br' => ['Breton', 'Breton'],
        'bs' => ['Bosnian', 'Bosanski'],
        'ca' => ['Catalan', 'Català'],
        'cs' => ['Czech', 'Čeština'],
        'cy' => ['Welsh', 'Cymraeg'],
        'da' => ['Danish', 'Dansk'],
        'de' => ['German', 'Deutsch'],
        'dz' => ['Dzongkha', 'རྫོང་ཁ'],
        'el' => ['Greek', 'Ελληνικά'],
        'en' => ['English', 'English'],
        'en-gb' => ['English, British', 'English, British'],
        'en-x-simple' => ['Simple English', 'Simple English'],
        'eo' => ['Esperanto', 'Esperanto'],
        'es' => ['Spanish', 'Español'],
        'et' => ['Estonian', 'Eesti'],
        'eu' => ['Basque', 'Euskera'],
        'fa' => ['Persian, Farsi', 'فارسی'],
        'fi' => ['Finnish', 'Suomi'],
        'fil' => ['Filipino', 'Filipino'],
        'fo' => ['Faeroese', 'Føroyskt'],
        'fr' => ['French', 'Français'],
        'fy' => ['Frisian, Western', 'Frysk'],
        'ga' => ['Irish', 'Gaeilge'],
        'gd' => ['Scots Gaelic', 'Gàidhlig'],
        'gl' => ['Galician', 'Galego'],
        'gsw-berne' => ['Swiss German', 'Schwyzerdütsch'],
        'gu' => ['Gujarati', 'ગુજરાતી'],
        'haw' => ['Hawaiian', 'ʻŌlelo Hawaiʻi'],
        'he' => ['Hebrew', 'עברית'],
        'hi' => ['Hindi', 'हिन्दी'],
        'hr' => ['Croatian', 'Hrvatski'],
        'ht' => ['Haitian Creole', 'Kreyòl ayisyen'],
        'hu' => ['Hungarian', 'Magyar'],
        'hy' => ['Armenian', 'Հայերեն'],
        'id' => ['Indonesian', 'Bahasa Indonesia'],
        'is' => ['Icelandic', 'Íslenska'],
        'it' => ['Italian', 'Italiano'],
        'ja' => ['Japanese', '日本語'],
        'jv' => ['Javanese', 'Basa Java'],
        'ka' => ['Georgian', 'ქართული ენა'],
        'kk' => ['Kazakh', 'Қазақ'],
        'km' => ['Khmer', 'ភាសាខ្មែរ'],
        'kn' => ['Kannada', 'ಕನ್ನಡ'],
        'ko' => ['Korean', '한국어'],
        'ku' => ['Kurdish', 'Kurdî'],
        'ky' => ['Kyrgyz', 'Кыргызча'],
        'lo' => ['Lao', 'ພາສາລາວ'],
        'lt' => ['Lithuanian', 'Lietuvių'],
        'lv' => ['Latvian', 'Latviešu'],
        'mg' => ['Malagasy', 'Malagasy'],
        'mk' => ['Macedonian', 'Македонски'],
        'ml' => ['Malayalam', 'മലയാളം'],
        'mn' => ['Mongolian', 'монгол'],
        'mr' => ['Marathi', 'मराठी'],
        'ms' => ['Malay', 'بهاس ملايو'],
        'mt' => ['Maltese', 'Malti'],
        'my' => ['Burmese', 'ဗမာစကား'],
        'ne' => ['Nepali', 'नेपाली'],
        'nl' => ['Dutch', 'Nederlands'],
        'nb' => ['Norwegian Bokmål', 'Norsk, bokmål'],
        'nn' => ['Norwegian Nynorsk', 'Norsk, nynorsk'],
        'oc' => ['Occitan', 'Occitan'],
        'or' => ['Odia', 'ଓଡିଆ'],
        'os' => ['Ossetian', 'Ossetian'],
        'pa' => ['Punjabi', 'ਪੰਜਾਬੀ'],
        'pl' => ['Polish', 'Polski'],
        'prs' => ['Persian, Afghanistan', 'دری'],
        'ps' => ['Pashto', 'پښتو'],
        'pt' => ['Portuguese, International', 'Português, Internacional'],
        'pt-pt' => ['Portuguese, Portugal', 'Português, Portugal'],
        'pt-br' => ['Portuguese, Brazil', 'Português, Brasil'],
        'rhg' => ['Rohingya', 'Ruáinga'],
        'rm-rumgr' => ['Rumantsch Grischun', 'Rumantsch Grischun'],
        'ro' => ['Romanian', 'Română'],
        'ru' => ['Russian', 'Русский'],
        'rw' => ['Kinyarwanda', 'Kinyarwanda'],
        'sco' => ['Scots', 'Scots'],
        'se' => ['Northern Sami', 'Sámi'],
        'si' => ['Sinhala', 'සිංහල'],
        'sk' => ['Slovak', 'Slovenčina'],
        'sl' => ['Slovenian', 'Slovenščina'],
        'sq' => ['Albanian', 'Shqip'],
        'sr' => ['Serbian', 'Српски'],
        'sv' => ['Swedish', 'Svenska'],
        'sw' => ['Swahili', 'Kiswahili'],
        'ta' => ['Tamil', 'தமிழ்'],
        'ta-lk' => ['Tamil, Sri Lanka', 'தமிழ், இலங்கை'],
        'te' => ['Telugu', 'తెలుగు'],
        'th' => ['Thai', 'ภาษาไทย'],
        'tr' => ['Turkish', 'Türkçe'],
        'tyv' => ['Tuvan', 'Тыва дыл'],
        'ug' => ['Uyghur', 'ئۇيغۇرچە'],
        'uk' => ['Ukrainian', 'Українська'],
        'ur' => ['Urdu', 'اردو'],
        'vi' => ['Vietnamese', 'Tiếng Việt'],
        'xx-lolspeak' => ['Lolspeak', 'Lolspeak'],
        'zh-hans' => ['Chinese, Simplified', '简体中文'],
        'zh-hant' => ['Chinese, Traditional', '繁體中文'],
    ];

    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $all = null;

    /**
     * The languages /translate knows: code => [English name, native name]. A code with a region or a script
     * (zh-hans) also gives its language alone (zh) when the list has no such code; "xx" is no language.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $languages = [];
        foreach (self::STANDARD as $code => $names) {
            $code = strtolower($code);
            if (!str_starts_with($code, 'xx-')) {
                $languages[$code] = [$names[0], $names[1]];
            }
        }
        foreach ($languages as $code => $names) {
            $base = (string) strtok($code, '-');
            if ($base !== $code && !isset($languages[$base])) {
                $languages[$base] = [trim(explode(',', $names[0])[0]), trim(explode(',', $names[1])[0])];
            }
        }
        ksort($languages);
        return self::$all = $languages;
    }
}
