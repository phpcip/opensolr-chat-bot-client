<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\Links;

/**
 * An answer of the chat shown in the admin: paragraphs, lists, code, bold and links; everything else escaped text.
 */
final class AdminMarkdown
{
    private const INLINE = '~\[([^\]\n]{1,500})\]\(\s*<?(https?://[^\s<>]+?)>?\s*\)|`([^`\n]+)`|\*\*([^*\n]+)\*\*|(https?://[^\s<>"\'`\[\]{}|\\\\^]+)~i';

    public static function render(string $markdown): string
    {
        $out = '';
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $count = count($lines);
        $paragraph = [];
        $list = null;
        $flush = static function () use (&$out, &$paragraph, &$list): void {
            if ($paragraph !== []) {
                $out .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $paragraph)) . '</p>';
                $paragraph = [];
            }
            if ($list !== null) {
                $out .= '</' . $list . '>';
                $list = null;
            }
        };
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*(```|~~~)/', $line, $fence)) {
                $flush();
                $code = [];
                for ($i++; $i < $count && !str_starts_with(ltrim($lines[$i]), $fence[1]); $i++) {
                    $code[] = $lines[$i];
                }
                $out .= '<pre><code>' . AdminView::e(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^\s*(?:([-*+])|(\d{1,9})[.)])\s+(.*)$/', $line, $item)) {
                $tag = $item[1] !== '' ? 'ul' : 'ol';
                if ($paragraph !== [] || ($list !== null && $list !== $tag)) {
                    $flush();
                }
                if ($list === null) {
                    $out .= '<' . $tag . '>';
                    $list = $tag;
                }
                $out .= '<li>' . self::inline($item[3]) . '</li>';
                continue;
            }
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/', $line, $heading)) {
                $flush();
                $out .= '<p><strong>' . self::inline($heading[1]) . '</strong></p>';
                continue;
            }
            if ($list !== null) {
                $flush();
            }
            $paragraph[] = $line;
        }
        $flush();
        return $out;
    }

    /**
     * One line: links (Markdown and bare), `code` and **bold** turned into HTML, the rest escaped.
     */
    public static function inline(string $text): string
    {
        if (!preg_match_all(self::INLINE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return AdminView::e($text);
        }
        $out = '';
        $at = 0;
        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $out .= AdminView::e(substr($text, $at, $offset - $at));
            $at = $offset + strlen($whole);
            if (($m[2][0] ?? '') !== '') {
                $out .= self::link($m[2][0], $m[1][0]);
            } elseif (($m[3][0] ?? '') !== '') {
                $out .= '<code>' . AdminView::e($m[3][0]) . '</code>';
            } elseif (($m[4][0] ?? '') !== '') {
                $out .= '<strong>' . AdminView::e($m[4][0]) . '</strong>';
            } else {
                $url = rtrim($m[5][0], '.,;:!?*_\'"');
                $out .= self::link($url, $url) . AdminView::e(substr($m[5][0], strlen($url)));
            }
        }
        return $out . AdminView::e(substr($text, $at));
    }

    private static function link(string $url, string $label): string
    {
        $href = Links::normalize($url);
        if ($href === null) {
            return AdminView::e($label);
        }
        return '<a href="' . AdminView::e($href) . '" target="_blank" rel="noopener noreferrer">' . AdminView::e($label) . '</a>';
    }
}
