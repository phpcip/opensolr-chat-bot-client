<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * Streamed Markdown passed on as it comes, held back only while a link is still being written, links repaired.
 */
final class MarkdownFlow
{
    private const TAIL = 2048;

    private string $pending = '';
    private string $tail = '';
    private bool $wrote = false;

    public function push(string $text): string
    {
        $this->pending .= $text;
        if (preg_match('/\[[^\]\n]*$|\][ \t]*\r?\n?[ \t]*$|\]\s*\([^)\s]*$/Du', $this->pending)) {
            return '';
        }
        return $this->flush();
    }

    public function flush(): string
    {
        if ($this->pending === '') {
            return '';
        }
        $piece = self::repair($this->pending);
        $this->pending = '';
        $this->tail = substr($this->tail . $piece, -self::TAIL);
        $this->wrote = $this->wrote || trim($piece) !== '';
        return $piece;
    }

    /**
     * What is left at the end, with the closing bracket of a link the text ended inside.
     */
    public function finish(): string
    {
        $piece = $this->flush();
        if (preg_match('/\]\((?:https?:\/\/|\/)[^)\s]+\s*$/', $this->tail)) {
            $piece .= ')';
            $this->tail .= ')';
        }
        return $piece;
    }

    public function wrote(): bool
    {
        return $this->wrote;
    }

    private static function repair(string $text): string
    {
        $text = (string) preg_replace('/\][ \t]*\r?\n?[ \t]*\(((?:https?:\/\/|\/)[^)\s]*)/u', '](\1', $text);
        return (string) preg_replace('/(\]\((?:https?:\/\/|\/)[^)\s]+)(?=\s(?!\s*")|$)/u', '$1)', $text);
    }
}
