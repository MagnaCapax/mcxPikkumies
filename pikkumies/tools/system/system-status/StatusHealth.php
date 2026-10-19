<?php
/**
 * StatusHealth: the record of `system-status health` (ADR-0066), one answer to "is my seedbox down?" from what this
 * machine and the platform already say. The machine is up (this tool runs on it). On PMSS, the account's web panel
 * answers, or the platform's own 502 page names the reason (scripts/lib/lighttpd/watchdogErrorPageFile.php:14-40).
 * Each torrent client is reachable or says why, and when it last started (HealthClients). The quota state is the
 * storage unit's (StorageStats); memory is the level PMSS itself reports (userShowLimits `memory status`). No
 * threshold is copied from elsewhere; verdicts are fixed sentences. Nothing names the account, the server or a URL
 * of this machine (ADR-0023).
 */
require_once dirname(__DIR__, 3) . '/lib/Http.php';
require_once dirname(__DIR__, 3) . '/lib/DocSources.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php'; // StorageStats runs quota through it
require_once dirname(__DIR__, 2) . '/storage/storage/StorageStats.php';
require_once __DIR__ . '/HealthClients.php';
require_once __DIR__ . '/StatusAccount.php';

class StatusHealth
{
    /** The headline under "Specific status:" on PMSS's 502 page => [reason key, next step] (watchdogErrorPageFile.php:15-21). */
    const REASONS = [
        'Your account is suspended.' => ['suspended', 'check the invoices in the client area, or contact support'],
        'Your disk quota is full.' => ['quota', 'free space: stop the torrent client before deleting files (files a running client holds open stay charged), then run health again'],
        'Deleted files are still counted by your quota.' => ['quota_descriptors', 'restart the client that holds the deleted files open (its restart verb), then run health again; deleting more files does not help'],
        'Server-wide storage pressure is blocking web access.' => ['inode', 'nothing to do on the account: the platform team handles server-wide storage pressure'],
        'A web configuration error was detected.' => ['config', 'contact support: the web configuration needs a fix on their side'],
        'Your PHP backend is not responding.' => ['php', 'the platform restarts it on its own; check again in 1-2 minutes'],
        'Your web service is restarting.' => ['restarting', 'the platform restarts it on its own; check again in 1-2 minutes'],
    ];
    const NOT_SHOWN = 'whether the user\'s own network reaches this server: this check runs on the server itself';
    const RECENT = 3600; // a client start this recent is named in the summary

    /**
     * $in: family, home, uid, proc (the /proc to read), panel_url (PMSS: the account's own panel address on this machine,
     * never printed; null skips the probe), usl (Detect::userShowLimitsData() or null).
     */
    public static function record(array $in)
    {
        $up = explode(' ', (string) @file_get_contents($in['proc'] . '/uptime'));
        $upS = is_numeric($up[0]) ? (int) $up[0] : null;
        $panel = $in['family'] === 'pmss' && $in['panel_url'] !== null ? self::panel($in['panel_url']) : null;
        $clients = HealthClients::all($in['family'], $in['home'], $in['uid'], $in['proc'], $upS);
        $q = StorageStats::record($in['home']);
        $quota = ['state' => $q['quota_state'], 'used_pct' => $q['quota_used_percent'], 'server_disk_full' => $q['fs_full'],
            'next_step' => isset($q['hint']) ? $q['hint'] : null, 'doc' => strpos($q['quota_state'], 'over_') === 0 ? DocSources::pageUrl('Seedbox Storage Quota') : null];
        $mem = $in['usl'] !== null ? StatusAccount::record($in['usl'], $in['family'], [])['memory'] : null;
        $memory = $mem === null ? null : ['status' => $mem['status'], 'oom_kills' => $mem['oom_kills']];
        $findings = self::findings($panel, $clients, $quota, $memory);
        return ['summary' => $findings ? $findings[0] : 'this machine is up' . ($panel ? ', the web panel answers' : '') . ($clients ? ' and the torrent clients are reachable' : ''),
            'findings' => $findings, 'machine' => ['up_s' => $upS, 'not_shown' => self::NOT_SHOWN], 'panel' => $panel, 'clients' => $clients,
            'quota' => $quota, 'memory' => $memory, 'units' => 'up_s and running_s seconds; used_pct percent'];
    }

    /** One GET of the account's own panel through the web server: any answer of its own, or the platform's 502 reason. */
    public static function panel($url)
    {
        $r = Http::request('GET', $url, ['timeout' => 10, 'max' => 65536, 'owners' => 'system']);
        $code = (int) $r['code'];
        if ($code > 0 && $code !== 502 && $code !== 504) {
            return ['state' => 'answers', 'code' => $code, 'reason' => null, 'next_step' => null, 'doc' => null];
        }
        if ($code === 502 && preg_match('/^Specific status:\r?\n- ([^\r\n]+)/m', $r['body'], $m) && isset(self::REASONS[$m[1]])) {
            return ['state' => 'platform_reason', 'code' => 502, 'reason' => self::REASONS[$m[1]][0], 'next_step' => self::REASONS[$m[1]][1], 'doc' => null];
        }
        return ['state' => 'no_answer', 'code' => $code > 0 ? $code : null, 'reason' => null,
            'next_step' => 'check again in a few minutes; if it still does not answer, run Diagnose on the service page in the client area, or open a support ticket with this record',
            'doc' => DocSources::pageUrl('Diagnose on PMSS')];
    }

    /** What is wrong, most blocking first, as fixed sentences; empty when nothing is. */
    private static function findings($panel, array $clients, array $quota, $memory)
    {
        $out = [];
        if ($panel !== null && $panel['state'] !== 'answers') {
            $out[] = $panel['state'] === 'platform_reason' ? 'the machine is up; the web panel is blocked (' . $panel['reason'] . '): ' . $panel['next_step']
                : 'the machine is up, but the web panel does not answer';
        }
        foreach ($clients as $c) {
            $name = HealthClients::NAMES[$c['client']];
            if ($c['state'] === 'not_enabled') {
                $out[] = "$name is off: it was not started or was stopped by the user ({$c['next_step']})";
            } elseif ($c['state'] !== 'listening') {
                $out[] = "the machine is up; $name cannot be reached: {$c['why']}";
            } elseif ($c['started_after_boot'] && $c['running_s'] < self::RECENT) {
                $out[] = "$name started " . (int) ceil($c['running_s'] / 60) . ' minutes ago while the machine kept running: the client restarted, not the server';
            }
        }
        if ($quota['state'] === 'over_hard' || $quota['server_disk_full']) {
            $out[] = $quota['next_step']; // the storage unit's own sentence: hard limit, or the server's disk full
        }
        if ($memory !== null && in_array($memory['status'], ['HIGH', 'THROTTLED'], true)) {
            $out[] = 'the platform reports memory ' . $memory['status'] . ' for this account; the performance tool says what uses it';
        }
        return $out;
    }
}
