<?php
/**
 * CreditsFormat (ADR-0040): the bar, the percent, the pot, the token estimate, the short numbers, the locale fallback
 * and the three looks of the credits line. Pure functions, no network.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsFormat.php';

/** A record as CreditsFormat::record makes it, for the render tests. */
function creditsFormatRecord($balance, $pct, $low = 1175000, $high = 4700000, $today = 35000)
{
    return ['balance' => $balance, 'pot' => 1, 'pct_left' => $pct, 'tokens_low' => $low, 'tokens_high' => $high, 'model' => 'default', 'today_credits' => $today, 'approx' => ['tokens' => true, 'pot' => false]];
}

return [
    'credits format: the bar has 10 cells, percent/10 rounded half up and clamped to 0..10' => function () {
        $want = ['0' => 0, '4.9' => 0, '5' => 1, '9.4' => 1, '9.5' => 1, '10' => 1, '14.9' => 1, '15' => 2, '94' => 9, '94.9' => 9, '95' => 10, '99' => 10, '100' => 10, '150' => 10, '-20' => 0];
        foreach ($want as $pct => $cells) {
            check(CreditsFormat::cells((float) $pct) === $cells, "cells($pct) = " . CreditsFormat::cells((float) $pct) . ", want $cells");
        }
    },
    'credits format: percent left is balance over pot with one decimal; no pot, or a pot of zero or less, gives none' => function () {
        check(CreditsFormat::pct(4700000, 5000000) === 94.0, 'pct 94');
        check(CreditsFormat::pct(1, 3) === 33.3, 'one decimal: ' . var_export(CreditsFormat::pct(1, 3), true));
        check(CreditsFormat::pct(-500, 1000) === -50.0, 'a negative balance is a negative percent');
        check(CreditsFormat::pct(6000, 5000) === 120.0, 'over 100 is kept in the record');
        foreach ([null, 0, -10] as $pot) {
            check(CreditsFormat::pct(100, $pot) === null, 'no percent for pot ' . var_export($pot, true));
        }
    },
    'credits format: the pot is the balance plus what was charged on and after the newest grant\'s UTC day; no grants, no pot' => function () {
        $grantAt = gmmktime(15, 0, 0, 10, 10, 2026);
        $daily = [['day' => '2026-10-09', 'credits' => 999], ['day' => '2026-10-10', 'credits' => 100], ['day' => '2026-10-12', 'credits' => 50]];
        check(CreditsFormat::pot(850, $daily, [['credits' => 1000, 'created_at' => $grantAt]], gmmktime(0, 0, 0, 1, 1, 2026)) === [1000, false], 'pot: ' . json_encode(CreditsFormat::pot(850, $daily, [['credits' => 1000, 'created_at' => $grantAt]])));
        check(CreditsFormat::pot(850, $daily, []) === [null, false], 'no grants: no pot');
        check(CreditsFormat::pot(850, $daily, [['credits' => 1000, 'created_at' => $grantAt]], gmmktime(0, 0, 0, 10, 11, 2026)) === [1000, true], 'a grant older than the window is approximate');
        $r = CreditsFormat::record(['balance' => 850, 'daily' => $daily, 'grants' => []], [], '', '2026-10-12');
        check($r['pot'] === null && $r['pct_left'] === null && $r['today_credits'] === 50, 'record without grants: ' . json_encode($r));
        $line = CreditsFormat::render($r, 'utf8');
        check($line === '💳 850 credits · today 50' && strpos($line, '▰') === false && strpos($line, '%') === false, 'no grants: no bar and no percent: ' . $line);
    },
    'credits format: tokens are balance x 1e6 / output rate (low) and / input rate (high), rounded down; none without rates' => function () {
        check(CreditsFormat::tokens(4700000, 4000000, 1000000) === [1175000, 4700000], 'range');
        check(CreditsFormat::tokens(10, 3000000, 3000000) === [3, 3], 'rounded down, never up: ' . json_encode(CreditsFormat::tokens(10, 3000000, 3000000)));
        check(CreditsFormat::tokens(-500, 4000000, 1000000) === [0, 0], 'a negative balance buys no tokens');
        check(CreditsFormat::tokens(100, 0, null) === [null, null], 'no usable rate, no estimate');
        $rates = [['id' => 'a', 'input' => 1000000, 'output' => 4000000], ['id' => 'b', 'input' => 500000, 'output' => 2000000]];
        $first = CreditsFormat::record(['balance' => 1000000], $rates, '', '2026-10-12');
        $named = CreditsFormat::record(['balance' => 1000000], $rates, 'b', '2026-10-12');
        $unknown = CreditsFormat::record(['balance' => 1000000], $rates, 'zz', '2026-10-12');
        check($first['model'] === 'a' && $first['tokens_low'] === 250000 && $named['tokens_high'] === 2000000, 'model choice: ' . json_encode([$first, $named]));
        check($unknown['tokens_low'] === null && $unknown['model'] === 'zz', 'an unlisted model gives no estimate: ' . json_encode($unknown));
    },
    'credits format: numbers are shortened to k and M with one decimal, a trailing .0 dropped' => function () {
        $want = [0 => '0', 999 => '999', 1000 => '1k', 1250 => '1.3k', 35000 => '35k', 999949 => '999.9k', 999950 => '1M', 1234567 => '1.2M', 4960000 => '5M', 2500000000 => '2500M', -1500 => '-1.5k'];
        foreach ($want as $n => $s) {
            check(CreditsFormat::short($n) === $s, "short($n) = " . CreditsFormat::short($n) . ", want $s");
        }
    },
    'credits format: UTF-8 is read from the first set of LC_ALL, LC_CTYPE, LANG' => function () {
        check(CreditsFormat::utf8Locale(['LANG' => 'en_US.UTF-8']) === true, 'LANG utf-8');
        check(CreditsFormat::utf8Locale(['LANG' => 'fi_FI.utf8']) === true, 'utf8 spelling');
        check(CreditsFormat::utf8Locale(['LC_ALL' => 'C', 'LANG' => 'en_US.UTF-8']) === false, 'LC_ALL wins');
        check(CreditsFormat::utf8Locale(['LC_ALL' => '', 'LC_CTYPE' => 'C.UTF-8', 'LANG' => 'C']) === true, 'an empty LC_ALL is not set');
        check(CreditsFormat::utf8Locale([]) === false && CreditsFormat::utf8Locale(['LANG' => 'POSIX']) === false, 'no UTF-8 locale: ASCII');
    },
    'credits format: the line in UTF-8, ASCII and tmux form; a negative balance shows 0% and an empty bar' => function () {
        $r = creditsFormatRecord(4700000, 94.0);
        check(CreditsFormat::render($r, 'utf8') === '💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≈1.2M–4.7M tokens · today 35k', 'utf8: ' . CreditsFormat::render($r, 'utf8'));
        check(CreditsFormat::render($r, 'ascii') === 'cr 4.7M credits [#########-] 94% left | ~1.2M-4.7M tokens | today 35k', 'ascii: ' . CreditsFormat::render($r, 'ascii'));
        check(CreditsFormat::render($r, 'tmux', false) === '4.7M credits #[fg=green,bg=black][#########-]#[default] 94% left | ~1.2M-4.7M tokens | today 35k', 'tmux ascii: ' . CreditsFormat::render($r, 'tmux', false));
        $neg = CreditsFormat::render(creditsFormatRecord(-1500, -30.0, 0, 0, 0), 'utf8');
        check($neg === '💳 -1.5k credits ▱▱▱▱▱▱▱▱▱▱ 0% left · ≈0–0 tokens · today 0', 'negative: ' . $neg);
        check(strpos(CreditsFormat::render(creditsFormatRecord(10, 130.0), 'utf8'), '▰▰▰▰▰▰▰▰▰▰ 100% left') !== false, 'over 100 shows a full bar and 100%');
        $colours = ['25.1' => 'green', '25' => 'yellow', '10' => 'yellow', '9.9' => 'red', '0' => 'red'];
        foreach ($colours as $pct => $colour) {
            $line = CreditsFormat::render(creditsFormatRecord(100, (float) $pct), 'tmux');
            check(strpos($line, "#[fg=$colour,bg=black]") === 0 + strlen('100 credits ') && strpos($line, '💳') === false, "tmux at $pct% must be $colour, no emoji: $line");
        }
    },
];
