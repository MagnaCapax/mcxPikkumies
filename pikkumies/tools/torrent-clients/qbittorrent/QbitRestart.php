<?php
/**
 * QbitRestart: restart qBittorrent the way the host does it, then check that it answers.
 * - PMSS: only while ~/.qbittorrentEnable exists (the panel creates it on start, the watchdog honours
 *   it: PMSS etc/skel/www/scriptsInc.php:1765-1768, scripts/lib/user/watchdog.php:179-180). Graceful
 *   stop of the own process (QbitProc), then the panel's start command `qbittorrent-nox -d`
 *   (etc/skel/www/qbittorrent.php:10), run from $HOME as the watchdog does (checkQbittorrentInstances.php:16).
 * - Ultra.cc: `app-qbittorrent restart` (docs.ultra.cc/applications/qbittorrent, research §4).
 * - Feral, Whatbox, others: refused with the provider's own instructions (Feral documents only
 *   `pkill -f`, which this tool never uses, and says qBittorrent cannot be started by hand).
 */
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
            $r = array_merge($r, $this->proc->stop());
            $this->exec('cd ' . escapeshellarg($ep->home) . ' && qbittorrent-nox -d', 'qbittorrent-nox -d');
            $r['how'] = 'PMSS: own qbittorrent-nox stopped (SIGTERM, SIGKILL only after 10 s), then started with `qbittorrent-nox -d` as the panel does';
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

    private function exec($cmd, $label)
    {
        exec($cmd . ' >/dev/null 2>&1 </dev/null', $out, $code);
        if ($code !== 0) {
            throw new CliError("`$label` failed (exit $code)", $code === 127 ? 'the command is not installed here' : 'see the client log; nothing else was changed');
        }
    }
}
