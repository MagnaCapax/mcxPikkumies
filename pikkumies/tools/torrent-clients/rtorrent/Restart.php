<?php
/**
 * RtorrentRestart: `restart` the way the provider does it, never by killing processes by name.
 * PMSS: refuse while ~/.rtorrentDisable exists (the user stopped rtorrent in the panel and the
 * watchdog keeps it stopped, PMSS scripts/cron/checkRtorrent.php:72-80); otherwise touch
 * ~/www/.rtorrentRestart and run `php ./.rtorrentRestart.php` from $HOME, as PMSS userTransfer does
 * (scripts/lib/userTransfer/postSetup.php:87-139), then poll until rtorrent answers, up to 30 s
 * (postSetup.php:164-178). The script itself stops the user's own rtorrent and starts it again
 * (PMSS etc/skel/.rtorrentRestart.php:12-13, 106-140). Other providers: their own way, as a hint.
 * An answer alone does not prove a restart: that script finds rTorrent by the exact name `rtorrent`, while a
 * running rTorrent of some builds is named `rtorrent main` (PMSS issue #717), so the old process can keep
 * answering. Success therefore also needs every rTorrent process from before (pid and start time) to be gone.
 * This tool never stops rTorrent itself (ADR-0064).
 */
require_once dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/OwnProcs.php';

class RtorrentRestart
{
    const WAIT = 30;
    /** The /proc to read; a test seam. */
    public static $proc = '/proc';

    /** What each provider documents (research notes §4; tools/torrent-clients/README.md). */
    const HINTS = [
        'ultra'     => 'run `app-rtorrent restart` (Ultra.cc documentation)',
        'whatbox'   => 'restart it from the Manage page of the Whatbox web site',
        'feral'     => 'Feral starts rtorrent again within five minutes when it is not running (Feral wiki)',
        'seedhost'  => 'restart it from the SeedHost Client Area',
        'generic'   => 'restart rtorrent the way it was started on this machine (a systemd user unit, screen or tmux)',
    ];

    public static function run(RtorrentClient $c)
    {
        if ($c->family !== 'pmss') {
            $hints = self::HINTS;
            $hint = array_key_exists($c->family, $hints) ? $hints[$c->family] : $hints['generic'];
            throw new CliError('not restarted: on this host rtorrent is restarted by the provider, and this tool does not kill processes', $hint);
        }
        $home = $c->home;
        if (is_file("$home/.rtorrentDisable")) {
            throw new CliError('not restarted: rtorrent was stopped in the panel (~/.rtorrentDisable), and PMSS keeps it stopped', 'the user starts it again with the start button in the panel');
        }
        $script = "$home/.rtorrentRestart.php";
        $marker = "$home/www/.rtorrentRestart";
        if (!is_file($script) || is_link($script) || !is_dir("$home/www") || is_link("$home/www") || is_link($marker) || (file_exists($marker) && !is_file($marker))) {
            throw new CliError('not restarted: the PMSS restart files are missing or unsafe (~/.rtorrentRestart.php, ~/www/.rtorrentRestart)', 'use the restart button in the panel');
        }
        $names = Detect::KNOWN_APPS['rtorrent'];
        $before = OwnProcs::identities($names, $c->uid, self::$proc);
        if (!@touch($marker)) {
            throw new CliError('not restarted: cannot create ~/www/.rtorrentRestart', 'check the free space and permissions of ~/www');
        }
        $null = ['file', '/dev/null', 'w'];
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ./.rtorrentRestart.php', [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, $home);
        if (!is_resource($p)) {
            throw new CliError('not restarted: could not run ~/.rtorrentRestart.php', 'use the restart button in the panel; the marker is set, so PMSS cron runs it within a minute');
        }
        proc_close($p); // the script waits for the old process to stop, then starts the new one in screen
        $deadline = time() + self::WAIT;
        $answered = false;
        $last = '';
        do {
            $c->forget();
            try {
                $version = $c->call('system.client_version', [], 3);
                $answered = true;
                break;
            } catch (CliError $e) {
                $last = $e->getMessage();
            }
            sleep(1);
        } while (time() < $deadline);
        if (!$answered) {
            throw new CliError('restart requested, but rtorrent did not answer within ' . self::WAIT . " s ($last)", 'check again with `stats` in a minute; the PMSS watchdog also starts rtorrent');
        }
        return self::proven($before, $names, $c->uid) + ['how' => 'PMSS: ~/www/.rtorrentRestart, then php ~/.rtorrentRestart.php (as PMSS userTransfer does)', 'answered' => true, 'version' => $version];
    }

    /**
     * The restart counts only when no rTorrent process from before is still running (same pid and start time)
     * and at most one runs now. Otherwise it is an error that says what is running, and nothing is stopped.
     */
    private static function proven(array $before, array $names, $uid)
    {
        $after = OwnProcs::identities($names, $uid, self::$proc);
        $kept = array_intersect_assoc($before, $after);
        if (count($after) > 1) {
            throw new CliError('not restarted cleanly: ' . count($after) . ' rTorrent processes are running for this account and share one session',
                'do not stop either by hand; the PMSS watchdog checks every 2 minutes and restarts rTorrent when more than one runs; check `stats` in a few minutes and open a support ticket if two still run');
        }
        if ($kept) {
            throw new CliError('not restarted: the rTorrent process from before the restart is still running, so the platform restart did not take effect (a known platform issue, PMSS #717; the panel\'s restart button runs the same script)',
                'do not stop rTorrent by hand; tell the user, and open a support ticket with this message');
        }
        return ['processes_before' => count($before), 'processes_after' => count($after)];
    }
}
