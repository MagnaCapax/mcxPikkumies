<?php
/**
 * GoalReviewPrompt: the goal review's one prompt, never more than MAX_TOKENS estimated tokens with the system text
 * (ADR-0096). est() is bytes / 3, rounded up: pessimistic on purpose (prose runs near 4 bytes a token, code and JSON
 * near 3). Filled in this order: every request whole; the skills invoked; the offered tools and pages (dropped first
 * when tight); then, from the newest back, tool calls and agent texts (text gets at most TEXT_MAX, or 15 % of what is
 * left, and anything the tools leave), all merged oldest first with a U line where a request arrived. When the requests
 * alone do not fit, whole requests are left out, oldest first from R2; R1 (usually the main goal) and the newest
 * KEEP_NEWEST always stay, and a marker says how many were left out. Only when those kept requests still do not fit is
 * the longest one cut, marked `[cut for the 30k cap]`; a final pass drops the oldest activity lines until the whole
 * prompt fits. The shown requests are numbered R1..Rk (k at most MAX_SHOWN, the parser's three digits); 'offered' maps
 * each shown number back to the request's own. The data sits between two lines carrying a fresh random marker, which is
 * removed from the data itself.
 */
require_once __DIR__ . '/GoalReviewWindow.php';
require_once __DIR__ . '/HookRecords.php';

class GoalReviewPrompt
{
    const MAX_TOKENS = 30000;  // the whole prompt (operator, 2026-10-20)
    const HEADERS = 100;
    const FLOOR = 4000;        // kept for the activity
    const CANDIDATES_MAX = 800;
    const TEXT_MAX = 3000;
    const KEEP_NEWEST = 3;
    const MAX_SHOWN = 999;
    const U_LINE = 10;         // estimated tokens a U line may take ("U (a request left out above)")
    const CUT = ' [cut for the 30k cap]';

    public static function est($s)
    {
        return intdiv(strlen((string) $s) + 2, 3);
    }

    /**
     * ['text' => the user message, 'offered' => [shown number => request number], 'omitted' => count, 'docs', 'tools'
     * (the candidates kept), 'est' => estimated tokens of system + text, at most MAX_TOKENS].
     */
    public static function build(array $w, array $docs, array $tools, $nonce, $system)
    {
        $budget = self::MAX_TOKENS - self::est($system) - self::HEADERS;
        $skills = '';
        foreach ($w['skills'] as $name => $head) {
            $skills .= "[$name] $head\n";
        }
        $ids = array_keys($w['requests']);
        $omit = [];
        $over = function () use ($w, &$omit, $skills, $budget) {
            $rest = array_diff_key($w['requests'], $omit);
            $bytes = strlen($skills);
            foreach ($rest as $r) {
                $bytes += strlen($r['text']) + strlen($r['after']) + 40;
            }
            return count($rest) > self::MAX_SHOWN || intdiv($bytes + 2, 3) > $budget - self::FLOOR;
        };
        foreach (array_slice($ids, 1, max(0, count($ids) - 1 - self::KEEP_NEWEST)) as $n) { // R2 .. the 4th newest
            if (!$over()) {
                break;
            }
            $omit[$n] = true;
        }
        $shown = [];   // shown number => request number
        $lines = [];   // shown number => line
        foreach (array_diff_key($w['requests'], $omit) as $n => $r) {
            $k = count($shown) + 1;
            $shown[$k] = $n;
            $lines[$k] = self::request($k, $r);
        }
        $lines = self::fitForced($lines, $budget - self::FLOOR - self::est($skills));
        $req = self::marker($lines, $shown, count($omit));
        $cand = self::candidates($docs, $tools);
        $left = $budget - self::est($req . $skills) - self::U_LINE * count($w['requests']);
        if (self::est($cand) > self::CANDIDATES_MAX || $left - self::est($cand) < self::FLOOR) {
            list($cand, $docs, $tools) = ['', [], []];
        }
        $rows = self::activity($w, max(0, $left - self::est($cand)), $omit, array_flip($shown));
        do { // the final word on the cap: the oldest activity line goes until the whole prompt fits
            $data = ($omit ? 'Requests shown: ' . count($shown) . ' of ' . count($ids) . "\n" : '')
                . ($skills !== '' ? "Skills the user invoked:\n$skills" : '')
                . "Requests (oldest first; the user's own words, whole):\n$req\n$cand"
                . "Recent activity (oldest first; U = a request arrives, T = tool call, A = agent text):\n" . implode('', $rows);
            $text = "Marker: $nonce\n<<<DATA-$nonce\n" . str_replace($nonce, '', $data) . ">>>END-$nonce";
            $est = self::est($system) + self::est($text);
        } while ($est > self::MAX_TOKENS && array_shift($rows) !== null);
        return ['text' => $text, 'offered' => $shown, 'omitted' => count($omit), 'docs' => $docs, 'tools' => $tools, 'est' => $est];
    }

