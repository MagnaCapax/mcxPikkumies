<?php
/**
 * GoalReviewWindow: what the goal review reads from the whole spool (ADR-0096, from ADR-0071). read() gives every
 * request the user made, whole (a user record that is not a row Hermes added, not Pikkumies' own resume text and not a
 * delegated agent's, short ones included: a garbled "fx teh thng" is exactly what the clarity check is for), each with
 * its seq and time, its skill and the cut it was stored with, and for a request under SHORT_WORDS words the end of the
 * agent text just before it, so "yes do it" reads in context; the skills invoked; and the activity: one line per tool
 * call and the start of each agent text, oldest first. candidates(): free tools and pages the model may point to, up to
 * 8 of each, none the session already used. lib/GoalReviewPrompt.php fits them into the prompt.
 */
require_once __DIR__ . '/UserTurn.php';
require_once __DIR__ . '/DocSources.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/Generate.php';

class GoalReviewWindow
{
    const CANDIDATES = 8;
    const SHORT_WORDS = 12;
    const AFTER = 300;     // bytes of the agent text shown with a short request
    const TOOL_LINE = 240;
    const TEXT_LINE = 600;
    const FILE_TOOLS = ['read_file', 'write_file', 'patch', 'search_files'];

    /**
     * ['requests' => [n => ['n', 'text', 'seq', 'ts', 'skill', 'cut', 'after']] (n from 1, oldest first), 'skills' =>
     * [name => head] (newest last), 'activity' => [['seq', 'kind' T|A, 'text']], 'scheduled' => bool, 'used' => args text].
     */
    public static function read(array $records)
    {
        $w = ['requests' => [], 'skills' => [], 'activity' => [], 'scheduled' => false, 'used' => ''];
        $lastText = '';
        foreach ($records as $r) {
            if (!is_array($r)) {
                continue;
            }
            $kind = isset($r['kind']) ? $r['kind'] : '';
            $text = isset($r['text']) && is_string($r['text']) ? $r['text'] : '';
            $seq = isset($r['seq']) && is_int($r['seq']) ? $r['seq'] : 0;
            $w['scheduled'] = $w['scheduled'] || !empty($r['scheduled']);
            if ($kind === 'user' && empty($r['synthetic']) && empty($r['subagent']) && trim($text) !== '' && !UserTurn::ownResume($text)) {
                $n = count($w['requests']) + 1;
                $skill = isset($r['skill']) && is_string($r['skill']) ? self::line($r['skill'], 120) : '';
                $w['requests'][$n] = ['n' => $n, 'text' => $text, 'seq' => $seq, 'ts' => isset($r['ts']) && is_string($r['ts']) ? (int) strtotime($r['ts']) : 0,
                    'skill' => $skill, 'cut' => isset($r['cut']) && is_int($r['cut']) ? $r['cut'] : 0,
                    'after' => count(UserTurn::words($text)) < self::SHORT_WORDS && $lastText !== '' ? self::tail($lastText, self::AFTER) : ''];
                if ($skill !== '') {
                    unset($w['skills'][$skill]);
                    $w['skills'][$skill] = isset($r['skill_head']) && is_string($r['skill_head']) ? self::line($r['skill_head'], 600) : '';
                }
            } elseif ($kind === 'assistant' || $kind === 'reply') {
                $lastText = empty($r['subagent']) ? $text : $lastText;
                $w['activity'][] = ['seq' => $seq, 'kind' => 'A', 'text' => self::line((empty($r['subagent']) ? '' : '(delegated) ') . $text, self::TEXT_LINE)];
            } elseif ($kind === 'tool') {
                $args = isset($r['args']) ? (string) $r['args'] : '';
                $w['used'] .= "\n" . $args;
                $w['activity'][] = ['seq' => $seq, 'kind' => 'T', 'text' => self::toolLine(isset($r['tool']) ? (string) $r['tool'] : '?', $args, $r)];
            }
        }
        $w['skills'] = array_slice($w['skills'], -4, 4, true);
        return $w;
    }

