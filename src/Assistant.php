<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\EventStream;

/**
 * The answer of the Opensolr assistant (assistant_chat), relayed to the widget as it arrives.
 */
final class Assistant
{
    public const TIMEOUT = 180;
    public const FAILED = 'Sorry, I could not answer that right now. Please try again in a moment.';
    public const LATE = 'The answer took too long. Please try again in a moment.';
    private const LATE_CODES = ['ERROR_STREAM_STALLED', 'ERROR_STREAM_TIMEOUT', 'ERROR_ASSISTANT_TIMEOUT'];
    private const MESSAGES = [
        'ERROR_VISITOR_QUESTIONS_LIMIT' => 'You have asked many questions in a short time. Please try again a little later.',
        'ERROR_MESSAGE_TOO_LONG' => 'Your message is too long. Please make it shorter.',
    ];

    public function __construct(
        private readonly OpensolrApi $api,
        private readonly EventStream $out,
    ) {
    }

    /**
     * The message the visitor sees for an error code of the Opensolr API.
     */
    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? (in_array($code, self::LATE_CODES, true) ? self::LATE : self::FAILED);
    }

    /** How an answer ended for the history: answered, late, limited or failed. */
    public static function outcome(string $code): string
    {
        if ($code === '') {
            return 'answered';
        }
        if (in_array($code, self::LATE_CODES, true)) {
            return 'late';
        }
        return isset(self::MESSAGES[$code]) ? 'limited' : 'failed';
    }

    /**
     * The products of an answer (a link, a price and its og_image) as the widget takes them; anything else is dropped.
     *
     * @return list<array{link: string, title: string, price: float, currency: string, image: string}>
     */
    private static function products(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) ? array_slice($items, 0, 20) : [] as $p) {
            $link = is_array($p) && is_string($p['link'] ?? null) ? $p['link'] : '';
            $image = is_array($p) && is_string($p['image'] ?? null) ? $p['image'] : '';
            if (!preg_match('~^https?://~i', $link) || !preg_match('~^https?://~i', $image) || strlen($link) > 2000 || strlen($image) > 2000
                || !is_numeric($p['price'] ?? null)) {
                continue;
            }
            $out[] = [
                'link' => $link,
                'title' => mb_substr(is_string($p['title'] ?? null) ? $p['title'] : '', 0, 300),
                'price' => (float) $p['price'],
                'currency' => mb_substr(is_string($p['currency'] ?? null) ? $p['currency'] : '', 0, 10),
                'image' => $image,
            ];
        }
        return $out;
    }

    /**
     * The answer relayed as it arrives; the caller sends the end (done or the error message of the code).
     *
     * @param list<array{role: string, content: string}> $messages
     * @return array{text: string, code: string, searches: list<array{0: string, 1: string}>, first: ?float}
     */
    public function answer(array $messages, string $instructions, string $timezone, string $visitorIp, string $conversationId = '', string $visitorEmail = ''): array
    {
        $flow = new MarkdownFlow();
        $failure = null;
        $text = '';
        $first = null;
        $searches = [];
        $holding = false;
        $send = function (string $piece) use (&$text, &$first, &$holding, $flow): bool {
            if ($piece !== '') {
                $text .= $piece;
                $first ??= microtime(true);
                $holding = false;
            }
            if (!$this->out->text($piece)) {
                return false;
            }
            // Text held back while a link is written: one signal, so the visitor sees the answer is still coming
            if (!$holding && $text !== '' && $flow->holding()) {
                $holding = true;
                return $this->out->hold();
            }
            return true;
        };
        try {
            $this->api->stream('assistant_chat', array_filter([
                'messages' => $messages,
                'instructions' => $instructions,
                'timezone' => $timezone,
                'visitor_ip' => $visitorIp,
                // The conversation's id: the Opensolr API keeps what its tools found for the next questions
                'conversation_id' => $conversationId,
                // The signed-in visitor, verified by the site's signature: the Opensolr API reads their account for them where it allows it
                'visitor_email' => $visitorEmail,
            ], static fn ($v): bool => $v !== ''), function (array $event) use ($flow, &$failure, &$searches, $send): bool {
                $type = is_string($event['type'] ?? null) ? $event['type'] : '';
                if ($type === 'text') {
                    return $send($flow->push(is_string($event['text'] ?? null) ? $event['text'] : ''));
                }
                if ($type === 'status' || $type === 'tool') {
                    if ($type === 'tool' && is_string($event['name'] ?? null)) {
                        $searches[] = [$event['name'], Progress::subject($event)];
                    }
                    $line = Progress::label($event);
                    if ($line === '') {
                        return true;
                    }
                    return $send($flow->flush()) && $this->out->progress($line);
                }
                if ($type === 'products') {
                    return $this->out->products(self::products($event['items'] ?? null));
                }
                if ($type === 'error') {
                    $failure = is_scalar($event['msg'] ?? null) ? (string) $event['msg'] : 'ERROR';
                    return false;
                }
                return $type !== 'done';
            }, self::TIMEOUT, fn (): bool => $this->out->alive());
            if ($failure !== null) {
                throw new \RuntimeException($failure);
            }
            $send($flow->finish());
            if (!$flow->wrote()) {
                throw new \RuntimeException('ERROR_NO_ANSWER');
            }
            return ['text' => $text, 'code' => '', 'searches' => $searches, 'first' => $first];
        } catch (\RuntimeException $e) {
            error_log('Opensolr Chat Bot: the answer failed: ' . $e->getMessage());
            $send($flow->finish());
            return ['text' => $text, 'code' => $e->getMessage(), 'searches' => $searches, 'first' => $first];
        }
    }
}
