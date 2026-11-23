<?php
/**
 * DelugeRestart: restart the way the provider does it, then check Deluge answers.
 * PMSS (the panel's restart, etc/skel/www/deluge.php:6-12 and scriptsInc.php:1762-1781, 1178-1191):
 * only while ~/.delugeEnable exists; SIGTERM to the user's own deluged and deluge-web, 3 s, SIGKILL to
 * survivors; then the start PMSS itself uses, under the account's Deluge lock held here: when the account
 * selected a build (~/bin/deluge/default is a link) the PMSS launcher, `torrentClientsResolve.php deluge --launch
 * both` (the panel: scriptsIncAppProcess.php:220; the watchdog: scripts/cron/checkDelugeInstances.php:22,25),
 * which starts the selected build; with no selection the bare `deluged` and `deluge-web`, run from $HOME like the
 * watchdog. A busy lock is reported before anything is stopped; a launcher failure is reported, never answered with
 * a bare start (ADR-0123). Processes are matched by uid, mount namespace (as PMSS
 * does, so a Docker guest of the same name is left alone) and exact process name (pgrep -u, PMSS
 * scripts/lib/user/watchdog.php:44-47); never `pkill -f`. Other providers: refused with their instructions.
 */
require_once dirname(__DIR__, 3) . '/lib/OwnProcs.php';
require_once dirname(__DIR__, 3) . '/lib/PmssClientLock.php';

class DelugeRestart
{
    const NAMES = ['deluged', 'deluge-web'];

    /** Instructions per provider, from each provider's public documentation (fetched 2026-10-14; README.md of this unit). */
    const ELSEWHERE = [
        'feral'   => 'Feral restarts deluged on its own within five minutes, and users cannot restart the web UI (feralhosting.com/wiki/software/deluge)',
        'ultra'   => 'the user runs `app-deluge restart` (docs.ultra.cc/applications/deluge)',
        'whatbox' => 'the user restarts Deluge from the Manage page of whatbox.ca (whatbox.ca/wiki/bittorrent_clients)',
    ];

    public static function run(DelugeClient $c, $home, $family, $uid)
    {
        if ($family !== 'pmss') {
            $how = array_key_exists($family, self::ELSEWHERE) ? self::ELSEWHERE[$family] : 'restart Deluge the way the provider documents it';
            throw new CliError('restart is done by the provider here, not by this tool', $how);
        }
        if (!file_exists("$home/.delugeEnable")) {
            throw new CliError('Deluge is not enabled, so it is not restarted', 'the user enables Deluge in the PMSS web panel (Deluge: start)');
        }
        if (!DelugeLocate::installed('pmss')) {
            throw new CliError('Deluge is not installed on this machine', 'the provider installs it: report it in your answer');
        }
        if (!function_exists('posix_kill')) {
            throw new CliError('the PHP posix extension is missing, so the tool cannot stop Deluge gracefully', 'use the PMSS web panel: Deluge, restart');
        }
        $h = escapeshellarg($home);
        $selected = is_link("$home/bin/deluge/default");
        $script = $home . '/www/torrentClientsResolve.php';
        if ($selected && !is_file($script)) {
            throw new CliError('the PMSS launcher script is missing (~/www/torrentClientsResolve.php); nothing was stopped', 'the provider restores it: report it in your answer');
        }
        $lock = PmssClientLock::take($home, 'deluge', 'Deluge', $selected);
        try {
            $stopped = self::stop($uid);
            if ($selected) {
                exec("cd $h && " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' deluge --launch both >/dev/null 2>&1 </dev/null', $out, $code);
                if ($code !== 0) {
                    throw new CliError("Deluge was stopped and the PMSS launcher refused to start it (exit $code)", 'the user starts Deluge in the PMSS web panel (Deluge: start); this tool does not fall back to a bare start');
                }
            } else {
                // The panel's start command (etc/skel/www/deluge.php:8), with the home directory spelled out.
                exec("cd $h && { nohup deluged -l $h/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l $h/.delugeWebLog -L info >> /dev/null 2>&1 & }");
            }
        } finally {
            PmssClientLock::release($lock);
        }
        $c->reset();
        $version = null;
        $last = '';
        for ($t = 0; $t < 30 && $version === null; $t++) { // up to 30 s
            sleep(1);
            try {
                $v = $c->rpc()->call('daemon.get_version', []);
                $version = is_string($v) ? $v : '';
            } catch (CliError $e) {
                $last = $e->getMessage();
                $c->reset();
            }
        }
        $start = $selected ? "through the account's PMSS launcher (torrentClientsResolve.php deluge --launch both) under the account's Deluge lock: the selected build" : 'with the bare deluged and deluge-web (no build is selected)';
        return ['how' => "PMSS: stopped $stopped process(es) (SIGTERM, then SIGKILL after 3 s), started deluged and deluge-web $start",
            'answered' => $version !== null, 'version' => $version, 'last_error' => $version === null ? $last : ''];
    }

    /** SIGTERM to this user's own Deluge processes, wait up to 3 s, SIGKILL survivors. Returns how many there were. */
    public static function stop($uid)
    {
        $pids = array_merge(self::pids(self::NAMES[0], $uid), self::pids(self::NAMES[1], $uid));
        foreach ($pids as $p) {
            @posix_kill($p, 15);
        }
        for ($i = 0; $i < 15 && self::alive($pids); $i++) {
            usleep(200000);
        }
        foreach (self::alive($pids) as $p) {
            @posix_kill($p, 9);
        }
        for ($i = 0; $i < 10 && self::alive($pids); $i++) {
            usleep(200000);
        }
        if (self::alive($pids)) {
            throw new CliError('Deluge did not stop (process ' . implode(', ', self::alive($pids)) . ')', 'nothing was started; check the processes with `ps -u $(whoami)`');
        }
        return count($pids);
    }

    /** Pids of processes named exactly $name, owned by $uid, in this mount namespace. */
    public static function pids($name, $uid, $proc = '/proc')
    {
        return OwnProcs::pids([$name], $uid, $proc);
    }

    private static function alive(array $pids)
    {
        return array_values(array_filter($pids, function ($p) {
            return is_dir("/proc/$p");
        }));
    }
}