    private static function request($k, array $r)
    {
        return "R$k" . ($r['skill'] !== '' ? " [skill {$r['skill']}]" : '') . ': ' . GoalReviewWindow::line($r['text'], 1000000)
            . ($r['cut'] > 0 ? " [stored cut at 12000 of {$r['cut']} bytes]" : '') . ($r['after'] !== '' ? ' after agent: "' . $r['after'] . '"' : '');
    }

    /** The kept request lines within $budget estimated tokens: the longest is cut (and marked) until they fit. */
    private static function fitForced(array $lines, $budget)
    {
        for ($guard = 0; $guard < 2 * count($lines) && self::est(implode("\n", $lines)) > $budget; $guard++) {
            $len = array_map('strlen', $lines);
            $k = array_search(max($len), $len, true);
            $excess = (self::est(implode("\n", $lines)) - $budget) * 3 + strlen(self::CUT) + 8;
            if ($len[$k] - $excess < 200) {
                $lines[$k] = HookRecords::cutBytes($lines[$k], 200) . self::CUT; // the rest is the activity pass's
                continue;
            }
            $lines[$k] = HookRecords::cutBytes($lines[$k], $len[$k] - $excess) . self::CUT;
        }
        return $lines;
    }

    /** The shown request lines, with `[8 earlier requests left out whole]` after R1 when some were left out. */
    private static function marker(array $lines, array $shown, $omitted)
    {
        if ($omitted > 0 && count($lines) > 1) {
            $first = array_slice($lines, 0, 1, true);
            $lines = $first + ['m' => "[$omitted earlier request" . ($omitted > 1 ? 's' : '') . ' left out whole]'] + array_slice($lines, 1, null, true);
        }
        return implode("\n", $lines);
    }

    private static function candidates(array $docs, array $tools)
    {
        $out = $tools ? "Offered tools:\n" : '';
        foreach ($tools as $id => $t) {
            $out .= "$id: {$t[0]}\n";
        }
        $out .= $docs ? "Offered pages:\n" : '';
        foreach ($docs as $id => $d) {
            $out .= "$id: {$d[0]}\n";
        }
        return $out;
    }

    /**
     * Tool lines then agent texts, newest first, within $left estimated tokens; merged oldest first with U lines
     * ($number: request number => shown number). Lines, each ending in "\n".
     */
    private static function activity(array $w, $left, array $omit, array $number)
    {
        $textShare = min(self::TEXT_MAX, intdiv($left * 15, 100));
        $budget = ['T' => $left - $textShare, 'A' => 0];
        $keep = [];
        foreach (['T', 'A'] as $kind) {
            $budget['A'] += $kind === 'A' ? $textShare : 0; // what the tools did not use flows to the texts
            for ($i = count($w['activity']) - 1; $i >= 0; $i--) {
                $a = $w['activity'][$i];
                if ($a['kind'] !== $kind) {
                    continue;
                }
                $cost = self::est($a['text']) + 4;
                if ($cost > $budget[$kind]) {
                    break; // newest first: an older one never takes the place of a newer one
                }
                $budget[$kind] -= $cost;
                $keep[$i] = "{$a['kind']}: {$a['text']}";
            }
            if ($kind === 'T') {
                $budget['A'] += $budget['T'];
            }
        }
        ksort($keep);
        $first = $keep ? $w['activity'][key($keep)]['seq'] : PHP_INT_MAX;
        $rows = [];
        foreach ($w['requests'] as $n => $r) {
            if ($r['seq'] > $first) {
                $rows[] = [$r['seq'], -1, isset($omit[$n]) || !isset($number[$n]) ? 'U (a request left out above)' : 'U R' . $number[$n]];
            }
        }
        foreach ($keep as $i => $l) {
            $rows[] = [$w['activity'][$i]['seq'], $i, $l];
        }
        usort($rows, function ($a, $b) {
            return $a[0] - $b[0] ?: $a[1] - $b[1]; // by seq, then a request before activity, then in record order
        });
        return array_map(function ($r) {
            return $r[2] . "\n";
        }, $rows);
    }
}
