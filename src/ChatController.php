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

    public function __construct(
        private readonly Request $request,
        private readonly Store $store,
        private readonly Settings $settings,
        private readonly Captcha $captcha,
    ) {
    }

    public function handle(EventStream $out): void
    {
        $out->open();
        try {
            $this->respond($out);
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: ' . $e->getMessage());
            $out->error(Assistant::FAILED);
        } finally {
            $this->releaseLock();
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
        [$conversation, $prior, $question] = $input;

        $commands = new Commands($this->api(), $this->settings, $this->request->clientIp());
        // A text to translate has its own limit, counted on the text alone
        $translation = $commands->translation($question);
        $limit = $translation !== null ? $this->settings->int('max_translate_chars') : $this->settings->int('max_chars');
        $length = mb_strlen($translation['text'] ?? $question);
        if ($length > $limit) {
            $out->error($translation !== null
                ? 'The text to translate is too long: ' . $length . ' characters, at most ' . $limit . '.'
                : 'Your message is too long: ' . $length . ' characters, at most ' . $limit . '.');
            return;
        }

        $isCommand = str_starts_with($question, '/');
        if (!$this->admit($out, $conversation, $isCommand, $translation !== null && !isset($translation['error']))) {
            return;
        }

        if ($translation !== null) {
            if (isset($translation['error'])) {
                $out->text($translation['error']);
                $out->done();
                return;
            }
            self::timeLimit(Translator::TIMEOUT + 30);
            (new Translator($this->api(), $this->store, $out))->translate($translation);
            return;
        }
        if ($isCommand) {
            self::timeLimit(180);
            $out->text($commands->run($question));
            $out->done();
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
        (new Assistant($this->api(), $out))->answer($messages, $this->settings->str('instructions'), $this->settings->str('timezone'), $this->request->clientIp());
    }

    /**
     * The request body checked: [conversation id, earlier messages, question], or why it cannot be answered.
     *
     * @return array{0: string, 1: list<array{role: string, content: string}>, 2: string}|string
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
        return [$conversation, $clean, $last['content']];
    }

    /**
     * One question at a time per visitor, questions per conversation (commands do not count) and per visitor.
     */
    private function admit(EventStream $out, string $conversation, bool $isCommand, bool $isTranslation): bool
    {
        $this->store->purgeIfDue($this->settings->int('window_seconds'));
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

        $conversationKey = $limits->key('conversation', $conversation, $ip);
        $max = $this->settings->int('questions_per_conversation');
        if ($limits->conversationQuestions($conversationKey) >= $max) {
            $out->limit('This conversation has reached ' . $max . ' questions. Start a new chat with the New chat button.');
            return false;
        }
        if ($limits->visitorQuestions($visitor, $this->settings->int('window_seconds')) >= $this->settings->int('questions_per_visitor')) {
            $out->limit('You have asked many questions in a short time. Please try again a little later.');
            return false;
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

    private function api(): OpensolrApi
    {
        return new OpensolrApi($this->settings->str('opensolr_email'), $this->settings->str('opensolr_api_key'), $this->settings->str('index_name'));
    }
}
