<?php
/**
 * QbitRestart: restart qBittorrent the way the host does it, then check that it answers.
 * - PMSS: only while ~/.qbittorrentEnable exists (the panel creates it on start, the watchdog honours
 *   it: PMSS etc/skel/www/scriptsInc.php:1765-1768, scripts/lib/user/watchdog.php:179-180). Graceful
 *   stop of the own process (QbitProc), then the start the PMSS watchdog uses
 *   (scripts/cron/checkQbittorrentInstances.php:21): with the account's start helper
 *   (~/www/qbittorrentStart.php) the PMSS launcher, `torrentClientsResolve.php qbittorrent --launch --held
 *   --system`, which starts the build the account selected (its ~/bin/qbittorrent/default link) or the system
 *   build, under the account's qBittorrent lock held here; without the helper (a half-delivered PMSS update) the
 *   bare `qbittorrent-nox -d`, as PMSS itself then does. A busy lock or a refusal marker is reported before
 *   anything is stopped; a launcher refusal is reported, never answered with a bare start.
 * - Ultra.cc: `app-qbittorrent restart` (docs.ultra.cc/applications/qbittorrent, research §4).
 * - Feral, Whatbox, others: refused with the provider's own instructions (Feral documents only
 *   `pkill -f`, which this tool never uses, and says qBittorrent cannot be started by hand).
 */
require_once dirname(__DIR__, 3) . '/lib/PmssClientLock.php';

class QbitRestart
{
    const WAIT = 30;

    public $proc;

    public function __construct(QbitProc $proc)
    {
        $this->proc = $proc;
    }

    public function run(QbitApi $api)
    {
        $ep = $api->ep;
        $ep->readConfig();
        $r = ['how' => '', 'stopped' => 0, 'killed' => 0];
        if ($ep->family === 'pmss') {
            if (!$ep->enabled()) {
                throw new CliError('qBittorrent is disabled for this account (~/.qbittorrentEnable is missing); not starting it', 'the user starts qBittorrent in the Pulsed Media panel; this tool never overrides a disabled client');
            }
            $r = array_merge($r, $this->pmssRestart($ep->home));
        } elseif ($ep->family === 'ultra') {
            $this->exec('app-qbittorrent restart', 'app-qbittorrent restart');
            $r['how'] = 'Ultra.cc: app-qbittorrent restart';
        } else {
            $hints = [
                'feral' => 'Feral restarts qBittorrent by itself within five minutes and it cannot be started by hand (feralhosting.com/wiki/software/qbittorrent); wait five minutes and check again; if it is still down, report it in your answer for Feral support',
                'whatbox' => 'restart it from the Manage page of the Whatbox website (whatbox.ca/wiki/bittorrent_clients)',
            ];
            throw new CliError("restart is not supported on this host ({$ep->family}): no documented restart command for qBittorrent", isset($hints[$ep->family]) ? $hints[$ep->family] : 'restart it the way your provider documents it (control panel or support)');
        }
        $r['answered'] = false;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $t0 = time();
        while (true) {
            $owner = Host::ownsListener($ep->port, $uid);
            if ($owner === 'other') {
                throw new CliError("after the restart, port {$ep->port} belongs to another account; not connecting", 'restart again from the panel so qBittorrent gets its port back');
            }
            $r['answered'] = $owner === 'own' && $api->answers();
            if ($r['answered'] || time() - $t0 >= self::WAIT) {
                break;
            }
            usleep(500000);
        }
        $r['waited'] = time() - $t0;
        $r['version'] = '';
        if (!$r['answered']) {
            throw new CliError('qBittorrent was started but does not answer after ' . self::WAIT . ' s (state unknown)', 'check again with `stats` in a minute; a client with many torrents starts slowly');
        }
        try {
            $r['version'] = $api->open()->text('app/version');
        } catch (CliError $e) {
            $r['note'] = 'it answers, but the version could not be read: ' . $e->getMessage();
        }
        return $r;
    }

    /** PMSS stop and start; returns the stop counts and `how`. See the class comment for the two start paths. */
    private function pmssRestart($home)
    {
        $stopText = 'PMSS: own qbittorrent-nox stopped (SIGTERM, SIGKILL only after 10 s), then started ';
        if (!is_file($home . '/www/qbittorrentStart.php')) {
            $r = $this->proc->stop();
            $this->exec('cd ' . escapeshellarg($home) . ' && qbittorrent-nox -d', 'qbittorrent-nox -d');
            $r['how'] = $stopText . 'with `qbittorrent-nox -d` (this account has no PMSS start helper, and PMSS starts it that way then)';
            return $r;
        }
        $dir = $home . '/.config/pmss/torrentClients';
        $script = $home . '/www/torrentClientsResolve.php';
        if (!is_file($script)) {
            throw new CliError('the PMSS launcher script is missing (~/www/torrentClientsResolve.php); nothing was stopped', 'the provider restores it: report it in your answer');
        }
        $lock = PmssClientLock::take($home, 'qbittorrent', 'qBittorrent', true);
        try {
            if (file_exists($dir . '/qbittorrent.refused') || is_link($dir . '/qbittorrent.refused')) {
                throw new CliError('PMSS refuses to start qBittorrent on this account (qbittorrent.refused is set); nothing was stopped', 'the user clears it by starting qBittorrent in the Pulsed Media panel (Apps); this tool never clears it');
            }
            $before = $this->notice($dir);
            $own = $this->proc->pids();
            $r = $this->proc->stop();
            for ($i = 0; $i < 10 && array_filter($own, [$this->proc, 'alive']); $i++) { // the start helper refuses while an own process lives
                usleep(200000);
            }
            if (array_filter($own, [$this->proc, 'alive'])) {
                throw new CliError('qBittorrent was stopped but its process has not exited; nothing was started', 'check again with `stats` in a minute');
            }
            exec('cd ' . escapeshellarg($home) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
                . ' qbittorrent --launch --held --system >/dev/null 2>&1 </dev/null', $out, $code);
            if ($code !== 0) {
                $after = $this->notice($dir);
                $why = $after !== $before ? trim(substr($after, strpos($after, ':') + 1)) : '';
                throw new CliError('qBittorrent was stopped and the PMSS launcher refused to start it: ' . ($why !== '' ? $why : "exit $code, no reason recorded"), 'the user starts qBittorrent in the Pulsed Media panel (Apps); this tool does not fall back to a bare start');
            }
        } finally {
            PmssClientLock::release($lock);
        }
        $r['how'] = $stopText . "through the account's PMSS launcher (torrentClientsResolve.php qbittorrent --launch --held --system) under the account's qBittorrent lock: the selected build, or the system build when none is selected";
        return $r;
    }

    /** The launcher's last refusal text, with its change time in front (so an old, unchanged notice is never reported). */
    private function notice($dir)
    {
        $f = $dir . '/qbittorrent.notice';
        return is_file($f) ? filemtime($f) . ':' . (string) file_get_contents($f) : '';
    }

    private function exec($cmd, $label)
    {
        exec($cmd . ' >/dev/null 2>&1 </dev/null', $out, $code);
        if ($code !== 0) {
            throw new CliError("`$label` failed (exit $code)", $code === 127 ? 'the command is not installed here' : 'see the client log; nothing else was changed');
        }
    }
}
