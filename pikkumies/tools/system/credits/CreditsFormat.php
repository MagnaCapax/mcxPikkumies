<?php
/**
 * CreditsFormat: the arithmetic and the look of the credits line (ADR-0040). Pure functions, no I/O: CreditsLine
 * fetches and caches, this turns the API's answers into one record and one line of text.
 *
 * The API meters credits, not tokens. The pot is what the newest grant left to spend: the balance plus every credit
 * charged since that grant's UTC day. Tokens are an estimate from the model's rates, so they are always shown with ≈.
 */
class CreditsFormat
{
    const CELLS = 10;

    /**
     * The record from GET /usage (days=N, grants=1) and the model rates [['id', 'input', 'output'], ...] (first =
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
        list($low, $high) = $rate === null ? [null, null] : self::tokens($balance, $rate['output'], $rate['input']);
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
            'tokens_low' => $low,
            'tokens_high' => $high,
            'model' => $rate === null ? ($model === '' ? null : $model) : $rate['id'],
            'today_credits' => $todayCredits,
            // tokens: always an estimate; pot: the newest grant is older than the usage window, so some spending is missing.
            'approx' => ['tokens' => true, 'pot' => $potApprox],
        ];
    }

    /** [pot, approx]: balance + credits charged on and after the newest grant's UTC day; [null, false] with no grant. */
    public static function pot($balance, array $daily, array $grants, $since = null)
    {
        if (!$grants || !isset($grants[0]['created_at']) || !is_int($grants[0]['created_at'])) {
            return [null, false];
        }
        $at = $grants[0]['created_at'];
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

    /** Percent left, one decimal; null when there is no pot to measure against. */
    public static function pct($balance, $pot)
    {
        return $pot === null || $pot <= 0 ? null : round($balance * 100 / $pot, 1);
    }

    /** Filled cells of the 10-cell bar: percent / 10 rounded half up, clamped to 0..10. */
    public static function cells($pct)
    {
        return (int) max(0, min(self::CELLS, floor($pct / 10 + 0.5 + 1e-9)));
    }

    /** [low, high] tokens the balance buys: at the output rate and at the input rate (credits per million tokens). */
    public static function tokens($balance, $outputRate, $inputRate)
    {
        $at = function ($rate) use ($balance) {
            return is_int($rate) && $rate > 0 ? (int) floor(max(0, $balance) * 1000000 / $rate) : null;
        };
        return [$at($outputRate), $at($inputRate)];
    }

    /** 950 -> "950", 35000 -> "35k", 1234567 -> "1.2M": one decimal, a trailing ".0" dropped. */
    public static function short($n)
    {
        $sign = $n < 0 ? '-' : '';
        $a = abs($n);
        $unit = '';
        if (round($a / 1000, 1) >= 1000) {
            $a = $a / 1000000;
            $unit = 'M';
        } elseif ($a >= 1000) {
            $a = $a / 1000;
            $unit = 'k';
        }
        $s = $unit === '' ? (string) (int) round($a) : number_format(floor($a * 10 + 0.5) / 10, 1, '.', '');
        return $sign . (substr($s, -2) === '.0' ? substr($s, 0, -2) : $s) . $unit;
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
                $colour = $r['pct_left'] > 25 ? 'green' : ($r['pct_left'] >= 10 ? 'yellow' : 'red');
                $bar = "#[fg=$colour,bg=black]$bar#[default]"; // black behind the bar: tmux's default status bar is green
            }
            $parts[0] .= ' ' . $bar . ' ' . (int) max(0, min(100, floor($r['pct_left'] + 0.5))) . '% left';
        }
        if ($r['tokens_low'] !== null && $r['tokens_high'] !== null) {
            $parts[] = ($u ? '≈' : '~') . self::short($r['tokens_low']) . ($u ? '–' : '-') . self::short($r['tokens_high']) . ' tokens';
        }
        $parts[] = 'today ' . self::short($r['today_credits']);
        return implode($u ? ' · ' : ' | ', $parts);
    }
}
