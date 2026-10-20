<?php
/**
 * TransmissionRestart: PMSS only (elsewhere refused, like the other units for a provider not yet
 * researched for Transmission specifically).
 *
 * Stop: SIGTERM then SIGKILL to this account's own `transmission-daemon`, matched the same way PMSS
 * itself matches it when probing for a live process (exact `comm` "transmission-da" -- Linux truncates
 * at 15 bytes -- this uid, this mount namespace: PMSS development/PMSS/etc/skel/www/
 * transmissionLaunch.php:14-29, `pmssTransmissionNativePids()`).
 *
 * Start: the account's own shipped launcher, `php ~/www/torrentClientsResolve.php transmission
 * --launch` -- exactly the command PMSS's own watchdog cron runs for this account (PMSS
 * development/PMSS/scripts/cron/transmissionInstancesCheck.php:20-22) and the CLI tail of PMSS
 * development/PMSS/etc/skel/www/torrentClientsResolve.php:107-110, which calls
 * `pmssTransmissionLaunch()` (etc/skel/www/transmissionLaunch.php): that function resolves the
 * account's selected build, (re)writes the managed settings.json while Transmission is stopped, and
 * starts the daemon itself under its own flock. This tool deliberately does not re-implement any of
 * that catalog/projection resolution -- it runs the same account-owned, unprivileged entrypoint PMSS
 * ships to every account's `~/www/`.
 *
 * As of this unit's writing, PMSS development/PMSS/docs/adr/0108-transmission-socket-integration.md
 * is "Status: Proposed" and says no account yet receives a Transmission selection from the catalog, so
 * this path is verified against the PMSS source only, not against a live restart.
 */
class TransmissionRestart
{
    public static function run(TransmissionClient $c, $home, $family, $uid)
    {
        if ($family !== 'pmss') {
            throw new CliError('restart is done by the provider here, not by this tool', 'restart Transmission the way your provider documents it');
        }
        if (!is_link("$home/bin/transmission/default")) {
            throw new CliError('no Transmission build is selected on this account', 'select one in the PMSS panel (torrent client switch)');
        }
        $stopped = self::stop($uid);
        $script = $home . '/www/torrentClientsResolve.php';
        if (!is_file($script)) {
            throw new CliError('the PMSS launcher script is missing (~/www/torrentClientsResolve.php)', 'ask the provider to restore it');
        }
        $cmd = 'cd ' . escapeshellarg($home) . ' && php ' . escapeshellarg($script) . ' transmission --launch';
        $output = [];
        $rc = 1;
        @exec($cmd, $output, $rc);
        $c->reset();
        $version = null;
        $last = '';
        for ($t = 0; $t < 30 && $version === null; $t++) { // up to 30 s
            sleep(1);
            try {
                $v = $c->rpc()->call('session-get', ['fields' => ['version']]);
                $version = isset($v['version']) ? (string) $v['version'] : '';
            } catch (CliError $e) {
                $last = $e->getMessage();
                $c->reset();
            }
        }
        return ['how' => "PMSS: stopped $stopped process(es) (SIGTERM, then SIGKILL after 3 s), ran the account's own launcher "
            . "(~/www/torrentClientsResolve.php transmission --launch, exit $rc)",
            'answered' => $version !== null, 'version' => $version, 'last_error' => $version === null ? $last : ''];
    }

    /** SIGTERM to this user's own Transmission process, wait up to 3 s, SIGKILL survivors. Returns how many there were. */
    public static function stop($uid)
    {
        $pids = OwnProcs::pids([TransmissionLocate::PROCESS], $uid);
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
            throw new CliError('Transmission did not stop (process ' . implode(', ', self::alive($pids)) . ')', 'nothing was started; check the processes with `ps -u $(whoami)`');
        }
        return count($pids);
    }

    private static function alive(array $pids)
    {
        return array_values(array_filter($pids, function ($p) {
            return is_dir("/proc/$p");
        }));
    }
}
