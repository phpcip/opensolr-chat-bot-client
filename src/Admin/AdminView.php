<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\I18n;
use Opensolr\ChatBot\Logo;
use Opensolr\ChatBot\Settings;

/**
 * The admin pages as plain HTML, in the admin's language; every value escaped.
 */
final class AdminView
{
    public const TABS = ['account', 'assistant', 'window', 'limits', 'captcha', 'language', 'install'];

    private const CSS = <<<'CSS'
:root{color-scheme:light}
body{margin:0;background:#f8fafc;color:#1e293b;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:1240px;margin:0 auto;padding:40px 16px 80px}
h1{font-size:26px;margin:0}
h3{font-size:16px;margin:28px 0 0;color:#0f172a}
p{margin:8px 0}
label{display:block;font-weight:600;margin:18px 0 6px}
input[type=text],input[type=email],input[type=password],input[type=number],input[type=search],input[type=file],select,textarea{box-sizing:border-box;width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:2px;background:#ffffff;color:#1e293b;font:inherit}
input:focus,select:focus,textarea:focus{outline:2px solid #c05520;outline-offset:0;border-color:#c05520}
textarea{min-height:140px;resize:vertical}
.hint{margin:6px 0 0;color:#475569;font-size:14px}
.lead{margin:20px 0 0;color:#334155}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}
@media (max-width:640px){.grid{grid-template-columns:minmax(0,1fr)}}
.actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:28px}
button{font:inherit;font-weight:600;padding:9px 18px;border:1px solid #c05520;border-radius:2px;background:#c05520;color:#ffffff;cursor:pointer}
button:hover{background:#a3461a;border-color:#a3461a}
button.plain{background:#ffffff;color:#c05520}
button.plain:hover{background:#fdf3ee}
button:focus-visible{outline:2px solid #1e293b;outline-offset:2px}
.box{margin:20px 0 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:2px;background:#ffffff}
.box.ok{border-color:#16a34a;color:#14532d}
.box.bad{border-color:#dc2626;color:#7f1d1d}
.box ul{margin:0;padding-left:20px}
.top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding-bottom:16px;border-bottom:1px solid #e2e8f0}
.brand{display:flex;flex-direction:column;gap:2px}
.brand span{color:#475569;font-size:14px}
code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px}
pre{margin:8px 0 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:2px;background:#ffffff;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.tab-r{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.tabs{display:flex;flex-wrap:wrap;margin:24px 0 0;border-bottom:1px solid #e2e8f0}
.tabs label{margin:0 0 -1px;padding:11px 16px;font-size:15px;font-weight:600;color:#475569;border:1px solid #f8fafc;border-bottom:2px solid #f8fafc;border-radius:2px 2px 0 0;cursor:pointer;white-space:nowrap}
.tabs label:hover{color:#0f172a;background:#ffffff}
.pane{display:none;padding:4px 0 0}
.card{margin-top:20px;padding:4px 20px 22px;border:1px solid #e2e8f0;border-radius:2px;background:#ffffff}
.logo-now{display:flex;align-items:center;gap:12px;margin-top:10px}
.logo-now img{max-height:40px;max-width:160px;border:1px solid #e2e8f0;border-radius:2px;background:#ffffff;padding:4px}
.check{display:flex;align-items:center;gap:8px;margin:0;font-weight:400}
.savebar{position:sticky;bottom:0;padding:14px 0;background:#f8fafc;border-top:1px solid #e2e8f0}
.tab-a{margin:0 0 -1px;padding:11px 16px;font-size:15px;font-weight:600;color:#475569;text-decoration:none;border:1px solid #f8fafc;border-bottom:2px solid #f8fafc;border-radius:2px 2px 0 0;white-space:nowrap}
.tab-a:hover{color:#0f172a;background:#ffffff}
.tab-a.on{color:#c05520;background:#ffffff;border-color:#e2e8f0;border-bottom-color:#c05520}
@media (max-width:640px){.tab-a{flex:1 1 auto;text-align:center;padding:10px 10px}}
@media (max-width:640px){.tabs label{flex:1 1 auto;text-align:center;padding:10px 10px}}
CSS;

    public static function e(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function t(string $text, array $vars = []): string
    {
        return self::e(I18n::t($text, $vars));
    }

    public static function page(string $title, string $body, string $css = ''): string
    {
        return '<!doctype html><html lang="' . self::e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::e($title) . '</title><style>' . self::CSS . $css . '</style></head>'
            . '<body><main>' . $body . '</main></body></html>';
    }

    /** The names of the settings tabs, in the admin's language. */
    public static function tabNames(): array
    {
        return [
            'account' => I18n::t('Account'),
            'assistant' => I18n::t('Assistant'),
            'window' => I18n::t('Chat window'),
            'limits' => I18n::t('Limits'),
            'captcha' => I18n::t('Captcha'),
            'language' => I18n::t('Language'),
            'install' => I18n::t('Add to your pages'),
        ];
    }

    /** The brand, the sign-out button and the messages at the top of every admin page. */
    public static function top(string $prefix, string $csrf, string $notice, array $errors): string
    {
        $body = '<div class="top"><div class="brand"><h1>Opensolr Chat Bot</h1><span>' . self::t('The chatbot of this site: it answers from your Opensolr Index, through the Opensolr API.') . '</span></div>'
            . '<form method="post" action="' . self::e($prefix . '/admin/logout') . '">'
            . '<input type="hidden" name="csrf" value="' . self::e($csrf) . '">'
            . '<button type="submit" class="plain">' . self::t('Sign out') . '</button></form></div>';
        if ($notice !== '') {
            $body .= '<div class="box ok" role="status">' . self::e($notice) . '</div>';
        }
        if ($errors) {
            $body .= '<div class="box bad" role="alert"><ul>';
            foreach ($errors as $error) {
                $body .= '<li>' . self::e($error) . '</li>';
            }
            $body .= '</ul></div>';
        }
        return $body;
    }

    /** The links to the stats and the history, before the settings tabs. */
    public static function insightLinks(string $prefix, string $active): string
    {
        $out = '';
        foreach (['stats' => I18n::t('Stats'), 'history' => I18n::t('History')] as $route => $name) {
            $out .= '<a class="tab-a' . ($route === $active ? ' on' : '') . '" href="' . self::e($prefix . '/admin/' . $route) . '"' . ($route === $active ? ' aria-current="page"' : '') . '>' . self::e($name) . '</a>';
        }
        return $out;
    }

    /** The whole tab bar of the stats and the history pages: the settings tabs are links there. */
    public static function nav(string $prefix, string $active): string
    {
        $out = '<nav class="tabs">' . self::insightLinks($prefix, $active);
        foreach (self::tabNames() as $tab => $name) {
            $out .= '<a class="tab-a" href="' . self::e($prefix . '/admin?tab=' . $tab) . '">' . self::e($name) . '</a>';
        }
        return $out . '</nav>';
    }

    private static function recaptcha(): string
    {
        return '<script src="https://www.google.com/recaptcha/api.js?hl=' . self::e(I18n::lang()) . '" async defer></script>';
    }

    private static function error(string $error): string
    {
        return $error !== '' ? '<div class="box bad" role="alert">' . self::e($error) . '</div>' : '';
    }

    public static function noPassword(): string
    {
        return self::page('Opensolr Chat Bot', '<h1>Opensolr Chat Bot</h1>'
            . '<div class="box bad"><p>' . self::t('The admin has no password yet, so it does not open. On the server, in your project folder, run:') . '</p>'
            . '<pre>php vendor/bin/opensolr-chat-bot password &lt;data_dir&gt;</pre>'
            . '<p class="hint">' . self::t('<data_dir> is the data_dir of the front controller of this chat. Then reload this page.') . '</p></div>');
    }

    public static function gate(string $action, string $captchaKey, string $error): string
    {
        $body = '<h1>Opensolr Chat Bot</h1><p class="lead">' . self::t('Confirm that you are a person to open the admin.') . '</p>' . self::error($error)
            . '<form method="post" action="' . self::e($action) . '">'
            . '<div class="g-recaptcha" data-sitekey="' . self::e($captchaKey) . '" style="margin-top:20px"></div>'
            . '<div class="actions"><button type="submit">' . self::t('Continue') . '</button></div></form>'
            . self::recaptcha();
        return self::page('Opensolr Chat Bot', $body);
    }

    public static function login(string $action, string $token, string $error, string $captchaKey = ''): string
    {
        $body = '<h1>Opensolr Chat Bot</h1><p class="lead">' . self::t('Sign in to the admin.') . '</p>' . self::error($error)
            . '<form method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="token" value="' . self::e($token) . '">'
            . '<label for="password">' . self::t('Password') . '</label>'
            . '<input type="password" id="password" name="password" autocomplete="current-password" required autofocus>'
            . ($captchaKey !== '' ? '<div class="g-recaptcha" data-sitekey="' . self::e($captchaKey) . '" style="margin-top:16px"></div>' : '')
            . '<div class="actions"><button type="submit">' . self::t('Sign in') . '</button></div></form>'
            . ($captchaKey !== '' ? self::recaptcha() : '');
        return self::page(I18n::t('Sign in') . ' · Opensolr Chat Bot', $body);
    }

    /**
     * @param array{
     *     prefix: string, csrf: string, values: array<string, string|int>, api_key_mask: string,
     *     captcha_secret_mask: string, indexes: list<array{index_name: string, index_type: string}>,
     *     errors: list<string>, notice: string, snippet: string, snippet_ident: string, snippet_php: string,
     *     snippet_other: string, ident_key: string, example_sig: string, tab: string, logo_url: string
     * } $p
     */
    public static function settings(array $p): string
    {
        $v = $p['values'];
        $field = static fn (string $name): string => self::e((string) ($v[$name] ?? ''));
        $tab = in_array($p['tab'], self::TABS, true) ? $p['tab'] : 'account';
        $names = self::tabNames();

        $css = '';
        foreach (self::TABS as $t) {
            $css .= '#tab-' . $t . ':checked~.tabs label[for=tab-' . $t . ']{color:#c05520;background:#ffffff;border-color:#e2e8f0;border-bottom-color:#c05520}'
                . '#tab-' . $t . ':checked~.panes .pane-' . $t . '{display:block}'
                . '#tab-' . $t . ':focus-visible~.tabs label[for=tab-' . $t . ']{outline:2px solid #1e293b;outline-offset:-2px}';
        }
        $css .= '#tab-install:checked~.savebar{display:none}';

        $body = self::top($p['prefix'], $p['csrf'], $p['notice'], $p['errors']);

        $body .= '<form method="post" action="' . self::e($p['prefix'] . '/admin') . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . self::e($p['csrf']) . '">';
        foreach (self::TABS as $t) {
            $body .= '<input type="radio" class="tab-r" name="tab" id="tab-' . $t . '" value="' . $t . '"' . ($t === $tab ? ' checked' : '') . '>';
        }
        $body .= '<div class="tabs">' . self::insightLinks($p['prefix'], '');
        foreach (self::TABS as $t) {
            $body .= '<label for="tab-' . $t . '">' . self::e($names[$t]) . '</label>';
        }
        $body .= '</div><div class="panes">';

        // Account
        $body .= '<section class="pane pane-account"><div class="card">'
            . '<div class="grid"><div><label for="opensolr_email">' . self::t('Email') . '</label>'
            . '<input type="email" id="opensolr_email" name="opensolr_email" maxlength="254" autocomplete="off" value="' . $field('opensolr_email') . '"></div>'
            . '<div><label for="opensolr_api_key">' . self::t('API key') . '</label>'
            . '<input type="password" id="opensolr_api_key" name="opensolr_api_key" maxlength="200" autocomplete="new-password" placeholder="' . self::e($p['api_key_mask']) . '">'
            . '<p class="hint">' . ($p['api_key_mask'] !== '' ? self::t('Saved. Leave empty to keep it.') : self::t('Not saved yet.')) . '</p></div></div>'
            . '<div class="actions"><button type="submit" class="plain" formnovalidate formaction="' . self::e($p['prefix'] . '/admin/test') . '">' . self::t('Test connection') . '</button>'
            . '<span class="hint">' . self::t('Tests the account, saves it when it works and lists its indexes below.') . '</span></div>'
            . '<label for="index_name">' . self::t('Opensolr Index') . '</label>';
        if ($p['indexes']) {
            $body .= '<select id="index_name" name="index_name"><option value="">' . self::t('Choose the index') . '</option>';
            foreach ($p['indexes'] as $index) {
                $selected = (string) ($v['index_name'] ?? '') === $index['index_name'] ? ' selected' : '';
                $body .= '<option value="' . self::e($index['index_name']) . '"' . $selected . '>' . self::e($index['index_name']) . '</option>';
            }
            $body .= '</select><p class="hint">' . self::t('The index the chat answers from.') . '</p>';
        } else {
            $body .= '<select id="index_name" name="index_name" disabled><option value="">' . self::t('Test the connection to list your indexes') . '</option></select>';
        }
        $body .= '</div></section>';

        // Assistant
        $body .= '<section class="pane pane-assistant"><div class="card">'
            . '<label for="instructions">' . self::t('Instructions') . '</label>'
            . '<textarea id="instructions" name="instructions" maxlength="' . Settings::INSTRUCTIONS_MAX . '">' . $field('instructions') . '</textarea>'
            . '<p class="hint">' . self::t('Optional: your own instructions for the assistant (its tone, what to recommend, what to avoid). They are added after its built-in rules: it searches this site with the Opensolr search tools, answers only from what they find, links every page, product and PDF page it mentions and looks up facts with the other Opensolr tools. At most {max} characters.', ['max' => number_format(Settings::INSTRUCTIONS_MAX)]) . '</p>'
            . '<label for="timezone">' . self::t('Time zone') . '</label><select id="timezone" name="timezone">';
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $body .= '<option value="' . self::e($zone) . '"' . ((string) ($v['timezone'] ?? '') === $zone ? ' selected' : '') . '>' . self::e($zone) . '</option>';
        }
        $body .= '</select><p class="hint">' . self::t('The assistant\'s "now", and the dates of the commands.') . '</p></div></section>';

        // Chat window
        $body .= '<section class="pane pane-window"><div class="card">'
            . '<div class="grid"><div><label for="title">' . self::t('Title') . '</label><input type="text" id="title" name="title" maxlength="100" required value="' . $field('title') . '">'
            . '<p class="hint">' . self::t('At the top of the chat window.') . '</p></div>'
            . '<div><label for="launcher_text">' . self::t('Text of the chat button') . '</label><input type="text" id="launcher_text" name="launcher_text" maxlength="40" value="' . $field('launcher_text') . '">'
            . '<p class="hint">' . self::t('Optional: shown next to the icon of the button that opens the chat. Empty: the icon only.') . '</p></div></div>'
            . '<label for="greeting">' . self::t('Greeting') . '</label><textarea id="greeting" name="greeting" maxlength="1000">' . $field('greeting') . '</textarea>'
            . '<p class="hint">' . self::t('Optional: the first message the visitor sees.') . '</p>'
            . '<label for="placeholder">' . self::t('Placeholder') . '</label><input type="text" id="placeholder" name="placeholder" maxlength="200" value="' . $field('placeholder') . '">'
            . '<p class="hint">' . self::t('The grey text in the empty message box.') . '</p>'
            . '<div class="grid"><div><label for="logo">' . self::t('Logo') . '</label><input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">'
            . '<p class="hint">' . self::t('PNG, JPEG, GIF or WebP, at most {size}. Shown on the chat button and in the header of the chat.', ['size' => '1 MB']) . '</p>';
        if ($p['logo_url'] !== '') {
            $body .= '<div class="logo-now"><img src="' . self::e($p['logo_url']) . '" alt="' . self::t('The logo now') . '">'
                . '<label class="check"><input type="checkbox" name="logo_remove" value="1"> ' . self::t('Remove the logo') . '</label></div>';
        }
        $body .= '</div><div><label for="accent">' . self::t('Accent colour') . '</label><input type="text" id="accent" name="accent" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#c05520" value="' . $field('accent') . '">'
            . '<p class="hint">' . self::t('The colour of the chat button, the header and the links, written like #c05520. Empty: #c05520.') . '</p></div></div>'
            . '</div></section>';

        // Limits
        $ranges = Settings::intRanges();
        $labels = Settings::intLabels();
        $number = static function (string $name, string $hint) use ($ranges, $labels, $field): string {
            [, $min, $max] = $ranges[$name];
            return '<div><label for="' . $name . '">' . self::e($labels[$name]) . '</label>'
                . '<input type="number" id="' . $name . '" name="' . $name . '" min="' . $min . '" max="' . $max . '" step="1" required value="' . $field($name) . '">'
                . ($hint !== '' ? '<p class="hint">' . self::e($hint) . '</p>' : '') . '</div>';
        };
        $body .= '<section class="pane pane-limits"><div class="card">'
            . '<h3>' . self::t('Messages') . '</h3><div class="grid">'
            . $number('max_chars', I18n::t('The longest question.'))
            . $number('max_translate_chars', I18n::t('The longest text the /translate command takes.'))
            . '</div>'
            . '<h3>' . self::t('Each visitor') . '</h3><div class="grid">'
            . $number('questions_per_visitor', I18n::t('Counted per IP address, commands included.'))
            . $number('window_seconds', I18n::t('The time those questions are counted in: 3600 is an hour. Then the visitor waits.'))
            . '</div>'
            . '<h3>' . self::t('Each conversation') . '</h3><div class="grid">'
            . $number('questions_per_conversation', I18n::t('Then the visitor is asked to start a new chat.'))
            . '<div></div></div>'
            . '</div></section>';

        // Captcha
        $body .= '<section class="pane pane-captcha"><div class="card">'
            . '<p class="lead">' . self::t('reCAPTCHA v2, the checkbox. With both keys set, a visitor solves the captcha before the first question, and the admin asks for it before any page.') . '</p>'
            . '<div class="grid"><div><label for="captcha_site_key">' . self::t('Site key') . '</label>'
            . '<input type="text" id="captcha_site_key" name="captcha_site_key" maxlength="100" autocomplete="off" value="' . $field('captcha_site_key') . '">'
            . '<p class="hint">' . self::t('Empty: no captcha.') . '</p></div>'
            . '<div><label for="captcha_secret">' . self::t('Secret key') . '</label>'
            . '<input type="password" id="captcha_secret" name="captcha_secret" maxlength="100" autocomplete="new-password" placeholder="' . self::e($p['captcha_secret_mask']) . '">'
            . '<p class="hint">' . ($p['captcha_secret_mask'] !== '' ? self::t('Saved. Leave empty to keep it.') : self::t('Not saved yet.')) . '</p></div></div>'
            . '<div class="grid">' . $number('pass_hours', I18n::t('After that the captcha is asked again.')) . '<div></div></div>'
            . '</div></section>';

        // Language
        $body .= '<section class="pane pane-language"><div class="card">'
            . '<label for="admin_language">' . self::t('Language of this admin') . '</label><select id="admin_language" name="admin_language">'
            . '<option value="">' . self::t('The language of the browser') . '</option>';
        foreach (I18n::LANGUAGES as $code => $name) {
            $body .= '<option value="' . self::e($code) . '"' . ((string) ($v['admin_language'] ?? '') === $code ? ' selected' : '') . '>' . self::e($name) . '</option>';
        }
        $body .= '</select><p class="hint">' . self::t('The chat itself answers every visitor in the visitor\'s own language.') . '</p></div></section>';

        // Add to your pages
        $body .= '<section class="pane pane-install"><div class="card">'
            . '<p class="lead">' . self::t('Paste this before </body> on every page that shows the chat:') . '</p>'
            . '<pre>' . self::e($p['snippet']) . '</pre>'
            . '<h3>' . self::t('Signed-in visitors') . '</h3>'
            . '<p>' . self::t('When your site has accounts, the chat can know who asked: the Stats and the History then show the email of a signed-in visitor instead of Anonymous. On the pages of a signed-in visitor, the tag carries the email and its signature:') . '</p>'
            . '<pre>' . self::e($p['snippet_ident']) . '</pre>'
            . '<p>' . self::t('EMAIL is the visitor\'s email, written for an HTML attribute. SIGNATURE is HMAC-SHA256 of that email in lower case, with the identity key of this chat, written in hex (64 characters). Your pages make it on your server, in any language. A visitor who is not signed in gets the first tag of this tab, without the two attributes. Without a valid signature the visitor is anonymous, so nobody can pass for somebody else.') . '</p>'
            . '<p><strong>' . self::t('The identity key:') . '</strong></p>'
            . '<pre>' . self::e($p['ident_key']) . '</pre>'
            . '<p class="hint">' . self::t('Keep it on your server, never in a page. It changes only if the signing secret of this chat is replaced.') . '</p>'
            . '<p><strong>' . self::t('Pages made in PHP:') . '</strong> ' . self::t('the package writes both attributes, signed, and nothing when $email is empty.') . '</p>'
            . '<pre>' . self::e($p['snippet_php']) . '</pre>'
            . '<p><strong>' . self::t('Pages made in another language:') . '</strong> ' . self::t('make the signature with the identity key, for example:') . '</p>'
            . '<pre>' . self::e($p['snippet_other']) . '</pre>'
            . '<p class="hint">' . self::t('To check your code: for visitor@example.com it must give') . ' <code>' . self::e($p['example_sig']) . '</code>. '
            . self::t('Every language, step by step:') . ' <a href="https://opensolr.com/opensolr-chat-bot-docs/signed-in-visitors" target="_blank" rel="noopener">https://opensolr.com/opensolr-chat-bot-docs/signed-in-visitors</a></p></div></section>';

        $body .= '</div><div class="actions savebar"><button type="submit">' . self::t('Save settings') . '</button></div></form>'
            . '<script src="' . self::e($p['prefix'] . '/admin.js') . '" defer></script>';

        return self::page('Opensolr Chat Bot', $body, $css);
    }
}
