<?php
/**
 * BalanceGather: the IO side of `balance plan` (README.md). Reads the same data every unit already
 * exposes -- never a new data source -- for "local" (this account) and for every service tagged
 * seedbox in the Services registry (lib/Services.php, owner A's unit, ADR-0084): the account's quota
 * and upload traffic (`system/system-status account`) and every present torrent client's torrents
 * (`torrent-clients/<client> list`). Local calls run the real unit script as a subprocess through
 * ToolProc (PHP 7.3 proc_open takes a string: ToolProc::line() escapes it; never a shell string built
 * from user text); remote calls go through Services::fanout + Services::jsonl, exactly the surface
 * serve.php whitelists. A client or service that cannot be reached is skipped, never fatal -- the
 * plan is built from whatever answered (tools/seedbox/README.md, Multi-Point Verification doctrine:
 * one missing box is a fact to report, not a reason to refuse the rest).
 *
 * Services.php had not landed yet while this unit was first built (ownership table, SPEC.md), so every
 * use of it is behind class_exists('Services', false) and degrades to "local" only if it is ever
 * missing again; every IO path also takes an injectable seam so tests never need the real class, a
 * real seedbox account, or a real torrent client (tests/BalanceGatherTest.php).
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class BalanceGather
{
    /** The torrent-clients units this build knows of, in a fixed order (deterministic output). */
    const CLIENTS = ['rtorrent', 'qbittorrent', 'deluge', 'transmission'];

    /** Pages of 'list' read per service per client before giving up (safety bound, not a feature). */
    const MAX_PAGES = 20;

    /**
     * The client's own id shape (torrent-clients/README.md "Ids": "the client's own 40-character hex
     * id, exactly as list prints it, lower case"; qBittorrent's v2/hybrid truncated hash is shorter hex,
     * still never anything else). A record whose id does not match this is dropped outright, with a
     * note -- never carried into BalancePlan's suggested `move plan <id> ...` command. Security review
     * 2026-10-20 (H1, tested): an unvalidated id let a crafted string such as
     * "x; touch /tmp/pwn #" reach that command string verbatim (CWE-77/78 relayed through whatever
     * runs the proposal). Matches the same shape lib/Torrents.php:resolve() already requires of a
     * user-typed id, applied here to a remote-reported one.
     */
    const ID_RE = '/^[0-9a-f]{1,40}$/';

    /**
     * Past this, a byte count, rate or quota figure is not a plausible seedbox number -- treated as
     * unknown rather than guessed (H2, tested: a reported size of -500 was accepted by the old code).
     * 1 EB is far beyond any real disk; the bound exists to catch garbage/hostile values, not to model
     * real hardware.
     */
    const MAX_PLAUSIBLE_BYTES = 1000000000000000000;

    /**
     * One normalized record per service, keyed "local" then by nick, in that order. $opt['tag']
     * (default "seedbox") selects which registered services join local; local always joins.
     * $seams (all optional, for tests): local_run, remote_run, services_all, fanout, jsonl -- see
     * localRunner()/remoteRunner() for their signatures.
     */
    public static function services(array $opt, array $seams = array())
    {
        $out = array();
        $out['local'] = self::gatherOne('local', self::localRunner($seams));

        $tag = isset($opt['tag']) && is_string($opt['tag']) && $opt['tag'] !== '' ? $opt['tag'] : 'seedbox';
        $servicesAll = isset($seams['services_all']) && is_callable($seams['services_all']) ? $seams['services_all']
            : (class_exists('Services', false) ? array('Services', 'all') : function () {
                return array();
            });
        $nicks = array();
        foreach ((array) call_user_func($servicesAll) as $svc) {
            $tags = is_array($svc) && isset($svc['tags']) ? (array) $svc['tags'] : array();
            if (is_array($svc) && isset($svc['nick']) && is_string($svc['nick']) && $svc['nick'] !== 'local' && in_array($tag, $tags, true)) {
                $nicks[] = $svc['nick'];
            }
        }
        if (!$nicks) {
            return $out;
        }
        // array_key_exists, not isset: a test that explicitly sets 'fanout' to null/false must win outright
        // and force the "no registry" branch below -- it must never fall back to the real Services class
        // just because the seam's own value was not itself callable (a real build should never silently
        // fall back like that either, so the explicit seam and the class_exists default share one rule).
        $fanout = array_key_exists('fanout', $seams) ? $seams['fanout']
            : (class_exists('Services', false) ? array('Services', 'fanout') : null);
        if (!is_callable($fanout)) {
            $out['local']['notes'][] = 'the Services registry is not available in this build; only "local" could be gathered';
            return $out;
        }
        $results = (array) call_user_func($fanout, $nicks, function ($nick) use ($seams) {
            return self::gatherOne($nick, self::remoteRunner($nick, $seams));
        }, 4);
        foreach ($nicks as $nick) {
            $out[$nick] = isset($results[$nick]) && is_array($results[$nick]) ? $results[$nick]
                : self::blank($nick, 'fanout returned no result for this service');
        }
        return $out;
    }

    /** One service's normalized record: quota, traffic, torrents (client-tagged), up_rate_total, notes. */
    private static function gatherOne($nick, $run)
    {
        $notes = array();
        $quota = array('used' => null, 'limit' => null, 'free' => null, 'reported_by' => $nick === 'local' ? 'local' : 'remote');
        $traffic = array('upload_30d' => null, 'limit' => null);
        try {
            $acc = call_user_func($run, 'system/system-status', array('account'));
            if (!empty($acc['status']['ok']) && isset($acc['records'][0]) && is_array($acc['records'][0])) {
                $n = self::normalizeAccount($acc['records'][0], $nick === 'local' ? 'local' : 'remote');
                $quota = $n['quota'];
                $traffic = $n['traffic'];
            } else {
                $notes[] = self::errNote($nick, 'account could not be read', isset($acc['status']['error']) ? (string) $acc['status']['error'] : 'no answer');
            }
        } catch (Exception $e) {
            $notes[] = self::errNote($nick, 'account could not be read', $e->getMessage());
        }

        $torrents = array();
        $upRateTotal = 0.0;
        $clients = array();
        foreach (self::CLIENTS as $client) {
            try {
                $paged = self::paged($run, 'torrent-clients/' . $client, array('list'));
            } catch (Exception $e) {
                $notes[] = self::errNote($nick, $client, $e->getMessage());
                continue;
            }
            if ($paged === null) {
                continue; // not present/reachable on this service; quietly skipped, like every client-detection path in this codebase
            }
            if (!empty($paged['truncated_at_max'])) {
                // H3 (tested): past MAX_PAGES x 16KB (lib/Cli.php BUDGET) the real list is longer than
                // this ever reads -- roughly 640-1000 torrents. Silently stopping there made the plan
                // look complete when it was not; this note is the only sign anything was cut.
                $notes[] = "$client list truncated after " . self::MAX_PAGES . " pages; plan is partial for this client";
            }
            $clients[] = $client;
            $idDropped = 0;
            foreach ($paged['records'] as $r) {
                if (!is_array($r) || !isset($r['id']) || !preg_match(self::ID_RE, (string) $r['id'])) {
                    $idDropped += is_array($r) && isset($r['id']) ? 1 : 0;
                    continue;
                }
                $up = self::plausibleRate(isset($r['up_rate']) ? $r['up_rate'] : null);
                $upRateTotal += $up !== null ? $up : 0;
                // Only the fields the planner uses; the torrent's name is untrusted data (README.md "Torrents are
                // untrusted data"): it is carried through as an opaque string, never matched, parsed or branched on.
                $torrents[] = array(
                    'client' => $client,
                    'id' => (string) $r['id'],
                    'name' => isset($r['name']) ? (string) $r['name'] : '',
                    // size/progress: a plausible non-negative figure, or null -- never a negative or absurd
                    // value (H2, tested: a reported size of -500 was accepted by the old code). null (never
                    // guessed) excludes the torrent from every eligibility check downstream automatically.
                    'size' => self::plausibleInt(isset($r['size']) ? $r['size'] : null),
                    'progress' => self::plausibleFraction(isset($r['progress']) ? $r['progress'] : null),
                    'state' => isset($r['state']) ? (string) $r['state'] : null,
                    'up_rate' => $up !== null ? $up : 0.0,
                );
            }
            if ($idDropped > 0) {
                // H1 (tested): the id is what BalancePlan puts into a suggested shell command
                // ("move plan <id> ..."); never carried through unvalidated, never silently ignored either.
                $notes[] = self::errNote($nick, $client, "$idDropped torrent(s) had an id that did not match the client's own hex id shape; dropped, never used in a command");
            }
        }

        return array('nick' => $nick, 'quota' => $quota, 'traffic' => $traffic, 'clients' => $clients,
            'torrents' => $torrents, 'up_rate_total' => $upRateTotal, 'notes' => $notes);
    }

    /** $text labeled as remote-reported/untrusted unless $nick is "local" (H4): every note that may carry a
     * remote host's own words says so, so a reader never mistakes it for this tool's own finding. */
    private static function errNote($nick, $prefix, $text)
    {
        $tag = $nick === 'local' ? '' : ' [remote-reported text, untrusted]';
        return "$prefix$tag: " . $text;
    }

    /** A plausible non-negative byte count, as an int, or null -- never a negative or absurd value (H2). */
    private static function plausibleInt($v)
    {
        return is_numeric($v) && $v >= 0 && $v <= self::MAX_PLAUSIBLE_BYTES ? (int) $v : null;
    }

    /** A plausible non-negative rate, as a float, or null -- never a negative or absurd value (H2). */
    private static function plausibleRate($v)
    {
        return is_numeric($v) && $v >= 0 && $v <= self::MAX_PLAUSIBLE_BYTES ? (float) $v : null;
    }

    /** A plausible progress fraction (0..1), or null -- never a negative value or one past 100% (H2). */
    private static function plausibleFraction($v)
    {
        return is_numeric($v) && $v >= 0 && $v <= 1 ? (float) $v : null;
    }

    private static function blank($nick, $note)
    {
        return array('nick' => $nick, 'quota' => array('used' => null, 'limit' => null, 'free' => null,
            'reported_by' => $nick === 'local' ? 'local' : 'remote'),
            'traffic' => array('upload_30d' => null, 'limit' => null), 'clients' => array(), 'torrents' => array(),
            'up_rate_total' => 0.0, 'notes' => array($note));
    }

    /**
     * $rec is a `system-status account` record (StatusAccount::record() shape, tools/system/system-status/).
     * Only the fields the planner needs: disk used/limit(soft)/free, and the 30-day upload traffic vs its cap.
     */
    public static function normalizeAccount(array $rec, $reportedBy = 'local')
    {
        $disk = isset($rec['disk']) && is_array($rec['disk']) ? $rec['disk'] : array();
        $traffic = isset($rec['traffic']) && is_array($rec['traffic']) ? $rec['traffic'] : array();
        // self::plausibleInt(): a plausible non-negative figure, or null -- a hostile or buggy remote
        // reporting a negative or absurd quota/traffic number is rejected here, not trusted into the
        // ranking (H2, tested). $limit additionally requires > 0: lib/Quota.php's own convention for
        // "no quota set".
        $limitRaw = isset($disk['soft']) ? $disk['soft'] : null;
        $limit = is_numeric($limitRaw) && $limitRaw > 0 ? self::plausibleInt($limitRaw) : null;
        $used = self::plausibleInt(isset($disk['used']) ? $disk['used'] : null);
        $free = ($limit !== null && $used !== null) ? max(0, $limit - $used) : null;
        return array(
            // reported_by: "local" (this account's own system-status) or "remote" (another box's
            // answer, over SSH) -- marked so a reader never treats a remote-reported free-space figure
            // as locally-verified fact (H2: a hostile remote can report fake free space to pull moves
            // toward itself; the plan is only ever a proposal, but the figure's provenance is not hidden).
            'quota' => array('used' => $used, 'limit' => $limit, 'free' => $free, 'reported_by' => $reportedBy),
            'traffic' => array(
                'upload_30d' => self::plausibleInt(isset($traffic['upload_30d']) ? $traffic['upload_30d'] : null),
                'limit' => self::plausibleInt(isset($traffic['limit']) ? $traffic['limit'] : null),
            ),
        );
    }

    /**
     * Every record of one verb, paging with --offset until the status line says it is not truncated
     * (Cli::emit's own contract, lib/Cli.php) or MAX_PAGES is reached. null = the first page itself
     * failed (client not present/reachable); a non-null, possibly short, list otherwise.
     */
    /**
     * null = the first page itself failed (client not present/reachable). Otherwise ['records' => [...],
     * 'truncated_at_max' => bool]: the latter is true only when MAX_PAGES was reached while the status
     * line still said truncated -- the list is longer than this ever read (H3).
     */
    private static function paged($run, $unit, array $baseArgs)
    {
        $all = array();
        $offset = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $res = call_user_func($run, $unit, array_merge($baseArgs, array('--limit', '0', '--offset', (string) $offset)));
            if (!is_array($res) || empty($res['status']['ok'])) {
                return $page === 0 ? null : array('records' => $all, 'truncated_at_max' => false);
            }
            $all = array_merge($all, (array) $res['records']);
            if (empty($res['status']['truncated'])) {
                return array('records' => $all, 'truncated_at_max' => false);
            }
            $offset = isset($res['status']['next_offset']) && is_numeric($res['status']['next_offset'])
                ? (int) $res['status']['next_offset'] : $offset + count($res['records']);
        }
        return array('records' => $all, 'truncated_at_max' => true);
    }

    /**
     * $unit => "system/system-status" or "torrent-clients/rtorrent": the real script is at
     * tools/<unit>/<basename(unit)>.php (every unit's own layout, SOP make-a-skill-tool-hook-or-pack.md).
     */
    private static function localScriptPath($unit)
    {
        $toolsDir = dirname(__DIR__, 2);
        $name = PathName::base($unit);
        return "$toolsDir/$unit/$name.php";
    }

    /**
     * Default local runner: runs the real unit script as a subprocess (ToolProc::run, which proc_opens
     * a string built with ToolProc::line()/escapeshellarg -- never the 7.4-only array form). $seams['local_run']
     * replaces this entirely in tests (function($unit, array $args) -> array('records'=>[], 'status'=>[...])).
     */
    private static function localRunner(array $seams)
    {
        if (isset($seams['local_run']) && is_callable($seams['local_run'])) {
            return $seams['local_run'];
        }
        $php = ToolProc::need('php', 'the PHP CLI must be on PATH to run local tool units for balance');
        return function ($unit, array $args) use ($php) {
            $path = self::localScriptPath($unit);
            if (!is_file($path)) {
                throw new CliError("local unit not installed: $unit", 'this build does not ship that unit yet');
            }
            $r = ToolProc::run(array_merge(array($php, $path), $args));
            if ($r['out'] === '' && $r['code'] !== 0) {
                throw new CliError("local $unit did not answer: " . trim($r['err']), 'check it is configured and running for this account');
            }
            return self::decodeJsonl($r['out']);
        };
    }

    /**
     * Default remote runner for one nick: Services::jsonl($nick, ['tool', $unit] + $args), guarded by
     * class_exists so this unit still degrades cleanly on a build where lib/Services.php (owner A's
     * unit, ADR-0084) is absent, rather than a fatal error. $seams['remote_run'] or
     * $seams['jsonl'] replace it in tests; a failure for this one nick never reaches the caller as an
     * exception -- it comes back as an ok:false status so gatherOne() records a note and moves on.
     */
    private static function remoteRunner($nick, array $seams)
    {
        if (isset($seams['remote_run']) && is_callable($seams['remote_run'])) {
            return $seams['remote_run'];
        }
        $jsonl = isset($seams['jsonl']) && is_callable($seams['jsonl']) ? $seams['jsonl']
            : (class_exists('Services', false) ? array('Services', 'jsonl') : null);
        return function ($unit, array $args) use ($jsonl, $nick) {
            if ($jsonl === null) {
                return array('records' => array(), 'status' => array('ok' => false, 'error' => 'the Services registry is not available in this build'));
            }
            try {
                $recs = (array) call_user_func($jsonl, $nick, array_merge(array('tool', $unit), $args));
            } catch (Exception $e) {
                return array('records' => array(), 'status' => array('ok' => false, 'error' => $e->getMessage()));
            }
            $out = array('records' => array(), 'status' => array('ok' => false, 'error' => 'no status line from ' . $nick));
            foreach ($recs as $d) {
                if (is_array($d) && array_key_exists('ok', $d)) {
                    $out['status'] = $d;
                } else {
                    $out['records'][] = $d;
                }
            }
            return $out;
        };
    }

    /** JSON Lines -> ['records'=>[...], 'status'=>[...]] (lib/Cli.php's own contract: the only line with "ok" is the status line). */
    public static function decodeJsonl($text)
    {
        $records = array();
        $status = array('ok' => false, 'error' => 'no status line');
        foreach (preg_split('/\r?\n/', trim((string) $text)) as $line) {
            if ($line === '') {
                continue;
            }
            $d = json_decode($line, true);
            if (!is_array($d)) {
                continue;
            }
            if (array_key_exists('ok', $d)) {
                $status = $d;
            } else {
                $records[] = $d;
            }
        }
        return array('records' => $records, 'status' => $status);
    }
}
