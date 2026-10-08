<?php

declare(strict_types=1);

namespace Opensolr\ChatBot\Admin;

use Opensolr\ChatBot\I18n;
use Opensolr\ChatBot\Languages;
use Opensolr\ChatBot\Settings;
use Opensolr\ChatBot\Store;

/**
 * The stats and the history pages of the admin; every value escaped, every value a link to the history filtered
 * on it.
 */
final class InsightsView
{
    private const CSS = <<<'CSS'
h2{font-size:17px;margin:0 0 12px;color:#0f172a}
a{color:#c05520}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin:20px 0 0}
.periods{display:flex;flex-wrap:wrap;gap:8px}
.periods a{padding:7px 14px;border:1px solid #cbd5e1;border-radius:2px;background:#ffffff;color:#334155;font-weight:600;font-size:15px;text-decoration:none}
.periods a.on{border-color:#c05520;background:#c05520;color:#ffffff}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 0}
@media (max-width:980px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:640px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.kpi{display:flex;flex-direction:column;gap:4px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:2px;background:#ffffff}
.k-label{color:#475569;font-size:14px;font-weight:600}
.k-value{color:#0f172a;font-size:26px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}
.k-sub{color:#475569;font-size:14px}
.k-delta{font-size:14px;font-weight:600;color:#475569}
.k-delta.good{color:#15803d}
.k-delta.bad{color:#b42318}
.panel{margin:16px 0 0;padding:16px 18px 18px;border:1px solid #e2e8f0;border-radius:2px;background:#ffffff}
.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:16px 0 0}
.cards .panel{margin:0}
@media (max-width:800px){.cards{grid-template-columns:minmax(0,1fr)}}
.chart{display:flex;align-items:flex-end;gap:3px;height:180px;padding:0 0 2px;border-bottom:1px solid #cbd5e1}
.col{flex:1 1 0;display:flex;flex-direction:column-reverse;height:100%;min-width:4px}
.seg{display:block;width:100%}
.seg.ok{background:#c05520}
.seg.fail{background:#b42318}
.seg.lim{background:#94a3b8}
.axis{display:flex;justify-content:space-between;margin-top:6px;color:#475569;font-size:14px}
.legend{display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;color:#334155;font-size:14px}
.legend i{display:inline-block;width:12px;height:12px;margin-right:6px;vertical-align:-1px;border-radius:2px}
.wrap{overflow-x:auto}
table.list{width:100%;border-collapse:collapse;font-size:15px}
table.list th,table.list td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
table.list th{color:#475569;font-size:14px;font-weight:600;white-space:nowrap}
table.list td.num,table.list th.num{text-align:right;white-space:nowrap}
table.list td.nowrap{white-space:nowrap}
table.list a{text-decoration:none}
table.list a:hover{text-decoration:underline}
.cut{display:inline-block;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
ol.top{margin:0;padding:0;list-style:none}
ol.top li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 12px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:15px}
ol.top .v{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
ol.top .v a{text-decoration:none}
ol.top .n{color:#334155;font-weight:600;text-align:right}
ol.top .w{grid-column:1/3;height:4px;background:#f1f5f9;border-radius:2px}
ol.top .w span{display:block;height:4px;background:#c05520;border-radius:2px}
.none{color:#475569;margin:0}
.flag{display:inline-block;min-width:22px;margin-right:6px;font-size:14px;font-weight:600;color:#475569}
.flag.glyph{font-size:18px;line-height:1;color:inherit}
.badge{display:inline-block;margin:0 6px 4px 0;padding:1px 7px;border:1px solid #cbd5e1;border-radius:2px;font-size:14px;color:#334155;white-space:nowrap}
.badge.bad{border-color:#f3b4ad;color:#b42318}
.badge.good{border-color:#a7d7b5;color:#15803d}
.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px 14px;margin:16px 0 0;padding:4px 18px 18px;border:1px solid #e2e8f0;border-radius:2px;background:#ffffff}
.filters label{margin:12px 0 4px;font-size:14px}
.filters .actions{grid-column:1/-1;margin-top:16px}
@media (max-width:980px){.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.filters{grid-template-columns:minmax(0,1fr)}}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0}
.chip{display:inline-flex;align-items:center;gap:8px;padding:4px 6px 4px 10px;border:1px solid #c05520;border-radius:2px;background:#ffffff;color:#0f172a;font-size:14px;max-width:100%}
.chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip a{padding:0 6px;font-weight:700;text-decoration:none}
.pager{display:flex;justify-content:space-between;gap:12px;margin:14px 0 0}
.overlay{position:fixed;top:0;right:0;bottom:0;left:0;z-index:10;display:flex;justify-content:center;align-items:flex-start;padding:24px 16px;overflow-y:auto;background:#0f172acc}
.dlg{width:100%;max-width:1000px;padding:20px 22px 26px;border-radius:2px;background:#ffffff}
.dlg:focus{outline:none}
.dlg-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0}
.dlg-head h2{margin:0}
.close{padding:7px 14px;border:1px solid #cbd5e1;border-radius:2px;color:#334155;font-weight:600;text-decoration:none}
.facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 18px;margin:14px 0 0}
.facts div{min-width:0}
.facts dt{color:#475569;font-size:14px;font-weight:600}
.facts dd{margin:2px 0 0;overflow-wrap:anywhere}
@media (max-width:800px){.facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
.dlg-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0}
.dlg-actions a{padding:6px 12px;border:1px solid #f3b4ad;border-radius:2px;color:#b42318;font-size:14px;font-weight:600;text-decoration:none}
.confirm{margin:14px 0 0;padding:12px 14px;border:1px solid #dc2626;border-radius:2px;color:#7f1d1d}
.confirm button{border-color:#b42318;background:#b42318}
.turn{margin:18px 0 0;padding:14px 0 0;border-top:1px solid #e2e8f0}
.t-meta{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;color:#475569;font-size:14px}
.q{margin:8px 0 0;padding:9px 12px;border:1px solid #ddd5ca;border-radius:2px;background:#efe9e2;white-space:pre-wrap;overflow-wrap:anywhere}
.searches{margin:8px 0 0;padding:0;list-style:none;color:#475569;font-size:14px}
.searches li{margin:2px 0}
.searches b{color:#334155}
.a{margin:8px 0 0;padding:9px 12px;border:1px solid #e2e8f0;border-radius:2px;overflow-wrap:anywhere}
.a p,.a ul,.a ol,.a pre{margin:0 0 .6em}
.a>:last-child{margin-bottom:0}
.a ul,.a ol{padding-left:1.4em}
.a code{padding:1px 4px;border-radius:2px;background:#f1f5f9}
.err{margin:8px 0 0;color:#b42318}
.t-foot{margin:8px 0 0;color:#475569;font-size:14px;overflow-wrap:anywhere}
CSS;

    private const TOOLS = [
        'site_search' => 'Search by meaning',
        'lexical_search' => 'Search for the exact words',
        'latest_search' => 'Search for the latest',
        'read_document' => 'Read a document',
        'describe_image' => 'Looked at a picture',
        'find_place' => 'Places and addresses',
        'local_time' => 'Local time',
        'currency_rates' => 'Exchange rates',
        'vat_rates' => 'VAT rates',
        'vat_check' => 'VAT numbers',
        'distance' => 'Distances',
        'postal_codes' => 'Postal codes',
        'ip_location' => 'IP addresses',
        'analyze_text' => 'Language and sentiment',
    ];

    private static function e(string|int $value): string
    {
        return AdminView::e($value);
    }

    private static function t(string $text, array $vars = []): string
    {
        return self::e(I18n::t($text, $vars));
    }

    /** @var array<string, \DateTimeZone> */
    private static array $zones = [];

    private static function zone(string $timezone): \DateTimeZone
    {
        return self::$zones[$timezone] ??= new \DateTimeZone(Settings::validTimezone($timezone) ? $timezone : 'UTC');
    }

    /** A moment as mm/dd/yyyy hh:mm:ss in the chat's time zone. */
    private static function when(int $ts, string $timezone): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone($timezone))->format('m/d/Y H:i:s');
    }

    /** A day (yyyymmdd) as mm/dd/yyyy. */
    private static function day(int $day): string
    {
        $s = (string) $day;
        return substr($s, 4, 2) . '/' . substr($s, 6, 2) . '/' . substr($s, 0, 4);
    }

    private static function n(int $n): string
    {
        return number_format($n);
    }

    private static function pct(?float $share): string
    {
        return $share === null ? '–' : (string) round($share * 100) . '%';
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1) . ' s';
    }

    /** The flag of a country (drawn by admin.js where the platform has flag glyphs) and its name. */
    private static function country(string $code, string $name, string $city = ''): string
    {
        if ($code === '') {
            return self::t('Unknown');
        }
        $label = $name !== '' ? $name : $code;
        return '<span class="flag" data-cc="' . self::e($code) . '" aria-hidden="true">' . self::e($code) . '</span>' . self::e($label) . ($city !== '' ? ', ' . self::e($city) : '');
    }

    /**
     * A link of the history with these filters.
     *
     * @param array<string, string|int> $params
     */
    private static function to(string $prefix, array $params): string
    {
        $params = array_filter($params, static fn ($v): bool => $v !== '' && $v !== null);
        return $prefix . '/admin/history' . ($params ? '?' . http_build_query($params) : '');
    }

    private static function link(string $href, string $html, string $class = ''): string
    {
        return '<a href="' . self::e($href) . '"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $html . '</a>';
    }

    private static function script(string $prefix): string
    {
        return '<script src="' . self::e($prefix . '/admin.js') . '" defer></script>';
    }

    /**
     * @param array{prefix: string, csrf: string, notice: string, timezone: string, search: bool, site: string, stats: array<string, mixed>} $p
     */
    public static function stats(array $p): string
    {
        $s = $p['stats'];
        $days = (int) $s['days'];
        $t = $s['totals'];
        $prev = $s['previous'];
        $prefix = $p['prefix'];
        $body = AdminView::top($prefix, $p['csrf'], $p['notice'], []) . AdminView::nav($prefix, 'stats');

        $body .= '<div class="bar"><div class="periods">';
        foreach ([1 => I18n::t('Today'), 7 => I18n::t('7 days'), 30 => I18n::t('30 days')] as $d => $label) {
            $body .= '<a href="' . self::e($prefix . '/admin/stats?days=' . $d) . '"' . ($d === $days ? ' class="on" aria-current="page"' : '') . '>' . self::e($label) . '</a>';
        }
        $body .= '</div><span class="hint">' . self::t('Times in {zone}. The history keeps {days} days.', ['zone' => $p['timezone'], 'days' => Store::HISTORY_DAYS]) . '</span></div>';

        $against = $days === 1 ? I18n::t('vs yesterday') : I18n::t('vs the {days} days before', ['days' => $days]);
        $delta = static function (string $key, string $kind, bool $upIsGood) use ($t, $prev, $against): string {
            if ($prev === null || $t[$key] === null || $prev[$key] === null) {
                return '';
            }
            $now = (float) $t[$key];
            $was = (float) $prev[$key];
            if ($kind === 'count') {
                if ($was <= 0) {
                    return '';
                }
                $change = ($now - $was) / $was * 100;
                $text = sprintf('%+d%%', (int) round($change));
            } elseif ($kind === 'share') {
                $change = ($now - $was) * 100;
                $text = I18n::t('{points} pts', ['points' => sprintf('%+d', (int) round($change))]);
            } else {
                $change = ($now - $was) / 1000;
                $text = sprintf('%+.1f s', $change);
            }
            $class = abs($change) < 0.5 ? '' : (($change > 0) === $upIsGood ? ' good' : ' bad');
            return '<span class="k-delta' . $class . '">' . self::e($text . ' ' . $against) . '</span>';
        };
        $tile = static fn (string $label, string $value, string $sub, string $deltaHtml = ''): string => '<div class="kpi"><span class="k-label">' . self::e($label) . '</span>'
            . '<span class="k-value">' . self::e($value) . '</span>' . ($sub !== '' ? '<span class="k-sub">' . self::e($sub) . '</span>' : '') . $deltaHtml . '</div>';

        $body .= '<div class="kpis">'
            . $tile(I18n::t('Messages'), self::n((int) $t['messages']), I18n::t('{questions} questions, {commands} commands, {translations} translations', ['questions' => self::n((int) $t['questions']), 'commands' => self::n((int) $t['commands']), 'translations' => self::n((int) $t['translations'])]), $delta('messages', 'count', true))
            . $tile(I18n::t('Conversations'), self::n((int) $t['conversations']), I18n::t('started in the period'), $delta('conversations', 'count', true))
            . $tile(I18n::t('Visitors'), self::n((int) $t['visitors']), I18n::t('different IP addresses'))
            . $tile(I18n::t('Signed-in users'), self::n((int) $t['users']), I18n::t('{share} of the messages', ['share' => self::pct($t['signed_share'])]))
            . $tile(I18n::t('Countries'), self::n((int) $t['countries']), I18n::t('the visitors came from'))
            . $tile(I18n::t('Answered'), self::pct($t['answered_share']), I18n::t('{count} answers', ['count' => self::n((int) $t['answered'])]), $delta('answered_share', 'share', true))
            . $tile(I18n::t('No link to your site'), self::pct($t['unlinked_share']), I18n::t('of the answers that searched the site'), $delta('unlinked_share', 'share', false))
            . $tile(I18n::t('First word'), $t['median_ms'] === null ? '–' : self::seconds((int) $t['median_ms']), I18n::t('median time to the first word'), $delta('median_ms', 'time', false))
            . $tile(I18n::t('Errors and timeouts'), self::n((int) $t['failed']), I18n::t('answers that did not come'), $delta('failed', 'count', false))
            . $tile(I18n::t('Refused by the limits'), self::n((int) $t['limited']), I18n::t('too long, too many, too often'), $delta('limited', 'count', false))
            . $tile(I18n::t('Ratings'), self::n((int) $t['good']) . ' / ' . self::n((int) $t['bad']), I18n::t('good / bad, from the visitors'))
            . $tile(I18n::t('Answers with a click'), self::pct($t['clicked_share']), I18n::t('a link of the answer was opened'), $delta('clicked_share', 'share', true))
            . '</div>';

        $body .= self::daily($s['daily']);
        $body .= self::countriesPanel($prefix, $s['countries'], $days);
        $body .= self::usersPanel($prefix, $s['users'], $days, $p['timezone']);

        $top = $s['top'];
        $searchLink = static fn (array $params): ?string => $p['search'] ? self::to($prefix, $params + ['days' => $days]) : null;
        $body .= '<div class="cards">'
            . self::topPanel(I18n::t('What people search for'), I18n::t('The words the assistant searched the site for.'), $top['term'], static fn (string $v): array => [self::e($v), $searchLink(['q' => $v])])
            . self::unlinkedPanel($prefix, $s['unlinked'], $p['timezone'])
            . self::topPanel(I18n::t('Pages cited the most'), I18n::t('The pages of your site linked in the answers.'), $top['cited'], static fn (string $v): array => [self::e($v), $searchLink(['link' => $v])])
            . self::topPanel(I18n::t('Links opened from the answers'), I18n::t('What the visitors clicked in the chat.'), $top['click'], static fn (string $v): array => [self::e($v), $searchLink(['link' => $v])])
            . self::topPanel(I18n::t('Pages people ask from'), I18n::t('The pages the chat was used on.'), $top['page'], static fn (string $v): array => [self::e($v), self::to($prefix, ['page' => $v, 'days' => $days])])
            . self::topPanel(I18n::t('What the assistant used'), I18n::t('Its searches and lookups.'), $top['tool'], static fn (string $v): array => [self::e(isset(self::TOOLS[$v]) ? I18n::t(self::TOOLS[$v]) : $v), null])
            . self::topPanel(I18n::t('Commands and translations'), I18n::t('The messages that started with "/".'), $top['command'], static fn (string $v): array => ['<code>/' . self::e($v) . '</code>', null])
            . self::topPanel(I18n::t('Languages of the browsers'), I18n::t('Of the conversations started.'), $top['lang'], static fn (string $v): array => [self::e(Languages::all()[$v][0] ?? $v), null])
            . '</div>';

        return AdminView::page(I18n::t('Stats') . ' · Opensolr Chat Bot', $body . self::script($prefix), self::CSS);
    }

    /**
     * @param list<array{day: int, answered: int, failed: int, limited: int}> $daily
     */
    private static function daily(array $daily): string
    {
        $max = 0;
        foreach ($daily as $d) {
            $max = max($max, $d['answered'] + $d['failed'] + $d['limited']);
        }
        $out = '<section class="panel"><h2>' . self::t('Messages per day, last {days} days', ['days' => Store::HISTORY_DAYS]) . '</h2>';
        if ($max === 0) {
            return $out . '<p class="none">' . self::t('Nothing yet.') . '</p></section>';
        }
        $out .= '<div class="chart">';
        foreach ($daily as $d) {
            $title = I18n::t('{day}: {answered} answered, {failed} failed, {limited} refused', [
                'day' => self::day($d['day']), 'answered' => self::n($d['answered']), 'failed' => self::n($d['failed']), 'limited' => self::n($d['limited']),
            ]);
            $out .= '<div class="col" title="' . self::e($title) . '">';
            foreach (['ok' => $d['answered'], 'fail' => $d['failed'], 'lim' => $d['limited']] as $class => $n) {
                if ($n > 0) {
                    $out .= '<span class="seg ' . $class . '" style="height:' . round($n / $max * 100, 2) . '%"></span>';
                }
            }
            $out .= '</div>';
        }
        $mid = $daily[intdiv(count($daily), 2)]['day'];
        return $out . '</div><div class="axis"><span>' . self::e(self::day($daily[0]['day'])) . '</span><span>' . self::e(self::day($mid)) . '</span><span>' . self::e(self::day($daily[count($daily) - 1]['day'])) . '</span></div>'
            . '<div class="legend"><span><i style="background:#c05520"></i>' . self::t('Answered') . '</span><span><i style="background:#b42318"></i>' . self::t('Errors and timeouts') . '</span>'
            . '<span><i style="background:#94a3b8"></i>' . self::t('Refused by the limits') . '</span><span>' . self::t('Highest day: {count} messages.', ['count' => self::n($max)]) . '</span></div></section>';
    }

    /**
     * @param list<array{country: string, name: string, conversations: int, visitors: int, messages: int}> $countries
     */
    private static function countriesPanel(string $prefix, array $countries, int $days): string
    {
        $out = '<section class="panel"><h2>' . self::t('Countries') . '</h2>';
        if ($countries === []) {
            return $out . '<p class="none">' . self::t('Nothing yet.') . '</p></section>';
        }
        $out .= '<div class="wrap"><table class="list"><thead><tr><th>' . self::t('Country') . '</th><th class="num">' . self::t('Messages') . '</th><th class="num">'
            . self::t('Conversations') . '</th><th class="num">' . self::t('Visitors') . '</th></tr></thead><tbody>';
        foreach ($countries as $c) {
            $out .= '<tr><td>' . self::link(self::to($prefix, ['country' => $c['country'] === '' ? '-' : $c['country'], 'days' => $days]), self::country($c['country'], $c['name'])) . '</td>'
                . '<td class="num">' . self::n($c['messages']) . '</td><td class="num">' . self::n($c['conversations']) . '</td><td class="num">' . self::n($c['visitors']) . '</td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    /**
     * @param list<array{email: string, country: string, conversations: int, messages: int, last: int}> $users
     */
    private static function usersPanel(string $prefix, array $users, int $days, string $timezone): string
    {
        $out = '<section class="panel"><h2>' . self::t('Signed-in users') . '</h2>';
        if ($users === []) {
            return $out . '<p class="none">' . self::t('No signed-in visitor yet. The tab "Add to your pages" shows how the chat learns who is signed in.') . '</p></section>';
        }
        $out .= '<div class="wrap"><table class="list"><thead><tr><th>' . self::t('Email') . '</th><th>' . self::t('Country') . '</th><th class="num">' . self::t('Messages') . '</th><th class="num">'
            . self::t('Conversations') . '</th><th>' . self::t('Last message') . '</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $out .= '<tr><td>' . self::link(self::to($prefix, ['email' => $u['email'], 'days' => $days]), self::e($u['email'])) . '</td>'
                . '<td class="nowrap">' . ($u['country'] !== '' ? self::link(self::to($prefix, ['country' => $u['country'], 'days' => $days]), self::country($u['country'], '')) : self::t('Unknown')) . '</td>'
                . '<td class="num">' . self::n($u['messages']) . '</td><td class="num">' . self::n($u['conversations']) . '</td><td class="nowrap">' . self::e(self::when($u['last'], $timezone)) . '</td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    /**
     * @param list<array{val: string, n: int}> $rows
     * @param callable(string): array{0: string, 1: ?string} $show the value as HTML and its link (null: no link)
     */
    private static function topPanel(string $title, string $hint, array $rows, callable $show): string
    {
        $out = '<section class="panel"><h2>' . self::e($title) . '</h2><p class="hint" style="margin:-6px 0 10px">' . self::e($hint) . '</p>';
        if ($rows === []) {
            return $out . '<p class="none">' . self::t('Nothing yet.') . '</p></section>';
        }
        $max = max(1, $rows[0]['n']);
        $out .= '<ol class="top">';
        foreach ($rows as $row) {
            [$html, $href] = $show($row['val']);
            $out .= '<li><span class="v" title="' . self::e($row['val']) . '">' . ($href !== null ? self::link($href, $html) : $html) . '</span><span class="n">' . self::n($row['n']) . '</span>'
                . '<span class="w"><span style="width:' . round($row['n'] / $max * 100, 2) . '%"></span></span></li>';
        }
        return $out . '</ol></section>';
    }

    /**
     * @param list<array{chat_id: int, ts: int, question: string}> $rows
     */
    private static function unlinkedPanel(string $prefix, array $rows, string $timezone): string
    {
        $out = '<section class="panel"><h2>' . self::t('Searched the site, answered with no link') . '</h2><p class="hint" style="margin:-6px 0 10px">'
            . self::t('What your index may be missing: the latest of these questions.') . '</p>';
        if ($rows === []) {
            return $out . '<p class="none">' . self::t('Nothing yet.') . '</p></section>';
        }
        $out .= '<ol class="top">';
        foreach ($rows as $row) {
            $out .= '<li><span class="v" title="' . self::e($row['question']) . '">' . self::link(self::to($prefix, ['c' => $row['chat_id'], 'flag' => 'unlinked']), self::e(mb_substr($row['question'], 0, 200))) . '</span>'
                . '<span class="n">' . self::e(self::when($row['ts'], $timezone)) . '</span></li>';
        }
        return $out . '</ol></section>';
    }

    /**
     * @param array{
     *     prefix: string, csrf: string, notice: string, timezone: string, search: bool,
     *     filters: array{days: int, who: string, email: string, country: string, ip: string, page: string, flag: string, q: string, link: string},
     *     after: string, rows: list<array<string, mixed>>, next: string, countries: list<array{country: string, name: string}>,
     *     chat: array{chat: array<string, mixed>, turns: list<array<string, mixed>>}|null, confirm: string
     * } $p
     */
    public static function history(array $p): string
    {
        $prefix = $p['prefix'];
        $f = $p['filters'];
        $tz = $p['timezone'];
        $kept = array_filter($f + ['after' => $p['after']], static fn ($v): bool => $v !== '' && $v !== Store::HISTORY_DAYS);
        $body = AdminView::top($prefix, $p['csrf'], $p['notice'], []) . AdminView::nav($prefix, 'history');

        // Filters
        $select = static function (string $name, string $label, array $options, string $current): string {
            $out = '<div><label for="f-' . $name . '">' . self::e($label) . '</label><select id="f-' . $name . '" name="' . $name . '">';
            foreach ($options as $value => $text) {
                $out .= '<option value="' . self::e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . self::e($text) . '</option>';
            }
            return $out . '</select></div>';
        };
        $input = static fn (string $name, string $label, string $value, string $type = 'text', string $placeholder = ''): string => '<div><label for="f-' . $name . '">' . self::e($label) . '</label>'
            . '<input type="' . $type . '" id="f-' . $name . '" name="' . $name . '" value="' . self::e($value) . '"' . ($placeholder !== '' ? ' placeholder="' . self::e($placeholder) . '"' : '') . '></div>';
        $countries = ['' => I18n::t('All countries'), '-' => I18n::t('Unknown')];
        foreach ($p['countries'] as $c) {
            $countries[$c['country']] = ($c['name'] !== '' ? $c['name'] : $c['country']) . ' (' . $c['country'] . ')';
        }
        $body .= '<form class="filters" method="get" action="' . self::e($prefix . '/admin/history') . '">'
            . $select('days', I18n::t('Period'), [1 => I18n::t('Today'), 7 => I18n::t('7 days'), 30 => I18n::t('30 days')], (string) $f['days'])
            . $select('who', I18n::t('Visitors'), ['' => I18n::t('Everyone'), 'signed' => I18n::t('Signed in'), 'anonymous' => I18n::t('Anonymous')], $f['who'])
            . $select('country', I18n::t('Country'), $countries, $f['country'])
            . $select('flag', I18n::t('Show'), ['' => I18n::t('All conversations'), 'failed' => I18n::t('With errors or timeouts'), 'unlinked' => I18n::t('With an answer with no link'), 'bad' => I18n::t('With an answer rated bad'), 'clicked' => I18n::t('With a link opened')], $f['flag'])
            . $input('email', I18n::t('Email'), $f['email'], 'email')
            . $input('ip', I18n::t('IP address'), $f['ip'])
            . $input('page', I18n::t('Page'), $f['page'], 'text', 'https://')
            . ($p['search'] ? $input('q', I18n::t('Words'), $f['q'], 'search', I18n::t('in the questions, the answers and the searches')) : '<div></div>')
            . ($f['link'] !== '' ? '<input type="hidden" name="link" value="' . self::e($f['link']) . '">' : '')
            . '<div class="actions"><button type="submit">' . self::t('Filter') . '</button>' . self::link($prefix . '/admin/history', self::t('Clear')) . '</div></form>';

        // The filters in use, each removable
        $names = ['who' => I18n::t('Visitors'), 'email' => I18n::t('Email'), 'country' => I18n::t('Country'), 'ip' => I18n::t('IP address'), 'page' => I18n::t('Page'), 'flag' => I18n::t('Show'), 'q' => I18n::t('Words'), 'link' => I18n::t('Link')];
        $chips = '';
        foreach ($names as $key => $label) {
            if ($f[$key] !== '') {
                $without = $kept;
                unset($without[$key], $without['after']);
                $chips .= '<span class="chip"><span>' . self::e($label . ': ' . ($key === 'country' && $f[$key] === '-' ? I18n::t('Unknown') : $f[$key])) . '</span>'
                    . '<a href="' . self::e(self::to($prefix, $without)) . '" aria-label="' . self::t('Remove this filter') . '">×</a></span>';
            }
        }
        if ($chips !== '') {
            $body .= '<div class="chips">' . $chips . '</div>';
        }

        // The conversations
        $body .= '<section class="panel">';
        if ($p['rows'] === []) {
            $body .= '<p class="none">' . self::t('No conversation matches.') . '</p>';
        } else {
            $base = $kept;
            unset($base['after']);
            $body .= '<div class="wrap"><table class="list"><thead><tr><th>' . self::t('Last message') . '</th><th>' . self::t('Visitor') . '</th><th>' . self::t('Place') . '</th><th>'
                . self::t('IP address') . '</th><th>' . self::t('Page') . '</th><th class="num">' . self::t('Messages') . '</th><th>' . self::t('First question') . '</th><th></th></tr></thead><tbody>';
            foreach ($p['rows'] as $r) {
                $open = self::to($prefix, $kept + ['c' => (int) $r['id']]);
                $body .= '<tr><td class="nowrap">' . self::link($open, self::e(self::when((int) $r['updated'], $tz))) . '</td>'
                    . '<td>' . ($r['email'] !== '' ? self::link(self::to($prefix, ['email' => $r['email']] + $base), self::e((string) $r['email'])) : self::t('Anonymous')) . '</td>'
                    . '<td class="nowrap">' . ($r['country'] !== '' ? self::link(self::to($prefix, ['country' => $r['country']] + $base), self::country((string) $r['country'], (string) $r['name'], (string) $r['city'])) : self::t('Unknown')) . '</td>'
                    . '<td class="nowrap">' . self::link(self::to($prefix, ['ip' => $r['ip']] + $base), self::e((string) $r['ip'])) . '</td>'
                    . '<td>' . ($r['page'] !== '' ? self::link(self::to($prefix, ['page' => $r['page']] + $base), '<span class="cut">' . self::e((string) parse_url((string) $r['page'], PHP_URL_PATH)) . '</span>') : '') . '</td>'
                    . '<td class="num">' . self::n((int) $r['questions']) . '</td>'
                    . '<td>' . self::link($open, '<span class="cut">' . self::e((string) $r['first']) . '</span>') . '</td>'
                    . '<td>' . self::marks($r) . '</td></tr>';
            }
            $body .= '</tbody></table></div>';
            $body .= '<div class="pager"><span>' . ($p['after'] !== '' ? self::link(self::to($prefix, $base), self::t('Newest')) : '') . '</span>'
                . '<span>' . ($p['next'] !== '' ? self::link(self::to($prefix, $base + ['after' => $p['next']]), self::t('Older')) : '') . '</span></div>';
        }
        $body .= '</section>';

        if ($p['chat'] !== null) {
            $body .= self::conversation($p, $kept);
        }
        return AdminView::page(I18n::t('History') . ' · Opensolr Chat Bot', $body . self::script($prefix), self::CSS);
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function marks(array $r): string
    {
        $out = '';
        if ((int) $r['failed'] > 0) {
            $out .= '<span class="badge bad">' . self::t('Errors') . '</span>';
        }
        if ((int) $r['unlinked'] > 0) {
            $out .= '<span class="badge">' . self::t('No link') . '</span>';
        }
        if ((int) $r['bad'] > 0) {
            $out .= '<span class="badge bad">' . self::t('Rated bad') . '</span>';
        }
        if ((int) $r['clicked'] > 0) {
            $out .= '<span class="badge good">' . self::t('Link opened') . '</span>';
        }
        return $out;
    }

    /**
     * The dialog with the whole conversation.
     *
     * @param array<string, mixed> $p
     * @param array<string, string|int> $kept the filters of the list behind it
     */
    private static function conversation(array $p, array $kept): string
    {
        $prefix = $p['prefix'];
        $tz = $p['timezone'];
        $c = $p['chat']['chat'];
        $id = (int) $c['id'];
        $back = self::to($prefix, $kept);
        $here = $kept + ['c' => $id];
        $base = $kept;
        unset($base['after']);

        $out = '<div class="overlay"><div class="dlg" id="conversation" role="dialog" aria-modal="true" aria-labelledby="dlg-title" tabindex="-1" data-close="' . self::e($back) . '">'
            . '<div class="dlg-head"><h2 id="dlg-title">' . self::t('Conversation') . '</h2><a class="close" href="' . self::e($back) . '">' . self::t('Close') . '</a></div>';
        $fact = static fn (string $label, string $html): string => '<div><dt>' . self::e($label) . '</dt><dd>' . $html . '</dd></div>';
        $out .= '<dl class="facts">'
            . $fact(I18n::t('Visitor'), $c['email'] !== '' ? self::link(self::to($prefix, ['email' => $c['email']] + $base), self::e((string) $c['email'])) : self::t('Anonymous'))
            . $fact(I18n::t('Place'), $c['country'] !== '' ? self::link(self::to($prefix, ['country' => $c['country']] + $base), self::country((string) $c['country'], (string) $c['name'], (string) $c['city'])) : self::t('Unknown'))
            . $fact(I18n::t('IP address'), self::link(self::to($prefix, ['ip' => $c['ip']] + $base), self::e((string) $c['ip'])))
            . $fact(I18n::t('Messages'), self::n((int) $c['questions']))
            . $fact(I18n::t('Started'), self::e(self::when((int) $c['started'], $tz)))
            . $fact(I18n::t('Last message'), self::e(self::when((int) $c['updated'], $tz)))
            . $fact(I18n::t('Language'), self::e($c['lang'] !== '' ? (Languages::all()[$c['lang']][0] ?? (string) $c['lang']) : '–'))
            . $fact(I18n::t('Page'), $c['page'] !== '' ? self::link(self::to($prefix, ['page' => $c['page']] + $base), self::e((string) parse_url((string) $c['page'], PHP_URL_PATH))) : '–')
            . '</dl>';

        // Deleting: asked once more before it is done
        $out .= '<div class="dlg-actions">' . self::link(self::to($prefix, $here + ['confirm' => 'chat']), self::t('Delete this conversation'));
        if ($c['email'] !== '') {
            $out .= self::link(self::to($prefix, $here + ['confirm' => 'email']), self::t('Delete everything of this email'));
        }
        $out .= self::link(self::to($prefix, $here + ['confirm' => 'ip']), self::t('Delete everything of this IP address')) . '</div>';
        if ($p['confirm'] !== '') {
            [$text, $value] = match ($p['confirm']) {
                'email' => [I18n::t('Delete every conversation of {email}, with all their messages?', ['email' => (string) $c['email']]), (string) $c['email']],
                'ip' => [I18n::t('Delete every conversation of the IP address {ip}, with all their messages?', ['ip' => (string) $c['ip']]), (string) $c['ip']],
                default => [I18n::t('Delete this conversation, with all its messages?'), (string) $id],
            };
            $out .= '<form class="confirm" method="post" action="' . self::e($prefix . '/admin/delete') . '"><p>' . self::e($text) . ' ' . self::t('This cannot be undone.') . '</p>'
                . '<input type="hidden" name="csrf" value="' . self::e($p['csrf']) . '"><input type="hidden" name="what" value="' . self::e($p['confirm']) . '">'
                . '<input type="hidden" name="value" value="' . self::e($value) . '"><input type="hidden" name="back" value="' . self::e(http_build_query($base)) . '">'
                . '<div class="actions" style="margin-top:12px"><button type="submit">' . self::t('Delete') . '</button>' . self::link(self::to($prefix, $here), self::t('Cancel')) . '</div></form>';
        }

        $kinds = ['question' => I18n::t('Question'), 'command' => I18n::t('Command'), 'translate' => I18n::t('Translation')];
        $outcomes = ['failed' => I18n::t('Error'), 'late' => I18n::t('Timeout'), 'limited' => I18n::t('Refused by a limit')];
        foreach ($p['chat']['turns'] as $t) {
            $out .= '<article class="turn"><div class="t-meta"><span>' . self::e(self::when((int) $t['ts'], $tz)) . '</span><span class="badge">' . self::e($kinds[$t['kind']] ?? (string) $t['kind']) . '</span>';
            if (isset($outcomes[$t['outcome']])) {
                $out .= '<span class="badge bad">' . self::e($outcomes[$t['outcome']]) . '</span>';
            }
            if ((int) $t['unlinked'] === 1) {
                $out .= '<span class="badge">' . self::t('No link') . '</span>';
            }
            if ($t['page'] !== '' && $t['page'] !== $c['page']) {
                $out .= '<span>' . self::t('on {page}', ['page' => (string) parse_url((string) $t['page'], PHP_URL_PATH)]) . '</span>';
            }
            $out .= '</div><div class="q" dir="auto">' . self::e((string) $t['question']) . '</div>';
            $searches = json_decode((string) $t['searches'], true);
            if (is_array($searches) && $searches !== []) {
                $out .= '<ul class="searches">';
                foreach ($searches as $s) {
                    if (is_array($s) && is_string($s[0] ?? null)) {
                        $tool = str_starts_with($s[0], 'opensolr_') ? substr($s[0], 9) : $s[0];
                        $out .= '<li><b>' . self::e(isset(self::TOOLS[$tool]) ? I18n::t(self::TOOLS[$tool]) : $tool) . '</b>' . (is_string($s[1] ?? null) && $s[1] !== '' ? ': ' . self::e($s[1]) : '') . '</li>';
                    }
                }
                $out .= '</ul>';
            }
            if ($t['answer'] !== '') {
                $out .= '<div class="a" dir="auto">' . AdminMarkdown::render((string) $t['answer']) . '</div>';
            }
            if ($t['error'] !== '') {
                $out .= '<p class="err">' . self::e((string) $t['error']) . '</p>';
            }
            $foot = [];
            if ((int) $t['first_ms'] > 0) {
                $foot[] = I18n::t('First word {time}', ['time' => self::seconds((int) $t['first_ms'])]);
            }
            $foot[] = I18n::t('Total {time}', ['time' => self::seconds((int) $t['total_ms'])]);
            $html = self::e(implode(' · ', $foot));
            if ((int) $t['rating'] !== 0) {
                $html .= ' · <span class="badge ' . ((int) $t['rating'] > 0 ? 'good' : 'bad') . '">' . ((int) $t['rating'] > 0 ? self::t('Rated good') : self::t('Rated bad')) . '</span>';
            }
            $clicked = json_decode((string) $t['clicked'], true);
            if (is_array($clicked) && $clicked !== []) {
                $links = [];
                foreach ($clicked as $url) {
                    if (is_string($url)) {
                        $links[] = '<a href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer">' . self::e($url) . '</a>';
                    }
                }
                $html .= ' · ' . self::t('Opened:') . ' ' . implode(', ', $links);
            }
            $out .= '<div class="t-foot">' . $html . '</div></article>';
        }
        return $out . '</div></div>';
    }
}
