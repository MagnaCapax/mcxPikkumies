<?php
/**
 * ReportTemplates: report documents built from this account's own data, never typed: every figure carries the file or
 * tool it came from and when. A figure the source does not have is left out, never guessed.
 * - account-health: `system-status account` (live) plus the telemetry history in local/state/telemetry/<source>/
 *   YYYYMMDD.jsonl ({ts, field: number, ...}, one line per run, written by the telemetry unit).
 * - services-overview: the services list, local/state/services.jsonl.
 * - move-report: one move's step log, local/state/moves/<id>.jsonl (the newest move when no id is given); its free
 *   text goes through redact(), so a passkey or a password in an error message never reaches the report.
 * The documents use only the report kit's JSON shape (pikkumies/lib/report), so a template's output can be edited by
 * hand before rendering.
 */
require_once __DIR__ . '/ReportTelemetry.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/Redact.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class ReportTemplates
{
    const NAMES = ['account-health' => 'This account: disk, upload, memory and processes against their limits, with the telemetry history',
        'services-overview' => 'The services list: every box this Pikkumies knows, by provider and role',
        'move-report' => 'One move between boxes: each step, its result and time'];

    /** One record per template: whether its data exists here. */
    public static function available($local)
    {
        $out = [];
        foreach (self::NAMES as $name => $what) {
            $from = ['account-health' => 'system-status account + ' . Config::shown(ReportTelemetry::dir($local)),
                'services-overview' => Config::shown("$local/state/services.jsonl"), 'move-report' => Config::shown("$local/state/moves/")][$name];
            $has = $name === 'account-health' ? true : ($name === 'services-overview' ? is_file("$local/state/services.jsonl") : (bool) self::moves($local));
            $out[] = ['template' => $name, 'what' => $what, 'from' => $from, 'data' => $has ? 'present' : 'missing'];
        }
        return $out;
    }

    /** $account: the `system-status account` record or null; $now: the report time. */
    public static function accountHealth($local, $account, $days, $now)
    {
        $src = 'system-status account' . (isset($account['source']) && $account['source'] ? ' (' . $account['source'] . ')' : '');
        $at = isset($account['at']) && $account['at'] ? $account['at'] : gmdate('c', $now);
        $figs = [];
        $kpis = [];
        $rows = [];
        $worst = 'good';
        $limits = [['Disk used', 'disk', 'used', 'soft', 'bytes', 'disk'], ['Upload, 30 days', 'traffic', 'upload_30d', 'limit', 'bytes', 'upload'],
            ['Memory used', 'memory', 'used', 'limit', 'bytes', 'memory'], ['Processes', 'processes', 'used', 'limit', '', 'processes']];
        foreach ($limits as $l) {
            list($label, $group, $used, $limit, $unit, $id) = $l;
            $u = isset($account[$group][$used]) && is_numeric($account[$group][$used]) ? $account[$group][$used] + 0 : null;
            if ($u === null) {
                continue;
            }
            $cap = isset($account[$group][$limit]) && is_numeric($account[$group][$limit]) && $account[$group][$limit] > 0 ? $account[$group][$limit] + 0 : null;
            $figs[$id] = ['value' => $u, 'unit' => $unit, 'source' => $src, 'as_of' => $at, 'label' => $label];
            $kpi = ['label' => $label, 'value' => "fig:$id"];
            $pct = null;
            if ($cap !== null) {
                $figs[$id . 'Limit'] = ['value' => $cap, 'unit' => $unit, 'source' => $src, 'as_of' => $at, 'label' => "$label (limit)"];
                $pct = round(100 * $u / $cap, 1);
                $figs[$id . 'Pct'] = ['value' => $pct, 'unit' => '%', 'source' => $src, 'as_of' => $at, 'label' => "$label (share of limit)", 'decimals' => 1];
                $kpi['of'] = 'fig:' . $id . 'Limit';
                $kpi['tone'] = $pct >= 90 ? 'crit' : ($pct >= 75 ? 'warn' : 'good');
                $worst = self::worse($worst, $kpi['tone']) ? $kpi['tone'] : $worst;
            }
            $kpis[] = $kpi;
            $rows[] = [$label, "fig:$id", $cap !== null ? "fig:{$id}Limit" : ['text' => 'no limit reported'], $pct !== null ? "fig:{$id}Pct" : ''];
        }
        if (isset($account['memory']['oom_kills']) && is_numeric($account['memory']['oom_kills'])) {
            $figs['oom'] = ['value' => $account['memory']['oom_kills'] + 0, 'unit' => '', 'source' => $src, 'as_of' => $at, 'label' => 'OOM kills'];
            $kpis[] = ['label' => 'OOM kills', 'value' => 'fig:oom', 'tone' => $account['memory']['oom_kills'] > 0 ? 'warn' : 'good'];
        }
        $tele = ReportTelemetry::read(ReportTelemetry::dir($local), $days, $now);
        $sections = [];
        if ($rows) {
            $sections[] = ['title' => 'Limits', 'lead' => 'Each measured figure against the limit this account has.', 'blocks' => [
                ['type' => 'table', 'caption' => 'Used against limit', 'columns' => ['Figure', 'Used', 'Limit', ['label' => 'Share', 'unit' => '%']], 'rows' => $rows]]];
        }
        $charts = ReportTelemetry::charts($tele);
        if ($charts) {
            $sections[] = ['title' => 'History', 'lead' => 'How the recorded measurements moved over the last ' . (int) $days . ' days.', 'blocks' => $charts];
            $sections[] = ['title' => 'Everything recorded', 'lead' => 'Each measurement the telemetry history holds, with its latest value and range.',
                'blocks' => [ReportTelemetry::table($tele)]];
        }
        $apps = isset($account['apps']) && is_array($account['apps']) ? array_values(array_filter($account['apps'], 'is_scalar')) : [];
        if ($apps) {
            $sections[] = ['title' => 'Running apps', 'lead' => 'The apps of this account running when the report was made.', 'blocks' => [['type' => 'list', 'items' => array_map('strval', $apps)]]];
        }
        $verdict = $kpis ? self::verdict($worst, $figs) : ['tone' => 'info', 'headline' => 'This host does not report account limits.',
            'text' => $charts ? 'The report shows the recorded history only.' : 'No telemetry history is recorded yet either; run the report again once the status tools have run for a while.'];
        return ['title' => 'Account health', 'eyebrow' => 'Pikkumies · account report', 'subtitle' => 'This account\'s own figures against their limits, and how they moved.',
            'as_of' => gmdate('c', $now), 'stale_days' => 2, 'footer' => 'Pikkumies account report', 'verdict' => $verdict, 'figures' => $figs,
            'kpis' => array_slice($kpis, 0, 6), 'sections' => $sections ?: [['title' => 'No data', 'blocks' => []]]];
    }

    private static function verdict($worst, array $figs)
    {
        $names = ['disk' => 'Disk', 'upload' => 'Upload', 'memory' => 'Memory', 'processes' => 'Processes'];
        foreach (['crit' => 90, 'warn' => 75] as $tone => $limit) {
            if ($worst !== $tone) {
                continue;
            }
            foreach ($names as $id => $name) {
                if (isset($figs[$id . 'Pct']) && $figs[$id . 'Pct']['value'] >= $limit) {
                    return ['tone' => $tone, 'headline' => "$name is at {fig:{$id}Pct} of its limit.",
                        'text' => $tone === 'crit' ? 'It is close to the limit: free some room now.' : 'There is room, but plan to free some before it runs out.'];
                }
            }
        }
        return ['tone' => 'good', 'headline' => 'Every measured limit has room.', 'text' => 'Nothing needs attention now.'];
    }

    private static function worse($a, $b)
    {
        $rank = ['good' => 0, 'info' => 0, 'warn' => 1, 'crit' => 2];
        return $rank[$b] > $rank[$a];
    }

    /** The services list as a report. $services: the decoded lines of services.jsonl. */
    public static function servicesOverview(array $services, $file, $now)
    {
        $src = 'services list (' . PathName::base($file) . ')';
        $at = is_file($file) ? gmdate('c', (int) filemtime($file)) : gmdate('c', $now);
        $rows = [];
        $providers = [];
        $tags = [];
        foreach ($services as $s) {
            $tagList = isset($s['tags']) && is_array($s['tags']) ? array_values(array_filter($s['tags'], 'is_string')) : [];
            $p = isset($s['provider']) && is_string($s['provider']) ? $s['provider'] : 'generic';
            $providers[$p] = (isset($providers[$p]) ? $providers[$p] : 0) + 1;
            foreach ($tagList as $t) {
                $tags[$t] = (isset($tags[$t]) ? $tags[$t] : 0) + 1;
            }
            $rows[] = [self::s($s, 'nick'), $p, implode(', ', $tagList), self::s($s, 'transport'), self::s($s, 'host'),
                !empty($s['allow_delete']) ? ['text' => 'allowed', 'tone' => 'warn'] : ['text' => 'no', 'tone' => 'good'], substr(self::s($s, 'added_at'), 0, 10)];
        }
        $f = function ($v, $label) use ($src, $at) {
            return ['value' => $v, 'unit' => '', 'source' => $src, 'as_of' => $at, 'label' => $label];
        };
        $deletes = count(array_filter($services, function ($s) {
            return !empty($s['allow_delete']);
        }));
        arsort($providers);
        arsort($tags);
        return ['title' => 'Services overview', 'eyebrow' => 'Pikkumies · services', 'subtitle' => 'Every box this Pikkumies knows, by provider and role.',
            'as_of' => gmdate('c', $now), 'stale_days' => 365, 'footer' => 'Pikkumies services overview',
            'verdict' => ['tone' => $deletes ? 'warn' : 'good', 'headline' => $deletes ? 'Some services allow deletes from this box.' : 'No service allows deletes from this box.',
                'text' => 'Deletes on another box need that box\'s own opt-in; the table names each one.'],
            'kpis' => [['label' => 'Services', 'value' => $f(count($services), 'Services')], ['label' => 'Seedboxes', 'value' => $f(isset($tags['seedbox']) ? $tags['seedbox'] : 0, 'Seedboxes')],
                ['label' => 'Backup targets', 'value' => $f(isset($tags['backup']) ? $tags['backup'] : 0, 'Backup targets')],
                ['label' => 'Deletes allowed', 'value' => $f($deletes, 'Deletes allowed'), 'tone' => $deletes ? 'warn' : 'good']],
            'sections' => [
                ['title' => 'Services', 'blocks' => [['type' => 'table', 'caption' => 'Every service', 'source' => $src, 'as_of' => $at,
                    'columns' => ['Nick', 'Provider', 'Tags', 'Transport', 'Host', 'Deletes', 'Added'], 'rows' => $rows]]],
                ['title' => 'By provider and role', 'blocks' => [
                    ['type' => 'chart', 'kind' => 'hbar', 'title' => 'Services by provider', 'source' => $src, 'as_of' => $at,
                        'categories' => array_keys($providers), 'series' => [['label' => 'Services', 'values' => array_values($providers)]]],
                    ['type' => 'chart', 'kind' => 'hbar', 'title' => 'Services by tag', 'source' => $src, 'as_of' => $at,
                        'categories' => array_keys($tags), 'series' => [['label' => 'Services', 'values' => array_values($tags)]]]]]]];
    }

    /** Move ids under local/state/moves, newest first. */
    public static function moves($local)
    {
        $files = (array) glob("$local/state/moves/*.jsonl");
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        return array_map(function ($f) {
            return PathName::base($f, '.jsonl');
        }, $files);
    }

    /** One move's step log as a report; only ts, step, torrent, result and message/error/bytes/progress are read. */
    public static function moveReport($local, $id, $now)
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) $id) || !is_file("$local/state/moves/$id.jsonl")) {
            throw new CliError("no move log for '$id' in " . Config::shown("$local/state/moves/"), 'run templates move-report without --move for the newest, or list the moves with the move tool');
        }
        $src = "move log $id";
        $rows = [];
        $failed = 0;
        $first = $last = null;
        foreach (explode("\n", (string) file_get_contents("$local/state/moves/$id.jsonl")) as $line) {
            $r = json_decode($line, true);
            if (!is_array($r) || !isset($r['step'])) {
                continue;
            }
            $ts = ReportFormat::ts(isset($r['ts']) ? $r['ts'] : null);
            $first = $first === null ? $ts : $first;
            $last = $ts !== null ? $ts : $last;
            $res = self::s($r, 'result');
            $bad = preg_match('/fail|error|refus/i', $res) === 1;
            $failed += $bad ? 1 : 0;
            $detail = self::redact(trim(self::s($r, 'message') . ' ' . self::s($r, 'error')));
            $rows[] = [$ts !== null ? gmdate('Y-m-d H:i:s', $ts) : '', self::s($r, 'step'), self::s($r, 'torrent'),
                ['text' => $res !== '' ? $res : '–', 'tone' => $bad ? 'crit' : (preg_match('/ok|done|verified|seeding/i', $res) ? 'good' : 'info')], substr($detail, 0, 160)];
        }
        $at = $last !== null ? gmdate('c', $last) : gmdate('c', $now);
        $f = function ($v, $unit, $label) use ($src, $at) {
            return ['value' => $v, 'unit' => $unit, 'source' => $src, 'as_of' => $at, 'label' => $label];
        };
        $kpis = [['label' => 'Steps logged', 'value' => $f(count($rows), '', 'Steps logged')],
            ['label' => 'Failed steps', 'value' => $f($failed, '', 'Failed steps'), 'tone' => $failed ? 'crit' : 'good']];
        if ($first !== null && $last !== null) {
            $kpis[] = ['label' => 'Duration', 'value' => $f(round(($last - $first) / 60, 1), 'min', 'Duration')];
        }
        return ['title' => "Move $id", 'eyebrow' => 'Pikkumies · move report', 'subtitle' => 'Each step of the move, in order, as the move tool logged it.',
            'as_of' => gmdate('c', $now), 'stale_days' => 365, 'footer' => "Pikkumies move report · $id",
            'verdict' => ['tone' => $failed ? 'crit' : 'good', 'headline' => $failed ? 'Some steps failed.' : 'Every logged step succeeded.',
                'text' => $failed ? 'Run the move again: it continues at the first unfinished step.' : 'Check the last step for the final state on the destination.'],
            'kpis' => $kpis,
            'sections' => [['title' => 'Steps', 'blocks' => [['type' => 'table', 'caption' => 'Step log', 'source' => $src, 'as_of' => $at,
                'columns' => ['Time (UTC)', 'Step', 'Torrent', 'Result', 'Detail'], 'rows' => $rows]]]]];
    }

    /**
     * Free text from a log, safe to keep and pass on: Redact::secrets (passkeys in URLs, name=value secrets, tokens),
     * then any user:password@ that has no scheme in front (Redact handles only the URL form).
     */
    public static function redact($text)
    {
        return (string) preg_replace('#(?<![^\s("\'=,;])[^\s:@/"\']+:[^\s@/"\']+@#', 'REDACTED@', Redact::secrets(substr((string) $text, 0, 2000)));
    }

    private static function s($a, $k)
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? (string) $a[$k] : '';
    }
}
