<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\EventStream;

/**
 * The answer of the Opensolr assistant (assistant_chat), relayed to the widget as it arrives.
 */
final class Assistant
{
    public const TIMEOUT = 90;
    public const FAILED = 'Sorry, I could not answer that right now. Please try again in a moment.';
    public const LATE = 'The answer took too long. Please try again in a moment.';
    private const LATE_CODES = ['ERROR_STREAM_STALLED', 'ERROR_STREAM_TIMEOUT', 'ERROR_ASSISTANT_TIMEOUT'];

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
        return in_array($code, self::LATE_CODES, true) ? self::LATE : self::FAILED;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function answer(array $messages, string $instructions, string $timezone, string $visitorIp): void
    {
        $flow = new MarkdownFlow();
        $failure = null;
        try {
            $this->api->stream('assistant_chat', [
                'messages' => $messages,
                'instructions' => $instructions,
                'timezone' => $timezone,
                'visitor_ip' => $visitorIp,
            ], function (array $event) use ($flow, &$failure): bool {
                $type = is_string($event['type'] ?? null) ? $event['type'] : '';
                if ($type === 'text') {
                    return $this->out->text($flow->push(is_string($event['text'] ?? null) ? $event['text'] : ''));
                }
                if ($type === 'status' || $type === 'tool') {
                    $line = Progress::label($event);
                    if ($line === '') {
                        return true;
                    }
                    return $this->out->text($flow->flush()) && $this->out->progress($line);
                }
                if ($type === 'error') {
                    $failure = is_scalar($event['msg'] ?? null) ? (string) $event['msg'] : 'ERROR';
                    return false;
                }
                return $type !== 'done';
            }, self::TIMEOUT);
            if ($failure !== null) {
                throw new \RuntimeException($failure);
            }
            $this->out->text($flow->finish());
            if (!$flow->wrote()) {
                throw new \RuntimeException('ERROR_NO_ANSWER');
            }
            $this->out->done();
        } catch (\RuntimeException $e) {
            error_log('Opensolr Chat Bot: the answer failed: ' . $e->getMessage());
            $this->out->text($flow->finish());
            $this->out->error(self::message($e->getMessage()));
        }
    }
}
