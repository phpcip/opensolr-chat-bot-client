# Translating the admin

The admin (the settings pages at `/your-path/admin`) is in English, Română, Français, Deutsch, Español, 中文 and 日本語. This page explains how to fix a translation and how to add a language.

These files translate the admin only. The texts visitors see in the chat window are not in them, and the assistant answers every visitor in the visitor's own language.

## How it works

- English is built in. Every text of the admin is written in the code as its English sentence, for example `I18n::t('Wrong password.')`.
- Every other language is one file, `lang/<code>.json`: a JSON object whose keys are the English texts and whose values are their translations.

```json
{
    "Wrong password.": "Mot de passe incorrect.",
    "The connection failed: {error}": "La connexion a échoué : {error}"
}
```

- A text with no entry in the file, or with an empty value, is shown in English. A file that is not valid JSON shows the whole admin in English.
- The admin's language is the one chosen in its Language tab. With none chosen, it is the browser's language (from the first two letters of its `Accept-Language` codes) when the admin has it, else English.

## Fix a translation

1. Open `lang/<code>.json`, for example `lang/fr.json`.
2. Change the value. Never change the key: the key must stay the English text of the code, or the entry is no longer used.
3. Check that the file is still valid JSON:

```sh
php -r 'json_decode(file_get_contents("lang/fr.json"), true, 512, JSON_THROW_ON_ERROR); echo "valid\n";'
```

4. Open the admin, choose the language in the Language tab, save, and read the page you changed.

## Rules for every entry

- **The key is the English text exactly**, character for character: capitals, punctuation, spaces. In JSON, write `"` as `\"` and `\` as `\\`. The PHP text `'The assistant\'s "now", and the dates of the commands.'` has the key:

```json
"The assistant's \"now\", and the dates of the commands."
```

- **Placeholders stay as they are.** `{field}`, `{max}`, `{min}`, `{count}`, `{error}`, `{size}` and `{side}` are replaced with values when the page is shown. Keep each one exactly as written, with its braces, untranslated. Move it to wherever your language's grammar needs it:

```json
"{field} must be a whole number from {min} to {max}.": "{field} : saisissez un nombre entier de {min} à {max}."
```

- **Code, names and values stay as they are**: `<data_dir>`, `data_dir`, `</body>`, `/translate`, `#c05520`, `reCAPTCHA`, `Opensolr`, `Opensolr Index`, `Opensolr API`, `PNG`, `JPEG`, `GIF`, `WebP`.
- **Write the text plainly.** The admin escapes every text before it shows it, so `<` and `>` appear as written and HTML in a value is shown as text, not run.
- Keep the file in UTF-8, with the keys in the same order as in the other files.

## Add a language

1. Copy an existing file, named with the new language's code, and translate every value. All the files have the same keys.

```sh
cp lang/fr.json lang/it.json
```

2. Add the code and the language's own name (written in that language) to `LANGUAGES` in `src/I18n.php`:

```php
public const LANGUAGES = [
    'en' => 'English',
    'ro' => 'Română',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'es' => 'Español',
    'zh' => '中文',
    'ja' => '日本語',
    'it' => 'Italiano',
];
```

3. Use a two-letter lowercase code (ISO 639-1), the same in the file name and in `LANGUAGES`. The browser's language is matched on its first two letters only, so a longer code could be chosen in the Language tab but never from the browser. The code is also the `lang` of the admin pages and the language of their reCAPTCHA box.
4. Check that the new file has every key of an existing one. This prints the keys missing from `lang/it.json` (an empty list when none is missing):

```sh
php -r '$a = json_decode(file_get_contents("lang/fr.json"), true); $b = json_decode(file_get_contents("lang/it.json"), true, 512, JSON_THROW_ON_ERROR); print_r(array_keys(array_diff_key($a, $b)));'
```

5. Open the admin. The new language is in the list of the Language tab.

## When the code gets a new text

The texts to translate are every text passed to `I18n::t()` in `src/`, and to `self::t()` in `src/Admin/AdminView.php`. When a new one is added to the code, add its key, with its translation, to every file in `lang/`. Until then, that text is shown in English.

## Sending a change

Send translations as a pull request to https://github.com/phpcip/opensolr-chat-bot-client, with the changed `lang/<code>.json` (and `src/I18n.php` for a new language).
