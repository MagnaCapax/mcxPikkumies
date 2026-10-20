<?php
/**
 * TicketSearches (ADR-0100): where the agent looked before asking for help, read from what it actually ran. The source
 * is the session export's tool calls (Hermes writes each assistant turn's calls as a "## Tool calls" JSON block and each
 * result under "### Tool — <name>"); with no export, the memory search's own metrics of the last 24 hours (ADR-0041).
 * Nothing is logged for this: a ticket shows the searches the transcript already holds. check() is the venues gate of a
 * ticket the agent opens on its own.
 */
require_once dirname(__DIR__, 3) . '/lib/MetricsLog.php';

class TicketSearches
{
    const VENUES = ['memory', 'docs', 'web', 'upstream'];

    /** [['venue', 'how', 'keywords', 'matches' => [titles]], ...] from an export's tool calls, in the order they ran. */
    public static function fromExport($md)
    {
        $out = [];
        $pending = [];
        // Line by line, so a transcript of several MB never meets a regex limit. The calls of a turn come first (a
        // "## Tool calls" heading and a ```json block), then one "### Tool — <name>" result per call, in order.
        $blocks = [];
        $cur = null;
        foreach (explode("\n", (string) $md) as $line) {
            if ($cur !== null && $cur[0] === 'calls') {
                if ($line === '```' && $cur[2]) {
                    $blocks[] = $cur;
                    $cur = null;
                } elseif ($line === '```json') {
                    $cur[2] = true;
                } elseif ($cur[2]) {
                    $cur[1] .= $line . "\n";
                }
                continue;
            }
            if ($line === '## Tool calls' || strncmp($line, '### ', 4) === 0 || strncmp($line, '## ', 3) === 0) {
                if ($cur !== null) {
                    $blocks[] = $cur;
                }
                $cur = $line === '## Tool calls' ? ['calls', '', false]
                    : (preg_match('/^### Tool — (.+?)(?: — [0-9T:.+Z-]+)?$/u', $line, $h) ? ['result', '', $h[1]] : null);
                continue;
            }
            if ($cur !== null && strlen($cur[1]) < 65536) {
                $cur[1] .= $line . "\n"; // a result's start is enough to find the titles it matched
            }
        }
        if ($cur !== null && $cur[0] === 'result') {
            $blocks[] = $cur;
        }
        foreach ($blocks as $b) {
            $m = $b[0] === 'calls' ? [1 => $b[1]] : [1 => '', 2 => $b[2], 3 => $b[1]];
            if ($m[1] !== '') {
                foreach ((array) json_decode($m[1], true) as $call) {
                    $s = is_array($call) ? self::classify($call) : null;
                    $name = is_array($call) && isset($call['function']['name']) ? (string) $call['function']['name'] : (is_array($call) && isset($call['name']) ? (string) $call['name'] : '');
                    $pending[] = [$name, $s === null ? null : count($out)];
                    if ($s !== null) {
                        $out[] = $s;
                    }
                }
                continue;
            }
            foreach ($pending as $i => $p) {
                if ($p[0] === trim($m[2])) {
                    unset($pending[$i]);
                    if ($p[1] !== null && preg_match_all('/"title"\s*:\s*"((?:[^"\\\\]|\\\\.){1,160})"/', $m[3], $t)) {
                        $out[$p[1]]['matches'] = array_slice(array_values(array_unique(array_map('stripcslashes', $t[1]))), 0, 3);
                    }
                    break;
                }
            }
        }
        return $out;
    }

