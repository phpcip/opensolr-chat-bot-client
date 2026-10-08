<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\EventStream;
use Opensolr\ChatBot\Http\Request;

/**
 * POST /chat: every check before anything costs anything, then a command, a translation or the assistant's answer.
 */
final class ChatController
{
    private const BODY_MAX = 1048576;
    private const MESSAGES_MAX = 21;
    private const MESSAGE_CHARS = 8000;
    private const HISTORY_SENT = 10;
    private const CONVERSATION_RE = '/^[A-Za-z0-9_-]{8,64}$/';
    private const UNREADABLE = 'The question could not be read. Please try again.';

    private ?string $lockKey = null;
    private ?string $lockToken = null;
    private ?Limits $limits = null;
    private ?Journal $journal = null;
    private float $started = 0.0;
    /** @var array{key: string, ip: string, email: string, page: string, lang: string, site: string, question: string}|null */
    private ?array $meta = null;
    private bool $recorded = false;

    public function __construct(
        private readonly Request $request,
        private readonly Store $store,
        private readonly Settings $settings,
        private readonly Captcha $captcha,
    ) {
    }

    public function handle(EventStream $out): void
    {
        $this->started = microtime(true);
        $out->open();
        try {
            $this->respond($out);
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: ' . $e->getMessage());
            $out->error(Assistant::FAILED);
        } finally {
            $this->releaseLock();
        }
        // After the answer was sent: the visitor's country and city, looked up once per IP address
        if ($this->recorded) {
            $out->finish();
            try {
                $this->journal()->locate($this->request->clientIp(), $this->api());
            } catch (\Throwable $e) {
                error_log('Opensolr Chat Bot: the place of a visitor could not be kept: ' . $e->getMessage());
            }
        }
    }