    /** How many tool calls came after request $n (all of them for 0). */
    public static function toolsSince(array $w, $n)
    {
        $after = $n > 0 && isset($w['requests'][$n]) ? $w['requests'][$n]['seq'] : 0;
        return count(array_filter($w['activity'], function ($a) use ($after) {
            return $a['kind'] === 'T' && $a['seq'] > $after;
        }));
    }

    /** `terminal: <command>`, `<file tool>: <path>`, else `<tool> <args>`, with (exit N) or (error); one line of TOOL_LINE bytes. */
    private static function toolLine($tool, $args, array $r)
    {
        $a = json_decode($args, true);
        if ($tool === 'terminal' && isset($a['command']) && is_string($a['command'])) {
            $line = "terminal: {$a['command']}";
        } elseif (in_array($tool, self::FILE_TOOLS, true) && isset($a['path']) && is_string($a['path'])) {
            $line = "$tool: {$a['path']}";
        } else {
            $line = "$tool $args";
        }
        $status = isset($r['exit_code']) && is_int($r['exit_code']) && $r['exit_code'] !== 0 ? " (exit {$r['exit_code']})"
            : (isset($r['status']) && $r['status'] !== 'ok' && $r['status'] !== '' ? ' (error)' : '');
        return self::line($line, self::TOOL_LINE - strlen($status)) . $status;
    }

    /** [D-id => [title, path]] and [T-id => [name, script]]: free candidates for the requests, none the session already used. */
    public static function candidates(array $w, array $config, $toolsDir = null)
    {
        $query = self::cut(implode(' ', array_column($w['requests'], 'text')), 1000);
        $docs = [];
        list($hits) = DocSources::search($query, self::CANDIDATES, true, 0.3); // free text: pages sharing a third of its words
        foreach ($hits as $h) {
            if (count($docs) < self::CANDIDATES && strpos($w['used'], $h[0]) === false) {
                $docs['D' . (count($docs) + 1)] = [self::line($h[1], 120), Config::shown($h[0])];
            }
        }
        $want = array_diff(array_unique(array_filter(UserTurn::words($query), function ($x) {
            return strlen($x) >= 4;
        })), UserTurn::FILLER);
        $scored = [];
        $off = Generate::disabledCategories($config);
        foreach (Units::all($toolsDir) as $u) {
            if ($u['errors'] || !is_file($u['script']) || array_filter($off, function ($c) use ($u) { return Tree::within($u['category'], $c); })
                || strpos($w['used'], $u['name'] . '.php') !== false) {
                continue;
            }
            $score = count(array_intersect($want, UserTurn::words($u['name'] . ' ' . $u['meta']['description'])));
            if ($score > 0) {
                $scored[] = [$score, $u['name'], Config::shown($u['script'])];
            }
        }
        usort($scored, function ($a, $b) {
            return $b[0] - $a[0] ?: strcmp($a[1], $b[1]);
        });
        $tools = [];
        foreach (array_slice($scored, 0, self::CANDIDATES) as $i => $s) {
            $tools['T' . ($i + 1)] = [$s[1], $s[2]];
        }
        return [$docs, $tools];
    }

    /** One line, at most $max characters. */
    public static function line($text, $max)
    {
        $flat = preg_replace('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]+/u', ' ', (string) $text);
        return self::cut(trim(preg_replace('/ {2,}/', ' ', $flat === null ? str_replace(["\r", "\n"], ' ', (string) $text) : $flat)), $max);
    }

    public static function cut($s, $max)
    {
        return strlen($s) <= $max ? $s : (preg_match('/^.{0,' . (int) $max . '}/su', $s, $m) ? $m[0] : substr($s, 0, $max));
    }

    /** The last $max bytes of a text as one line, starting at a whole character. */
    private static function tail($s, $max)
    {
        $flat = self::line($s, 1000000);
        return strlen($flat) <= $max ? $flat : (preg_match('/.{0,' . (int) $max . '}$/su', $flat, $m) ? $m[0] : substr($flat, -$max));
    }
}
