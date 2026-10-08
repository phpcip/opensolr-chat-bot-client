<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\EventStream;

/**
 * A /translate command, written by the Opensolr chat model (chat_completions, streamed).
 */
final class Translator
{
    public const TIMEOUT = 300;
    public const FAILED = 'Sorry, I could not translate that right now. Please try again in a moment.';

    public function __construct(
        private readonly OpensolrApi $api,
        private readonly Store $store,
        private readonly EventStream $out,
    ) {
    }

    /**
     * The translation relayed as it is written; the caller sends the end (done, or FAILED when the code is set).
     *
     * @param array{from: ?string, to: string, text: string} $translation
     * @return array{text: string, code: string, first: ?float}
     */
    public function translate(array $translation): array
    {
        $languages = Languages::all();
        $name = static fn (string $code): string => $languages[$code][0] . ' (' . $code . ')';
        $system = 'Translate the text the user sends ' . ($translation['from'] !== null ? 'from ' . $name($translation['from']) . ' ' : '')
            . 'into ' . $name($translation['to']) . '. Write the translation and nothing else: no introduction, no explanation, no notes, no quotation marks around it. Keep the meaning, the tone, the line breaks, the Markdown, and the names, numbers, codes and links as they are.';
        $body = [
            'stream' => true,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $translation['text']],
            ],
        ];
        // As long as the chat model may write: a long text gives a long translation
        $max = $this->maxOutputTokens();
        if ($max > 0) {
            $body['max_tokens'] = $max;
        }
        $flow = new MarkdownFlow();
        $failure = null;
        $text = '';
        $first = null;
        $send = function (string $piece) use (&$text, &$first): bool {
            if ($piece !== '') {
                $text .= $piece;
                $first ??= microtime(true);
            }
            return $this->out->text($piece);
        };
        try {
            $this->api->stream('chat_completions', $body, function (array $chunk) use ($flow, &$failure, $send): bool {
                if (isset($chunk['error'])) {
                    $failure = is_scalar($chunk['error']) ? (string) $chunk['error'] : 'ERROR';
                    return false;
                }
                $piece = $chunk['choices'][0]['delta']['content'] ?? '';
                return !is_string($piece) || $piece === '' || $send($flow->push($piece));
            }, self::TIMEOUT);
            if ($failure !== null) {
                throw new \RuntimeException($failure);
            }
            $send($flow->finish());
            if (!$flow->wrote()) {
                throw new \RuntimeException('ERROR_NO_ANSWER');
            }
            return ['text' => $text, 'code' => '', 'first' => $first];
        } catch (\RuntimeException $e) {
            error_log('Opensolr Chat Bot: the translation failed: ' . $e->getMessage());
            $send($flow->finish());
            return ['text' => $text, 'code' => $e->getMessage(), 'first' => $first];
        }
    }

    /**
     * The most the chat model may write in one answer, kept an hour; a failed read is never kept.
     */
    private function maxOutputTokens(): int
    {
        $value = $this->store->cached('llm_max_output_tokens', 3600, function (): ?int {
            try {
                $llm = $this->api->call('vdb_info', [], 30)['llm'] ?? null;
            } catch (\RuntimeException $e) {
                return null;
            }
            return is_array($llm) && !empty($llm['id']) && is_numeric($llm['max_output_tokens'] ?? null) ? (int) $llm['max_output_tokens'] : null;
        });
        return is_int($value) ? $value : 0;
    }
}
