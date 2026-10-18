<?php
/**
 * CreditsFormat: the arithmetic and the look of the credits line (ADR-0040). Pure functions, no I/O: CreditsLine
 * fetches and caches, this turns the API's answers into one record and one line of text.
 *
 * The API meters credits, not tokens. The pot is what the newest grant left to spend: the balance plus every credit
 * charged since the newest positive grant's UTC day (a negative grant is a refund). Tokens are a floor: what the
 * balance buys at the model's highest rate, so no mix of input and output buys fewer. Remaining figures round down.
 */
class CreditsFormat
{
    const CELLS = 10;
    /** Under this percent of the newest grant credits are low: the tmux bar turns red and the low-balance hook speaks (ADR-0050). */
    const LOW_PCT = 10;
    /** Fewer tokens than this at the highest rate is low too: a long session can send tens of thousands of tokens a turn (ADR-0050). */
    const LOW_TOKENS = 200000;

    /**
     * The record from GET /usage (days=N, grants=10) and the model rates [['id', 'input', 'output'], ...] (first =
     * the first model the endpoint lists). $model: the configured model, '' for the first one. $today: 'Y-m-d' UTC.
     */
    public static function record(array $usage, array $rates, $model, $today)
    {
        $balance = (int) $usage['balance'];
        $daily = isset($usage['daily']) && is_array($usage['daily']) ? $usage['daily'] : [];
        $grants = isset($usage['grants']) && is_array($usage['grants']) ? $usage['grants'] : [];
        $since = isset($usage['since']) && is_int($usage['since']) ? $usage['since'] : null;
        list($pot, $potApprox) = self::pot($balance, $daily, $grants, $since);
        $rate = null;
        foreach ($rates as $i => $r) {
            if ($model === '' ? $i === 0 : $r['id'] === $model) {
                $rate = $r;
                break;
            }
        }
        $tokensMin = $rate === null ? null : self::tokens($balance, max($rate['output'], $rate['input']));
        $todayCredits = 0;
        foreach ($daily as $d) {
            if (isset($d['day'], $d['credits']) && $d['day'] === $today && is_int($d['credits'])) {
                $todayCredits = $d['credits'];
            }
        }
        return [
            'balance' => $balance,
            'pot' => $pot,
            'pct_left' => self::pct($balance, $pot),
            'tokens_min' => $tokensMin,
            'model' => $rate === null ? ($model === '' ? null : $model) : $rate['id'],
            'today_credits' => $todayCredits,
            // pot: the grant is older than the usage window, so some spending is missing and the percent may read high.
            'approx' => ['pot' => $potApprox],
        ];
    }

    /** [pot, approx]: balance + credits charged on and after the newest positive grant's UTC day; [null, false] with none. */
    public static function pot($balance, array $daily, array $grants, $since = null)
    {
        $at = null;
        foreach ($grants as $g) { // newest first; a refund (negative credits) is not a pot
            if (isset($g['credits'], $g['created_at']) && is_int($g['credits']) && is_int($g['created_at']) && $g['credits'] > 0) {
                $at = $g['created_at'];
                break;
            }
        }
        if ($at === null) {
            return [null, false];
        }
        $day = gmdate('Y-m-d', $at);
        $spent = 0;
        foreach ($daily as $d) {
            if (isset($d['day'], $d['credits']) && is_string($d['day']) && is_int($d['credits']) && strcmp($d['day'], $day) >= 0) {
                $spent += $d['credits'];
            }
        }
        $sinceDay = $since === null ? null : gmdate('Y-m-d', $since);
        return [$balance + $spent, $sinceDay !== null && strcmp($day, $sinceDay) < 0];
    }

    /** The one test of "credits are low" for a record: pct_left under LOW_PCT or tokens_min under LOW_TOKENS; a missing figure never counts. */
    public static function isLow(array $r)
    {
        return (isset($r['pct_left']) && is_numeric($r['pct_left']) && $r['pct_left'] < self::LOW_PCT)
            || (isset($r['tokens_min']) && is_int($r['tokens_min']) && $r['tokens_min'] < self::LOW_TOKENS);
    }