    private function respond(EventStream $out): void
    {
        if ($this->request->method() !== 'POST' || !$this->request->isJson()) {
            $out->error(self::UNREADABLE);
            return;
        }
        if (!$this->request->sameOrigin()) {
            $out->error('This chat answers only on its own site.');
            return;
        }
        if (!$this->settings->isConfigured()) {
            $out->error('This chat is not set up yet.');
            return;
        }
        if ($this->captcha->required() && !$this->captcha->hasValidPass($this->request)) {
            $out->captcha();
            return;
        }

        $input = $this->read();
        if (is_string($input)) {
            $out->error($input);
            return;
        }
        [$conversation, $prior, $question, $who] = $input;
        $ip = $this->request->clientIp();
        $this->meta = [
            'key' => $this->limits()->key('conversation', $conversation, $ip),
            'ip' => $ip,
            'email' => $who['email'],
            'page' => $who['page'],
            'lang' => $who['lang'],
            'site' => $this->request->host(),
            'question' => $question,
        ];

        $commands = new Commands($this->api(), $this->settings, $ip);
        // A text to translate has its own limit, counted on the text alone
        $translation = $commands->translation($question);
        $isCommand = str_starts_with($question, '/');
        $kind = $translation !== null && !isset($translation['error']) ? 'translate' : ($isCommand ? 'command' : 'question');
        $command = $isCommand ? Commands::name($question) : '';
        $limit = $translation !== null ? $this->settings->int('max_translate_chars') : $this->settings->int('max_chars');
        $length = mb_strlen($translation['text'] ?? $question);
        if ($length > $limit) {
            $this->refuse($out, $kind, $translation !== null
                ? 'The text to translate is too long: ' . $length . ' characters, at most ' . $limit . '.'
                : 'Your message is too long: ' . $length . ' characters, at most ' . $limit . '.');
            return;
        }

        $refusal = $this->admit($out, $isCommand, $kind === 'translate');
        if ($refusal === false) {
            return;
        }
        if (is_string($refusal)) {
            $this->refuse($out, $kind, $refusal);
            return;
        }

        if ($translation !== null) {
            if (isset($translation['error'])) {
                $out->text($translation['error']);
                $this->end($out, 'command', 'translate', $translation['error'], '', 'answered', [], microtime(true));
                return;
            }
            self::timeLimit(Translator::TIMEOUT + 30);
            $r = (new Translator($this->api(), $this->store, $out))->translate($translation);
            $this->end($out, 'translate', 'translate', $r['text'], $r['code'] === '' ? '' : Translator::FAILED, Assistant::outcome($r['code']), [], $r['first']);
            return;
        }
        if ($isCommand) {
            self::timeLimit(180);
            $text = $commands->run($question);
            $out->text($text);
            $this->end($out, 'command', $command, $text, '', 'answered', [], microtime(true));
            return;
        }

        $messages = [];
        foreach (array_slice($prior, -self::HISTORY_SENT) as $message) {
            if ($message['content'] !== '' && !str_starts_with($message['content'], '/')) {
                $messages[] = $message;
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        self::timeLimit(Assistant::TIMEOUT + 30);
        $r = (new Assistant($this->api(), $out))->answer($messages, $this->settings->str('instructions'), $this->settings->str('timezone'), $ip);
        $this->end($out, 'question', '', $r['text'], $r['code'] === '' ? '' : Assistant::message($r['code']), Assistant::outcome($r['code']), $r['searches'], $r['first']);
    }

    /**
     * Records the message and its answer, then ends the stream: done with the answer's token, or the message of why
     * there is no answer.
     *
     * @param list<array{0: string, 1: string}> $searches
     */
    private function end(EventStream $out, string $kind, string $command, string $answer, string $error, string $outcome, array $searches = [], ?float $first = null): void
    {
        $token = $this->record($kind, $command, $answer, $error, $outcome, $searches, $first);
        if ($error !== '') {
            $outcome === 'limited' ? $out->limit($error) : $out->error($error);
            return;
        }
        $out->done($token !== null ? ['turn' => $token, 'rate' => $kind !== 'command'] : []);
    }

    /**
     * A message refused by the chat's own limits: counted, never stored (a visitor sending them in a loop adds no
     * rows), and the visitor told why.
     */
    private function refuse(EventStream $out, string $kind, string $message): void
    {
        try {
            $this->journal()->refused($kind, (string) ($this->meta['email'] ?? ''));
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: a refused message could not be counted: ' . $e->getMessage());
        }
        $out->limit($message);
    }

    /**
     * The token of the recorded message; null when it could not be recorded (the visitor still gets the answer).
     *
     * @param list<array{0: string, 1: string}> $searches
     */
    private function record(string $kind, string $command, string $answer, string $error, string $outcome, array $searches, ?float $first): ?string
    {
        if ($this->meta === null) {
            return null;
        }
        try {
            $token = $this->journal()->record($this->meta + [
                'kind' => $kind,
                'command' => $command,
                'answer' => $answer,
                'error' => $error,
                'outcome' => $outcome,
                'searches' => $searches,
                'first_ms' => $first !== null ? (int) round(($first - $this->started) * 1000) : 0,
                'total_ms' => (int) round((microtime(true) - $this->started) * 1000),
            ]);
            $this->recorded = true;
            return $token;
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: the message could not be recorded: ' . $e->getMessage());
            return null;
        }
    }

    /** The primary language of the browser (Accept-Language), '' when it names none. */
    private static function language(string $header): string
    {
        return preg_match('/^\s*([a-zA-Z]{2,3})(?=[-_;,\s]|$)/', $header, $m) ? strtolower($m[1]) : '';
    }

    /**
     * The request body checked: [conversation id, earlier messages, question, the visitor (signed-in email, page,
     * language)], or why it cannot be answered.
     *
     * @return array{0: string, 1: list<array{role: string, content: string}>, 2: string, 3: array{email: string, page: string, lang: string}}|string
     */
    private function read(): array|string
    {
        $body = $this->request->body(self::BODY_MAX);
        $data = $body !== null ? json_decode($body, true, 8) : null;
        if (!is_array($data)) {
            return self::UNREADABLE;
        }
        $conversation = $data['conversation'] ?? null;
        $messages = $data['messages'] ?? null;
        if (!is_string($conversation) || !preg_match(self::CONVERSATION_RE, $conversation)
            || !is_array($messages) || !array_is_list($messages) || count($messages) < 1 || count($messages) > self::MESSAGES_MAX) {
            return self::UNREADABLE;
        }
        $clean = [];
        foreach ($messages as $message) {
            $role = is_array($message) ? ($message['role'] ?? null) : null;
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || !mb_check_encoding($content, 'UTF-8')) {
                return self::UNREADABLE;
            }
            $clean[] = ['role' => $role, 'content' => trim(str_replace("\0", '', $content))];
        }
        $last = array_pop($clean);
        if ($last['role'] !== 'user') {
            return self::UNREADABLE;
        }
        if ($last['content'] === '') {
            return 'Please write a question.';
        }
        foreach ($clean as $i => $message) {
            $clean[$i]['content'] = trim(mb_substr($message['content'], 0, self::MESSAGE_CHARS));
        }
        $who = [
            'email' => Identity::verify($this->store, is_string($data['ident'] ?? null) ? $data['ident'] : '', is_string($data['sig'] ?? null) ? $data['sig'] : ''),
            'page' => is_string($data['page'] ?? null) ? Links::page($data['page'], $this->request->host()) : '',
            'lang' => self::language($this->request->header('Accept-Language')),
        ];
        return [$conversation, $clean, $last['content'], $who];
    }

    /**
     * One question at a time per visitor, questions per conversation (commands do not count) and per visitor: true
     * when admitted, false when another question is being answered (already told), else the message of the limit.
     */
    private function admit(EventStream $out, bool $isCommand, bool $isTranslation): bool|string
    {
        $this->store->purgeIfDue($this->settings->int('window_seconds'), $this->settings->str('timezone'));
        $limits = $this->limits();
        $ip = $this->request->clientIp();
        $visitor = $limits->key('visitor', $ip);
        $ttl = ($isTranslation ? Translator::TIMEOUT : ($isCommand ? 150 : Assistant::TIMEOUT)) + 30;
        $token = $limits->acquire($visitor, $ttl);
        if ($token === null) {
            $out->error('Please wait for the answer to your last question.');
            return false;
        }
        $this->lockKey = $visitor;
        $this->lockToken = $token;
        register_shutdown_function(fn () => $this->releaseLock());

        $conversationKey = (string) ($this->meta['key'] ?? '');
        $max = $this->settings->int('questions_per_conversation');
        if ($limits->conversationQuestions($conversationKey) >= $max) {
            return 'This conversation has reached ' . $max . ' questions. Start a new chat with the New chat button.';
        }
        if ($limits->visitorQuestions($visitor, $this->settings->int('window_seconds')) >= $this->settings->int('questions_per_visitor')) {
            return 'You have asked many questions in a short time. Please try again a little later.';
        }
        $limits->register($visitor, $isCommand ? null : $conversationKey);
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockKey === null || $this->lockToken === null) {
            return;
        }
        $key = $this->lockKey;
        $token = $this->lockToken;
        $this->lockKey = null;
        $this->lockToken = null;
        try {
            $this->limits()->release($key, $token);
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: the lock could not be released: ' . $e->getMessage());
        }
    }

    private static function timeLimit(int $seconds): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit($seconds);
        }
    }

    private function limits(): Limits
    {
        return $this->limits ??= new Limits($this->store);
    }

    private function journal(): Journal
    {
        return $this->journal ??= new Journal($this->store, $this->settings->str('timezone'));
    }

    private function api(): OpensolrApi
    {
        return new OpensolrApi($this->settings->str('opensolr_email'), $this->settings->str('opensolr_api_key'), $this->settings->str('index_name'));
    }
}
