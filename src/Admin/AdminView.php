<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\Settings;

/**
 * The admin pages as plain HTML; every value escaped.
 */
final class AdminView
{
    private const CSS = <<<'CSS'
:root{color-scheme:light}
body{margin:0;background:#f8fafc;color:#1e293b;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:880px;margin:0 auto;padding:40px 16px 80px}
h1{font-size:26px;margin:0 0 8px}
h2{font-size:19px;margin:36px 0 4px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}
p{margin:8px 0}
label{display:block;font-weight:600;margin:18px 0 6px}
input[type=text],input[type=email],input[type=password],input[type=number],select,textarea{box-sizing:border-box;width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:2px;background:#fff;color:#1e293b;font:inherit}
input:focus,select:focus,textarea:focus{outline:2px solid #c05520;outline-offset:0;border-color:#c05520}
textarea{min-height:140px;resize:vertical}
.hint{margin:6px 0 0;color:#475569;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}
@media (max-width:640px){.grid{grid-template-columns:minmax(0,1fr)}}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:28px}
button{font:inherit;font-weight:600;padding:9px 18px;border:1px solid #c05520;border-radius:2px;background:#c05520;color:#fff;cursor:pointer}
button.plain{background:#fff;color:#c05520}
button:focus{outline:2px solid #1e293b;outline-offset:2px}
.box{margin:20px 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:2px;background:#fff}
.box.ok{border-color:#16a34a;color:#14532d}
.box.bad{border-color:#dc2626;color:#7f1d1d}
.box ul{margin:0;padding-left:20px}
.top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px}
code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px}
pre{margin:8px 0 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:2px;background:#fff;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
CSS;

    public static function e(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::e($title) . '</title><style>' . self::CSS . '</style></head>'
            . '<body><main>' . $body . '</main></body></html>';
    }

    public static function noPassword(): string
    {
        return self::page('Opensolr Chat Bot', '<h1>Opensolr Chat Bot</h1>'
            . '<div class="box bad"><p>The admin has no password yet, so it does not open. On the server, in your project folder, run:</p>'
            . '<pre>php vendor/bin/opensolr-chat-bot password &lt;data_dir&gt;</pre>'
            . '<p class="hint">&lt;data_dir&gt; is the data_dir of the front controller of this chat. Then reload this page.</p></div>');
    }

    public static function login(string $action, string $token, string $error, string $captchaKey = ''): string
    {
        $body = '<h1>Opensolr Chat Bot</h1><p>Sign in to the admin.</p>';
        if ($error !== '') {
            $body .= '<div class="box bad" role="alert">' . self::e($error) . '</div>';
        }
        $body .= '<form method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="token" value="' . self::e($token) . '">'
            . '<label for="password">Password</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password" required autofocus>'
            . ($captchaKey !== '' ? '<div class="g-recaptcha" data-sitekey="' . self::e($captchaKey) . '" style="margin-top:16px"></div>' : '')
            . '<div class="actions"><button type="submit">Sign in</button></div></form>'
            . ($captchaKey !== '' ? '<script src="https://www.google.com/recaptcha/api.js" async defer></script>' : '');
        return self::page('Sign in · Opensolr Chat Bot', $body);
    }

    /**
     * @param array{
     *     prefix: string, csrf: string, values: array<string, string|int>, api_key_mask: string,
     *     captcha_secret_mask: string, indexes: list<array{index_name: string, index_type: string}>,
     *     errors: list<string>, notice: string, snippet: string
     * } $p
     */
    public static function settings(array $p): string
    {
        $v = $p['values'];
        $field = static fn (string $name): string => self::e((string) ($v[$name] ?? ''));
        $body = '<div class="top"><h1>Opensolr Chat Bot</h1>'
            . '<form method="post" action="' . self::e($p['prefix'] . '/admin/logout') . '">'
            . '<input type="hidden" name="csrf" value="' . self::e($p['csrf']) . '">'
            . '<button type="submit" class="plain">Sign out</button></form></div>'
            . '<p>The chatbot of this site: it answers from your Opensolr Index, through the Opensolr API.</p>';
        if ($p['notice'] !== '') {
            $body .= '<div class="box ok" role="status">' . self::e($p['notice']) . '</div>';
        }
        if ($p['errors']) {
            $body .= '<div class="box bad" role="alert"><ul>';
            foreach ($p['errors'] as $error) {
                $body .= '<li>' . self::e($error) . '</li>';
            }
            $body .= '</ul></div>';
        }

        $body .= '<form method="post" action="' . self::e($p['prefix'] . '/admin') . '">'
            . '<input type="hidden" name="csrf" value="' . self::e($p['csrf']) . '">';

        $body .= '<h2>Opensolr account</h2>'
            . '<div class="grid"><div><label for="opensolr_email">Email</label>'
            . '<input type="email" id="opensolr_email" name="opensolr_email" maxlength="254" autocomplete="off" value="' . $field('opensolr_email') . '"></div>'
            . '<div><label for="opensolr_api_key">API key</label>'
            . '<input type="password" id="opensolr_api_key" name="opensolr_api_key" maxlength="200" autocomplete="new-password" placeholder="' . self::e($p['api_key_mask']) . '">'
            . '<p class="hint">' . ($p['api_key_mask'] !== '' ? 'Saved. Leave empty to keep it.' : 'Not saved yet.') . '</p></div></div>'
            . '<div class="actions"><button type="submit" class="plain" formnovalidate formaction="' . self::e($p['prefix'] . '/admin/test') . '">Test connection</button></div>'
            . '<p class="hint">Tests the account, saves it when it works and lists its indexes below.</p>';

        $body .= '<label for="index_name">Opensolr Index</label>';
        if ($p['indexes']) {
            $body .= '<select id="index_name" name="index_name"><option value="">Choose the index</option>';
            foreach ($p['indexes'] as $index) {
                $selected = (string) ($v['index_name'] ?? '') === $index['index_name'] ? ' selected' : '';
                $label = $index['index_name'] . ($index['index_type'] !== '' ? ' (' . $index['index_type'] . ')' : '');
                $body .= '<option value="' . self::e($index['index_name']) . '"' . $selected . '>' . self::e($label) . '</option>';
            }
            $body .= '</select><p class="hint">The index the chat answers from.</p>';
        } else {
            $body .= '<select id="index_name" name="index_name" disabled><option value="">Test the connection to list your indexes</option></select>';
        }

        $body .= '<h2>Assistant</h2>'
            . '<label for="instructions">Instructions</label>'
            . '<textarea id="instructions" name="instructions" maxlength="' . Settings::INSTRUCTIONS_MAX . '">' . $field('instructions') . '</textarea>'
            . '<p class="hint">Optional: your own instructions for the assistant (its tone, what to recommend, what to avoid). They are added after its built-in rules: it searches this site with the Opensolr search tools, answers only from what they find, links every page, product and PDF page it mentions and looks up facts with the other Opensolr tools. At most ' . number_format(Settings::INSTRUCTIONS_MAX) . ' characters.</p>'
            . '<label for="timezone">Time zone</label><select id="timezone" name="timezone">';
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $body .= '<option value="' . self::e($zone) . '"' . ((string) ($v['timezone'] ?? '') === $zone ? ' selected' : '') . '>' . self::e($zone) . '</option>';
        }
        $body .= '</select><p class="hint">The assistant\'s "now", and the dates of the commands.</p>';

        $body .= '<h2>Chat window</h2>'
            . '<label for="title">Title</label><input type="text" id="title" name="title" maxlength="100" required value="' . $field('title') . '">'
            . '<label for="greeting">Greeting</label><textarea id="greeting" name="greeting" maxlength="1000">' . $field('greeting') . '</textarea>'
            . '<p class="hint">Optional: the first message the visitor sees.</p>'
            . '<label for="placeholder">Placeholder</label><input type="text" id="placeholder" name="placeholder" maxlength="200" value="' . $field('placeholder') . '">';

        $ranges = Settings::intRanges();
        $number = static function (string $name, string $label, string $hint) use ($ranges, $field): string {
            [, $min, $max] = $ranges[$name];
            return '<div><label for="' . $name . '">' . self::e($label) . '</label>'
                . '<input type="number" id="' . $name . '" name="' . $name . '" min="' . $min . '" max="' . $max . '" step="1" required value="' . $field($name) . '">'
                . ($hint !== '' ? '<p class="hint">' . self::e($hint) . '</p>' : '') . '</div>';
        };
        $body .= '<h2>Limits</h2><div class="grid">'
            . $number('max_chars', 'Characters per message', '')
            . $number('max_translate_chars', 'Characters of a text to translate', 'The longest text the /translate command takes.')
            . $number('questions_per_conversation', 'Questions per conversation', 'Then the visitor is asked to start a new chat.')
            . $number('questions_per_visitor', 'Questions per visitor', 'Counted per IP address, commands included.')
            . $number('window_seconds', 'In this many seconds', '')
            . $number('pass_hours', 'Hours a solved captcha is valid', '')
            . '</div>';

        $body .= '<h2>Captcha (reCAPTCHA v2, checkbox)</h2>'
            . '<div class="grid"><div><label for="captcha_site_key">Site key</label>'
            . '<input type="text" id="captcha_site_key" name="captcha_site_key" maxlength="100" autocomplete="off" value="' . $field('captcha_site_key') . '">'
            . '<p class="hint">Empty: no captcha.</p></div>'
            . '<div><label for="captcha_secret">Secret key</label>'
            . '<input type="password" id="captcha_secret" name="captcha_secret" maxlength="100" autocomplete="new-password" placeholder="' . self::e($p['captcha_secret_mask']) . '">'
            . '<p class="hint">' . ($p['captcha_secret_mask'] !== '' ? 'Saved. Leave empty to keep it.' : 'Not saved yet.') . '</p></div></div>'
            . '<p class="hint">With both keys set, a visitor solves the captcha once, then chats for the hours set above.</p>';

        $body .= '<div class="actions"><button type="submit">Save settings</button></div></form>';

        $body .= '<h2>Add the chat to your pages</h2>'
            . '<p>Paste this before <code>&lt;/body&gt;</code> on every page that shows the chat:</p>'
            . '<pre>' . self::e($p['snippet']) . '</pre>';

        return self::page('Opensolr Chat Bot', $body);
    }
}