    /** A GET /models entry's rates ['input', 'output'] (credits per million tokens, plus 'cached_input' when sent), or null when it has none usable. */
    public static function rate($m)
    {
        $c = is_array($m) && isset($m['credits_per_million_tokens']) ? $m['credits_per_million_tokens'] : null;
        if (!isset($c['input'], $c['output']) || !is_int($c['input']) || !is_int($c['output'])) {
            return null;
        }
        return ['input' => $c['input'], 'output' => $c['output']] + (isset($c['cached_input']) && is_int($c['cached_input']) ? ['cached_input' => $c['cached_input']] : []);
    }

    /** Percent left, one decimal rounded down; 0 at a zero or negative balance; null when there is no pot. */
    public static function pct($balance, $pot)
    {
        if ($balance <= 0) {
            return 0.0; // nothing left; below zero only after a refund
        }
        return $pot === null || $pot <= 0 ? null : floor($balance * 1000 / $pot) / 10;
    }

    /** Filled cells of the 10-cell bar: percent / 10 rounded down, so a full bar means 100% left; clamped to 0..10. */
    public static function cells($pct)
    {
        return (int) max(0, min(self::CELLS, floor($pct / 10)));
    }

    /** Tokens the balance buys at $rate credits per million tokens, rounded down; null without a usable rate. */
    public static function tokens($balance, $rate)
    {
        return is_int($rate) && $rate > 0 ? (int) floor(max(0, $balance) * 1000000 / $rate) : null;
    }

    /** 950 -> "950", 35000 -> "35k", 4960000 -> "4.9M": one decimal rounded down (never more than is left), a trailing ".0" dropped. */
    public static function short($n)
    {
        $a = abs($n);
        list($div, $unit) = $a >= 1000000 ? [1000000, 'M'] : ($a >= 1000 ? [1000, 'k'] : [1, '']);
        $s = $unit === '' ? (string) (int) $n : number_format(floor($n * 10 / $div) / 10, 1, '.', '');
        return (substr($s, -2) === '.0' ? substr($s, 0, -2) : $s) . $unit;
    }

    /** True when the first set of LC_ALL, LC_CTYPE, LANG names UTF-8. $env: a test seam (name => value). */
    public static function utf8Locale(array $env = null)
    {
        foreach (['LC_ALL', 'LC_CTYPE', 'LANG'] as $name) {
            $v = $env === null ? getenv($name) : (isset($env[$name]) ? $env[$name] : false);
            if ($v !== false && $v !== '') {
                return preg_match('/utf-?8/i', $v) === 1;
            }
        }
        return false;
    }

    /** One line for people. $mode: 'utf8', 'ascii' or 'tmux' (no emoji, tmux colours around the bar); $utf8: tmux may use non-ASCII characters. */
    public static function render(array $r, $mode, $utf8 = true)
    {
        $u = $mode === 'utf8' || ($mode === 'tmux' && $utf8);
        $parts = [($mode === 'utf8' ? '💳 ' : ($mode === 'ascii' ? 'cr ' : '')) . self::short($r['balance']) . ' credits'];
        if ($r['pct_left'] !== null) {
            $n = self::cells($r['pct_left']);
            $bar = $u ? str_repeat('▰', $n) . str_repeat('▱', self::CELLS - $n) : '[' . str_repeat('#', $n) . str_repeat('-', self::CELLS - $n) . ']';
            if ($mode === 'tmux') {
                $colour = $r['pct_left'] > 25 ? 'green' : ($r['pct_left'] >= self::LOW_PCT ? 'yellow' : 'red');
                $bar = "#[fg=$colour,bg=black]$bar#[default]"; // black behind the bar: tmux's default status bar is green
            }
            $approx = !empty($r['approx']['pot']) ? '~' : '';
            $parts[0] .= ' ' . $bar . ' ' . $approx . (int) max(0, min(100, floor($r['pct_left']))) . '% left';
        }
        if ($r['tokens_min'] !== null) {
            $parts[] = ($u ? '≥' : '>=') . self::short($r['tokens_min']) . ' tokens';
        }
        $parts[] = 'today ' . self::short($r['today_credits']);
        return implode($u ? ' · ' : ' | ', $parts);
    }
}