    /** One tool call as a search, or null when it searched nothing. */
    public static function classify(array $call)
    {
        $name = isset($call['function']['name']) ? (string) $call['function']['name'] : (isset($call['name']) ? (string) $call['name'] : '');
        $args = isset($call['function']['arguments']) ? $call['function']['arguments'] : (isset($call['arguments']) ? $call['arguments'] : []);
        $args = is_string($args) ? json_decode($args, true) : $args;
        $args = is_array($args) ? $args : [];
        $s = function ($venue, $how, $words) {
            return ['venue' => $venue, 'how' => $how, 'keywords' => substr(trim((string) $words), 0, 200), 'matches' => []];
        };
        if ($name === 'web_search') {
            return $s('web', 'web search', isset($args['query']) ? $args['query'] : '');
        }
        if ($name === 'web_extract') {
            return $s('web', 'web page read', isset($args['urls']) ? implode(' ', (array) $args['urls']) : '');
        }
        if ($name === 'read_file' && isset($args['path']) && strpos((string) $args['path'], 'docs-cache/') !== false) {
            return $s('docs', 'documentation page read', preg_replace('/\.md$/', '', substr((string) strrchr('/' . (string) $args['path'], '/'), 1)));
        }
        $cmd = $name === 'terminal' && isset($args['command']) ? (string) $args['command'] : '';
        // `P=~/.../pikkumies.php` then `php $P search ...` (the USAGE examples' form): put the path back in for $P.
        if (preg_match_all('/^\s*([A-Z][A-Z0-9_]*)=(\S+)\s*$/m', $cmd, $vars, PREG_SET_ORDER)) {
            foreach ($vars as $v) {
                $cmd = preg_replace('/\$' . $v[1] . '\b/', str_replace(['\\', '$'], ['\\\\', '\\$'], $v[2]), $cmd);
            }
        }
        $words = preg_match('/\b(?:search|list)\s+(.+?)(?:\s+--|\s*(?:;|&&|\|\||\||$))/m', $cmd, $w) ? trim($w[1], " \t'\"") : '';
        if (preg_match('#/development/upstream/|\bupstream\.php\b#', $cmd)) {
            return $s('upstream', 'upstream issues', $words);
        }
        if (preg_match('#\b(?:wiki|kb)\.php\s+(?:search|list)\b|docs-cache/#', $cmd)) {
            return $s('docs', strpos($cmd, 'kb.php') !== false ? 'knowledge base' : (strpos($cmd, 'wiki.php') !== false ? 'wiki' : 'documentation page read'), $words);
        }
        if (preg_match('#(?<![\w.-])pikkumies(?:\.php)?\s+search\b#', $cmd)) {
            return $s('memory', 'memory search (lessons, this host\'s docs, other providers\' docs)', $words);
        }
        return null;
    }

    /** With no export: the memory searches of the last 24 hours from the metrics (their words only when no lesson matched). */
    public static function fromMetrics($dir, $now)
    {
        $out = [];
        foreach (MetricsLog::files($dir, 'searches') as $file) {
            MetricsLog::each($file, $now - 86400, function ($r) use (&$out) {
                $hits = isset($r['hits']) && is_array($r['hits']) ? $r['hits'] : [];
                $out[] = ['venue' => 'memory', 'how' => 'memory search, tiers ' . implode(',', isset($r['tiers']) ? (array) $r['tiers'] : []),
                    'keywords' => isset($r['words']) ? (string) $r['words'] : '(words kept only when no lesson matched)', 'matches' => [], 'hits' => $hits];
                if (!empty($hits[2])) {
                    $out[] = ['venue' => 'docs', 'how' => 'this host\'s documentation, through the memory search', 'keywords' => isset($r['words']) ? (string) $r['words'] : '',
                        'matches' => [], 'hits' => $hits];
                }
            });
        }
        return $out;
    }

    /** venue => number of searches. */
    public static function counts(array $searches)
    {
        $c = array_fill_keys(self::VENUES, 0);
        foreach ($searches as $s) {
            $c[$s['venue']]++;
        }
        return $c;
    }

    /** The venues a ticket the agent opens itself must show: memory and docs; web when web search is on; upstream when that unit exists. */
    public static function required($webOn, $upstreamUnit)
    {
        return array_merge(['memory', 'docs'], $webOn ? ['web'] : [], $upstreamUnit ? ['upstream'] : []);
    }

    /** Refuses when a required venue has no search. */
    public static function check(array $searches, array $required)
    {
        $c = self::counts($searches);
        $missing = array_values(array_filter($required, function ($v) use ($c) {
            return $c[$v] === 0;
        }));
        if ($missing) {
            throw new CliError('not every place was searched before asking Pulsed Media: no ' . implode(', no ', $missing) . ' search in this session',
                'search those first (doctrine/SOP/ask-vainamoinen-for-help.md); a ticket the user asked for is drafted with ticket draft, or bundle --by user');
        }
    }

    /** The search log as Markdown, for the diagnosis attachment. */
    public static function markdown(array $searches, $source)
    {
        $lines = ['## Where Pikkumies searched', '', "Source: $source.", ''];
        foreach (self::counts($searches) as $v => $n) {
            $lines[] = "- $v: $n";
        }
        $lines[] = '';
        foreach ($searches as $i => $s) {
            $lines[] = ($i + 1) . ". [{$s['venue']}] {$s['how']}: " . ($s['keywords'] !== '' ? '"' . $s['keywords'] . '"' : '(no keywords)')
                . ($s['matches'] ? ' -> ' . implode('; ', $s['matches']) : '');
        }
        return implode("\n", $lines) . "\n";
    }
}
