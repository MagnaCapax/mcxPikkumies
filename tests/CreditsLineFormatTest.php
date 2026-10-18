<?php
/**
 * CreditsFormat (ADR-0040): the bar, the percent, the pot, the token floor, the short numbers, the locale fallback
 * and the three looks of the credits line. Pure functions, no network. Every remaining figure rounds down.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/credits/CreditsFormat.php';

/** A record as CreditsFormat::record makes it, for the render tests. */
function creditsFormatRecord($balance, $pct, $tokensMin = 1175000, $today = 35000, $potApprox = false)
{
    return ['balance' => $balance, 'pot' => 1, 'pct_left' => $pct, 'tokens_min' => $tokensMin, 'model' => 'default', 'today_credits' => $today, 'approx' => ['pot' => $potApprox]];
}

return [
    'credits format: the bar has 10 cells, percent/10 rounded down (a full bar is 100% left) and clamped to 0..10' => function () {
        $want = ['0' => 0, '9.9' => 0, '10' => 1, '19.9' => 1, '20' => 2, '94' => 9, '99.9' => 9, '100' => 10, '150' => 10, '-20' => 0];
        foreach ($want as $pct => $cells) {
            check(CreditsFormat::cells((float) $pct) === $cells, "cells($pct) = " . CreditsFormat::cells((float) $pct) . ", want $cells");
        }
    },
    'credits format: percent left is balance over pot, one decimal rounded down; 0 at or below a zero balance; no pot gives none' => function () {
        check(CreditsFormat::pct(4700000, 5000000) === 94.0, 'pct 94');
        check(CreditsFormat::pct(1, 3) === 33.3 && CreditsFormat::pct(2, 3) === 66.6, 'rounded down: ' . var_export(CreditsFormat::pct(2, 3), true));
        check(CreditsFormat::pct(4999999, 5000000) === 99.9, 'never 100 before it is: ' . var_export(CreditsFormat::pct(4999999, 5000000), true));
        check(CreditsFormat::pct(-500, 1000) === 0.0 && CreditsFormat::pct(-500, null) === 0.0 && CreditsFormat::pct(0, 1000) === 0.0, 'nothing left is 0%');
        check(CreditsFormat::pct(6000, 5000) === 120.0, 'over 100 is kept in the record');
        foreach ([null, 0, -10] as $pot) {
            check(CreditsFormat::pct(100, $pot) === null, 'no percent for pot ' . var_export($pot, true));
        }
    },
    'credits format: the pot is the balance plus what was charged since the newest positive grant\'s UTC day; refunds are skipped' => function () {
        $grantAt = gmmktime(15, 0, 0, 10, 10, 2026);
        $grant = ['credits' => 1000, 'created_at' => $grantAt];
        $refund = ['credits' => -300, 'created_at' => gmmktime(9, 0, 0, 10, 12, 2026)];
        $daily = [['day' => '2026-10-09', 'credits' => 999], ['day' => '2026-10-10', 'credits' => 100], ['day' => '2026-10-12', 'credits' => 50]];
        check(CreditsFormat::pot(850, $daily, [$grant], gmmktime(0, 0, 0, 1, 1, 2026)) === [1000, false], 'pot: ' . json_encode(CreditsFormat::pot(850, $daily, [$grant])));
        check(CreditsFormat::pot(550, $daily, [$refund, $grant]) === [700, false], 'a refund is not the pot: ' . json_encode(CreditsFormat::pot(550, $daily, [$refund, $grant])));
        check(CreditsFormat::pot(850, $daily, []) === [null, false] && CreditsFormat::pot(850, $daily, [$refund]) === [null, false], 'no positive grant: no pot');
        check(CreditsFormat::pot(850, $daily, [$grant], gmmktime(0, 0, 0, 10, 11, 2026)) === [1000, true], 'a grant older than the window is approximate');
        $r = CreditsFormat::record(['balance' => 850, 'daily' => $daily, 'grants' => []], [], '', '2026-10-12');
        check($r['pot'] === null && $r['pct_left'] === null && $r['today_credits'] === 50, 'record without grants: ' . json_encode($r));
        $line = CreditsFormat::render($r, 'utf8');
        check($line === '💳 850 credits · today 50' && strpos($line, '▰') === false && strpos($line, '%') === false, 'no grants: no bar and no percent: ' . $line);
    },
    'credits format: tokens are a floor, balance x 1e6 / the model\'s highest rate, rounded down; none without rates' => function () {
        check(CreditsFormat::tokens(4700000, 4000000) === 1175000, 'floor');
        check(CreditsFormat::tokens(10, 3000000) === 3, 'rounded down, never up');
        check(CreditsFormat::tokens(-500, 4000000) === 0, 'a negative balance buys no tokens');
        check(CreditsFormat::tokens(100, 0) === null && CreditsFormat::tokens(100, null) === null, 'no usable rate, no figure');
        $rates = [['id' => 'a', 'input' => 1000000, 'output' => 4000000], ['id' => 'b', 'input' => 500000, 'output' => 2000000], ['id' => 'odd', 'input' => 8000000, 'output' => 4000000]];
        $first = CreditsFormat::record(['balance' => 1000000], $rates, '', '2026-10-12');
        $named = CreditsFormat::record(['balance' => 1000000], $rates, 'b', '2026-10-12');
        $odd = CreditsFormat::record(['balance' => 1000000], $rates, 'odd', '2026-10-12');
        $unknown = CreditsFormat::record(['balance' => 1000000], $rates, 'zz', '2026-10-12');
        check($first['model'] === 'a' && $first['tokens_min'] === 250000 && $named['tokens_min'] === 500000, 'model choice: ' . json_encode([$first, $named]));
        check($odd['tokens_min'] === 125000, 'input dearer than output: the input rate is the floor: ' . json_encode($odd));
        check($unknown['tokens_min'] === null && $unknown['model'] === 'zz', 'an unlisted model gives no figure: ' . json_encode($unknown));
    },
    'credits format: numbers are shortened to k and M with one decimal rounded down, a trailing .0 dropped' => function () {
        $want = [0 => '0', 999 => '999', 1000 => '1k', 1250 => '1.2k', 35000 => '35k', 999999 => '999.9k', 1000000 => '1M', 1234567 => '1.2M',
            4960000 => '4.9M', 4999999 => '4.9M', 2500000000 => '2500M', -1500 => '-1.5k', -1550 => '-1.6k'];
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
    'credits format: the line in UTF-8, ASCII and tmux form; an approximate pot marks the percent with ~' => function () {
        $r = creditsFormatRecord(4700000, 94.0);
        check(CreditsFormat::render($r, 'utf8') === '💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k', 'utf8: ' . CreditsFormat::render($r, 'utf8'));
        check(CreditsFormat::render($r, 'ascii') === 'cr 4.7M credits [#########-] 94% left | >=1.1M tokens | today 35k', 'ascii: ' . CreditsFormat::render($r, 'ascii'));
        check(CreditsFormat::render($r, 'tmux', false) === '4.7M credits #[fg=green,bg=black][#########-]#[default] 94% left | >=1.1M tokens | today 35k', 'tmux ascii: ' . CreditsFormat::render($r, 'tmux', false));
        check(strpos(CreditsFormat::render(creditsFormatRecord(4700000, 94.0, 1175000, 35000, true), 'utf8'), '▱ ~94% left ·') !== false, 'approximate pot: ~');
        check(strpos(CreditsFormat::render(creditsFormatRecord(10, 99.9), 'utf8'), '▰▰▰▰▰▰▰▰▰▱ 99% left') !== false, '99.9% is not shown as 100%');
        check(strpos(CreditsFormat::render(creditsFormatRecord(10, 130.0), 'utf8'), '▰▰▰▰▰▰▰▰▰▰ 100% left') !== false, 'over 100 shows a full bar and 100%');
        $colours = ['25.1' => 'green', '25' => 'yellow', '10' => 'yellow', '9.9' => 'red', '0' => 'red'];
        foreach ($colours as $pct => $colour) {
            $line = CreditsFormat::render(creditsFormatRecord(100, (float) $pct), 'tmux');
            check(strpos($line, "#[fg=$colour,bg=black]") === 0 + strlen('100 credits ') && strpos($line, '💳') === false, "tmux at $pct% must be $colour, no emoji: $line");
        }
    },
];
