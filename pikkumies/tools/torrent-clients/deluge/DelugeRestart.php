<?php
/**
 * DelugeRestart: restart the way the provider does it, then check Deluge answers.
 * PMSS (the panel's restart, etc/skel/www/deluge.php:6-12 and scriptsInc.php:1762-1781, 1178-1191):
 * only while ~/.delugeEnable exists; SIGTERM to the user's own deluged and deluge-web, 3 s, SIGKILL to
 * survivors; then the panel's start command, run from $HOME like the watchdog
 * (scripts/cron/checkDelugeInstances.php:18-24). Processes are matched by uid, mount namespace (as PMSS
 * does, so a Docker guest of the same name is left alone) and exact process name (pgrep -u, PMSS
 * scripts/lib/user/watchdog.php:44-47); never `pkill -f`. Other providers: refused with their instructions.
 */
require_once dirname(__DIR__, 3) . '/lib/OwnProcs.php';

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
            throw new CliError('Deluge is not installed on this machine', 'ask the provider');
        }
        if (!function_exists('posix_kill')) {
            throw new CliError('the PHP posix extension is missing, so the tool cannot stop Deluge gracefully', 'use the PMSS web panel: Deluge, restart');
        }
        $stopped = self::stop($uid);
        $h = escapeshellarg($home);
        // The panel's start command (etc/skel/www/deluge.php:8), with the home directory spelled out.
        exec("cd $h && { nohup deluged -l $h/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l $h/.delugeWebLog -L info >> /dev/null 2>&1 & }");
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
        return ['how' => "PMSS: stopped $stopped process(es) (SIGTERM, then SIGKILL after 3 s), started deluged and deluge-web as the panel does",
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
