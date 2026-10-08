<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\Captcha;
use Opensolr\ChatBot\History;
use Opensolr\ChatBot\I18n;
use Opensolr\ChatBot\Identity;
use Opensolr\ChatBot\Logo;
use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;
use Opensolr\ChatBot\OpensolrApi;
use Opensolr\ChatBot\Settings;
use Opensolr\ChatBot\Stats;
use Opensolr\ChatBot\Store;

/**
 * /admin, /admin/login, /admin/logout, /admin/test.
 */
final class AdminController
{
    private const HEADERS = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        'X-Frame-Options' => 'DENY',
    ];
    // The sign-in page also loads reCAPTCHA
    private const LOGIN_HEADERS = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; script-src https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/; connect-src https://www.google.com/recaptcha/; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        'X-Frame-Options' => 'DENY',
    ];
    private const GATE_COOKIE = 'opensolr_chat_gate';
    private const FIELDS = [
        'opensolr_email', 'opensolr_api_key', 'index_name', 'instructions', 'title', 'greeting', 'placeholder',
        'max_chars', 'max_translate_chars', 'questions_per_conversation', 'questions_per_visitor', 'window_seconds',
        'captcha_site_key', 'captcha_secret', 'pass_hours', 'timezone', 'launcher_text', 'accent', 'admin_language',
    ];
    private const INDEX_LIST = 'index_list';
    private const INDEX_LIST_MAX = 1000;

    private string $prefix;

    public function __construct(
        private readonly Request $request,
        private readonly Store $store,
        private readonly Settings $settings,
        private readonly AdminSession $session,
    ) {
        $this->prefix = $request->prefix();
    }

    public function handle(string $route): void
    {
        $language = $this->settings->str('admin_language');
        I18n::use($language !== '' ? $language : I18n::negotiate($this->request->header('Accept-Language')));
        $hash = $this->store->adminPasswordHash();
        if ($hash === null) {
            Response::html(503, AdminView::noPassword(), self::HEADERS);
            return;
        }
        $this->store->purgeIfDue($this->settings->int('window_seconds'), $this->settings->str('timezone'));
        $post = $this->request->method() === 'POST';
        if ($post && !$this->request->sameOrigin()) {
            Response::html(403, AdminView::login($this->url('/admin/login'), $this->session->loginToken(), I18n::t('This form was not sent from this site.'), $this->captchaKey()), self::LOGIN_HEADERS);
            return;
        }
        // A captcha before any admin page loads, when the captcha is set up
        if ($route === '/admin/gate' && $post) {
            $this->passGate();
            return;
        }
        if ($this->captchaKey() !== '' && !$this->gateValid()) {
            Response::html(200, AdminView::gate($this->url('/admin/gate'), $this->captchaKey(), ''), self::LOGIN_HEADERS);
            return;
        }
        if ($route === '/admin/gate') {
            Response::redirect($this->url('/admin/login'), self::HEADERS);
            return;
        }
        if ($route === '/admin/login') {
            $post ? $this->login($hash) : $this->loginForm('');
            return;
        }
        if (!$this->session->isValid()) {
            Response::redirect($this->url('/admin/login'), self::HEADERS);
            return;
        }
        if ($post && !$this->session->checkCsrf($this->request->post('csrf', 200))) {
            $this->render([I18n::t('The form expired. Please try again.')], '', null, 400);
            return;
        }
        if ($route === '/admin/logout') {
            if ($post) {
                $this->session->destroy();
                Response::redirect($this->url('/admin/login'), self::HEADERS);
            } else {
                Response::redirect($this->url('/admin'), self::HEADERS);
            }
            return;
        }
        if ($route === '/admin/test') {
            $post ? $this->test() : Response::redirect($this->url('/admin'), self::HEADERS);
            return;
        }
        if ($route === '/admin/stats' || $route === '/admin/history') {
            if ($post) {
                Response::redirect($this->url($route), self::HEADERS);
            } elseif ($route === '/admin/stats') {
                $this->stats();
            } else {
                $this->history();
            }
            return;
        }
        if ($route === '/admin/delete') {
            $post ? $this->delete() : Response::redirect($this->url('/admin/history'), self::HEADERS);
            return;
        }
        $post ? $this->save() : $this->render([], $this->session->takeFlash(), null);
    }

    private function url(string $route): string
    {
        return $this->prefix . $route;
    }

    private function loginForm(string $error, int $status = 200): void
    {
        if ($error === '' && $this->session->isValid()) {
            Response::redirect($this->url('/admin'), self::HEADERS);
            return;
        }
        Response::html($status, AdminView::login($this->url('/admin/login'), $this->session->loginToken(), $error, $this->captchaKey()), self::LOGIN_HEADERS);
    }

    private function gateSign(int $ts): string
    {
        return hash_hmac('sha256', $ts . '|' . $this->request->clientIp() . '|' . $this->request->userAgent(), $this->store->key('admin_gate'));
    }

    private function gateValid(): bool
    {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $this->request->cookie(self::GATE_COOKIE), $m)) {
            return false;
        }
        $age = time() - (int) $m[1];
        return $age >= -60 && $age < $this->settings->int('pass_hours') * 3600 && hash_equals($this->gateSign((int) $m[1]), $m[2]);
    }

    private function passGate(): void
    {
        if ($this->captchaKey() === '') {
            Response::redirect($this->url('/admin/login'), self::HEADERS);
            return;
        }
        $reason = (new Captcha($this->settings, $this->store, '/'))->verify($this->request->post('g-recaptcha-response', 10000), $this->request);
        if ($reason !== null) {
            Response::html(400, AdminView::gate($this->url('/admin/gate'), $this->captchaKey(), $reason), self::LOGIN_HEADERS);
            return;
        }
        $ts = time();
        Response::cookie(self::GATE_COOKIE, $ts . '.' . $this->gateSign($ts), $ts + $this->settings->int('pass_hours') * 3600, $this->url('/admin'), $this->request->isHttps(), 'Lax');
        Response::redirect($this->url('/admin/login'), self::HEADERS);
    }

    /** The reCAPTCHA site key when the captcha is set up, else '' (the sign-in has no captcha before the keys are saved). */
    private function captchaKey(): string
    {
        return $this->settings->captchaRequired() ? $this->settings->str('captcha_site_key') : '';
    }

    private function login(string $hash): void
    {
        if (!$this->session->checkLoginToken($this->request->post('token', 200))) {
            $this->loginForm(I18n::t('The form expired. Please try again.'), 400);
            return;
        }
        if ($this->session->throttled()) {
            $this->loginForm(I18n::t('Too many failed sign-ins. Please try again in 15 minutes.'), 429);
            return;
        }
        if ($this->captchaKey() !== '') {
            $reason = (new Captcha($this->settings, $this->store, '/'))->verify($this->request->post('g-recaptcha-response', 10000), $this->request);
            if ($reason !== null) {
                $this->loginForm($reason, 400);
                return;
            }
        }
        $sent = $this->request->post('password', 4096);
        // Spaces around a pasted password are not part of it
        $password = trim($sent);
        if ($password === '' || !password_verify($password, $hash)) {
            error_log('Opensolr Chat Bot: a sign-in failed (' . mb_strlen($sent) . ' characters, ' . strlen($sent) . ' bytes, ' . preg_match_all('/[^\x21-\x7E]/', $password) . ' not printable ASCII' . ($sent !== $password ? ', with spaces around' : '') . ')');
            $this->session->recordFailure();
            $this->loginForm(I18n::t('Wrong password.'), 401);
            return;
        }
        $this->session->clearFailures();
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (password_needs_rehash($hash, $algo)) {
            $this->store->setMeta('admin_password_hash', password_hash($password, $algo));
        }
        $this->session->start();
        Response::redirect($this->url('/admin'), self::HEADERS);
    }

    /**
     * @return array<string, string>
     */
    private function posted(): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $this->request->post($field, 20000);
        }
        return $input;
    }

    private function save(): void
    {
        $input = $this->posted();
        [$values, $errors] = $this->settings->validate($input);
        $indexes = $this->indexes();
        $names = array_column($indexes, 'index_name');
        $index = trim($input['index_name']);
        $accountChanged = (isset($values['opensolr_email']) && $values['opensolr_email'] !== $this->settings->str('opensolr_email'))
            || (isset($values['opensolr_api_key']) && $values['opensolr_api_key'] !== $this->settings->str('opensolr_api_key'));
        if ($accountChanged) {
            $values['index_name'] = '';
        } elseif ($indexes === []) {
            $values['index_name'] = $this->settings->str('index_name');
        } elseif ($index === '' || in_array($index, $names, true)) {
            $values['index_name'] = $index;
        } else {
            $errors[] = I18n::t('Choose the index from the list.');
        }
        $logo = new Logo($this->store);
        $upload = $this->request->file('logo');
        if ($upload !== null && $upload['tmp_name'] === '') {
            $errors[] = I18n::t('The logo could not be uploaded. Please try again.');
        }
        if ($errors) {
            $this->render($errors, '', $input, 400);
            return;
        }
        if ($upload !== null) {
            $error = $logo->save($upload['tmp_name'], $upload['size']);
            if ($error !== null) {
                $this->render([$error], '', $input, 400);
                return;
            }
        } elseif ($this->request->post('logo_remove', 1) === '1') {
            $logo->remove();
        }
        $this->settings->save($values);
        $language = $this->settings->str('admin_language');
        I18n::use($language !== '' ? $language : I18n::negotiate($this->request->header('Accept-Language')));
        if ($accountChanged) {
            $this->store->deleteMeta(self::INDEX_LIST);
            $this->session->setFlash(I18n::t('Saved. The account changed: test the connection, then choose the index.'));
        } else {
            $this->session->setFlash($values['index_name'] === '' ? I18n::t('Saved. Choose the index the chat answers from.') : I18n::t('Saved.'));
        }
        Response::redirect($this->url('/admin') . '?tab=' . $this->tab(), self::HEADERS);
    }

    private function test(): void
    {
        $input = $this->posted();
        $email = trim($input['opensolr_email']);
        $key = trim($input['opensolr_api_key']);
        $errors = Settings::accountErrors($email, $key);
        $key = $key !== '' ? $key : $this->settings->str('opensolr_api_key');
        if ($email === '' || $key === '') {
            $errors[] = I18n::t('Write the email and the API key of your Opensolr account.');
        }
        if ($errors) {
            $this->render($errors, '', $input, 400);
            return;
        }
        try {
            $indexes = array_slice((new OpensolrApi($email, $key, ''))->indexList(), 0, self::INDEX_LIST_MAX);
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'ERROR_AUTHENTICATION_FAILED' => I18n::t('The email or the API key is not right.'),
                'ERROR_SCOPED_KEY_ENDPOINT_NOT_ALLOWED' => I18n::t('This API key may not list the indexes: give it the endpoint get_index_list.'),
                default => I18n::t('The connection failed: {error}', ['error' => $e->getMessage()]),
            };
            $this->render([$message], '', $input, 200);
            return;
        }
        $account = ['opensolr_email' => $email, 'opensolr_api_key' => $key];
        $names = array_column($indexes, 'index_name');
        if (!in_array($this->settings->str('index_name'), $names, true)) {
            $account['index_name'] = '';
        }
        $this->settings->save($account);
        $this->store->setMeta(self::INDEX_LIST, (string) json_encode($indexes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $notice = match (count($indexes)) {
            0 => I18n::t('The account works and is saved, but it has no index yet. Create one in your Opensolr account, then test again.'),
            1 => I18n::t('The account works and is saved: 1 index. Choose it and save the settings.'),
            default => I18n::t('The account works and is saved: {count} indexes. Choose the index and save the settings.', ['count' => count($indexes)]),
        };
        $input['index_name'] = in_array($input['index_name'], $names, true) ? $input['index_name'] : $this->settings->str('index_name');
        $this->render([], $notice, $input, 200);
    }

    /**
     * @return list<array{index_name: string, index_type: string}>
     */
    private function indexes(): array
    {
        $data = json_decode((string) $this->store->meta(self::INDEX_LIST), true);
        $indexes = [];
        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item) && is_string($item['index_name'] ?? null) && preg_match(Settings::INDEX_NAME_RE, $item['index_name'])) {
                $indexes[] = ['index_name' => $item['index_name'], 'index_type' => is_string($item['index_type'] ?? null) ? $item['index_type'] : ''];
            }
        }
        return $indexes;
    }

    /**
     * @param list<string> $errors
     * @param array<string, string>|null $input posted values shown again instead of the saved ones
     */
    private function render(array $errors, string $notice, ?array $input, int $status = 200): void
    {
        $values = $this->settings->all();
        if ($input !== null) {
            foreach ($input as $key => $value) {
                if (!in_array($key, Settings::SECRETS, true)) {
                    $values[$key] = $value;
                }
            }
        }
        Response::html($status, AdminView::settings([
            'prefix' => $this->prefix,
            'csrf' => $this->session->csrf(),
            'values' => $values,
            'api_key_mask' => self::mask($this->settings->str('opensolr_api_key')),
            'captcha_secret_mask' => self::mask($this->settings->str('captcha_secret')),
            'indexes' => $this->indexes(),
            'errors' => $errors,
            'notice' => $notice,
            'snippet' => '<script src="' . $this->prefix . '/widget.js" defer></script>',
            'snippet_ident' => '<script src="' . $this->prefix . '/widget.js" data-ident="EMAIL" data-ident-sig="SIGNATURE" defer></script>',
            'snippet_php' => '<script src="' . $this->prefix . '/widget.js"<?= \\Opensolr\\ChatBot\\Identity::attributes(' . var_export($this->store->dir(), true) . ', $email) ?> defer></script>',
            'snippet_other' => "Python:\nimport hmac, hashlib\nsignature = hmac.new(IDENTITY_KEY.encode(), email.strip().lower().encode(), hashlib.sha256).hexdigest()\n\n"
                . "Node.js:\nconst crypto = require('crypto');\nconst signature = crypto.createHmac('sha256', IDENTITY_KEY).update(email.trim().toLowerCase()).digest('hex');",
            'ident_key' => Identity::key($this->store),
            'example_sig' => Identity::sign($this->store, 'visitor@example.com'),
            'tab' => $this->tab(),
            'logo_url' => (new Logo($this->store))->url($this->prefix),
        ]), self::HEADERS);
    }

    private function stats(): void
    {
        $days = (int) $this->request->query('days', 3);
        Response::html(200, InsightsView::stats([
            'prefix' => $this->prefix,
            'csrf' => $this->session->csrf(),
            'notice' => $this->session->takeFlash(),
            'timezone' => $this->settings->str('timezone'),
            'search' => $this->store->canSearch(),
            'site' => $this->request->host(),
            'stats' => (new Stats($this->store, $this->settings->str('timezone')))->period($days),
        ]), self::HEADERS);
    }

    private function history(): void
    {
        $filters = History::filters(fn (string $name, int $max): string => $this->request->query($name, $max));
        $history = new History($this->store);
        $since = (new Stats($this->store, $this->settings->str('timezone')))->range($filters['days'])[2];
        [$rows, $next] = $history->page($filters, $since, $this->request->query('after', 40));
        $chat = ctype_digit($this->request->query('c', 19)) ? $history->conversation((int) $this->request->query('c', 19)) : null;
        $confirm = $this->request->query('confirm', 10);
        Response::html(200, InsightsView::history([
            'prefix' => $this->prefix,
            'csrf' => $this->session->csrf(),
            'notice' => $this->session->takeFlash(),
            'timezone' => $this->settings->str('timezone'),
            'search' => $this->store->canSearch(),
            'filters' => $filters,
            'after' => $this->request->query('after', 40),
            'rows' => $rows,
            'next' => $next,
            'countries' => $history->countries(),
            'chat' => $chat,
            'confirm' => in_array($confirm, ['chat', 'email', 'ip'], true) ? $confirm : '',
        ]), self::HEADERS);
    }

    /** Deletes a conversation, or everything of an email or an IP address, then back to the history. */
    private function delete(): void
    {
        $what = $this->request->post('what', 10);
        $deleted = (new History($this->store))->delete($what, $this->request->post('value', 254));
        $this->session->setFlash($deleted === 1 ? I18n::t('Deleted: 1 conversation.') : I18n::t('Deleted: {count} conversations.', ['count' => number_format($deleted)]));
        // Back to the same list, without the conversation that is gone
        parse_str($this->request->post('back', 2000), $back);
        $keep = [];
        foreach (['days', 'who', 'email', 'country', 'ip', 'page', 'flag', 'q', 'link'] as $key) {
            if (isset($back[$key]) && is_string($back[$key]) && $back[$key] !== '' && !($what === $key && $back[$key] === $this->request->post('value', 254))) {
                $keep[$key] = $back[$key];
            }
        }
        Response::redirect($this->url('/admin/history') . ($keep ? '?' . http_build_query($keep) : ''), self::HEADERS);
    }

    /** The tab the admin was on: the one posted, else the one in the address, else the first. */
    private function tab(): string
    {
        $tab = $this->request->method() === 'POST' ? $this->request->post('tab', 20) : $this->request->query('tab', 20);
        if (in_array($tab, AdminView::TABS, true)) {
            return $tab;
        }
        return $this->request->method() === 'POST' && $this->request->route() === '/admin/test' ? 'account' : AdminView::TABS[0];
    }

    private static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return str_repeat('•', 8) . (strlen($secret) > 12 ? substr($secret, -4) : '');
    }
}
