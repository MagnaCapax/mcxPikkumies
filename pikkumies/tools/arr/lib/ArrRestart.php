<?php
/**
 * ArrRestart: restart the way the provider does it, then check the app answers with a newer startTime.
 * - PMSS: exactly the panel's own restart (etc/skel/www/mediaStack.php:70-107): refused while
 *   ~/.<app>Disable exists, then `/bin/bash ~/install-media-stack.sh --stop-app=<app>` and
 *   `--start-app=<app>` from $HOME (installer flags: install-media-stack.sh:152-153, 171-178, 320-331).
 *   The installer must be this uid's own file. Success also needs the tmux session named after the app
 *   (install-media-stack.sh:101, 300, 1949), the one the watchdog tests (scripts/lib/mediaStackWatchdog.php:64)
 *   and the panel lists (etc/skel/www/scriptsInc.php:1211-1214).
 *   Never the installer without those flags, never POST system/restart: Sonarr v4 restarts by spawning a
 *   new process (AppLifetime.cs:70-78), which leaves the tmux session the PMSS watchdog checks.
 * - Docker: `docker restart <app>` against the user's own daemon (a socket this uid owns).
 * - Elsewhere: refused, with a hint.
 * $marker (Bazarr, Autobrr): fn() => ['at' => unix seconds or 0, 'id' => what changes on a restart], or null
 * while the app does not answer; without it, system/status startTime must get newer.
 */
class ArrRestart
{
    public static function run(array $app, array $where, callable $client, ?callable $marker = null)
    {
        $mark = function () use ($client, $marker) {
            if ($marker !== null) {
                return call_user_func($marker);
            }
            $t = self::startTime($client);
            return $t === null ? null : ['at' => $t, 'id' => $t];
        };
        $before = $mark();
        $pmss = $where['source'] === 'pmss';
        if ($pmss) {
            $how = self::pmss($app, $where);
        } elseif ($where['source'] === 'docker') {
            list($code, , $err) = ArrShell::run(['docker', 'restart', $app['id']], ['DOCKER_HOST' => 'unix://' . $where['docker']]);
            if ($code !== 0) {
                throw new CliError("docker restart {$app['id']} failed (exit $code): " . substr(trim($err), 0, 300),
                    'check the container with `docker ps -a`');
            }
            $how = 'Docker: docker restart (the user\'s own daemon)';
        } else {
            throw new CliError("restart is done by the provider here, not by this tool ({$app['name']} found at {$where['config']})",
                $where['family'] === 'pmss' ? 'this copy was not installed by install-media-stack.sh; restart it the way it was started'
                    : 'restart it from the provider\'s panel or the way it was started');
        }
        $after = null;
        $tmux = $pmss ? 'unknown' : null;
        $wait = (int) getenv('PIKKUMIES_ARR_WAIT');
        $deadline = microtime(true) + ($wait > 0 && $wait < ArrCommand::WAIT ? $wait : ArrCommand::WAIT);
        while (microtime(true) < $deadline) {
            $t = $after !== null ? $after : $mark();
            $after = $t !== null && ($before === null || ($marker === null ? $t['at'] > $before['at'] : $t['id'] !== $before['id']))
                ? $t : null;
            $tmux = $pmss ? ArrShell::tmuxState($app['id']) : null;
            if ($after !== null && $tmux !== 'stopped') {
                break;
            }
            usleep(1000000);
        }
        if ($after !== null && $tmux === 'stopped') {
            throw new CliError("{$app['name']} answers again, but its tmux session `{$app['id']}` is not running,"
                . ' so the PMSS panel and watchdog see it as stopped',
                'the user restarts it in the PMSS panel (Media stack); do not start a second copy');
        }
        $out = ['how' => $how, 'answered' => $after !== null, 'started_at' => $after !== null ? $after['at'] : 0,
            'previous_started_at' => $before !== null ? $before['at'] : 0];
        if ($pmss) {
            $out['tmux'] = $tmux;
        }
        if ($after === null) {
            $out['note'] = 'no newer start time within the wait; the app may still be starting. Check with `stats` in a minute';
        } elseif ($tmux === 'unknown') {
            $out['note'] = 'tmux is not in PATH, so the tmux session the PMSS panel checks could not be checked';
        }
        return $out;
    }

    /** The panel's restart; throws with nothing stopped when it is turned off or the installer is not this user's own. */
    private static function pmss(array $app, array $where)
    {
        $id = $app['id'];
        $home = $where['home'];
        $script = "$home/install-media-stack.sh";
        $marker = "$home/.{$id}Disable";
        if (is_link($marker) || file_exists($marker)) {
            throw new CliError("{$app['name']} is turned off (~/.{$id}Disable exists), so it is not restarted",
                'the user stopped it in the PMSS panel; only the user starts it again there');
        }
        $aliases = "$home/.bashrc.custom";
        if (!is_file($script) || is_link($script) || !is_readable($script) || !is_file($aliases) || is_link($aliases)) {
            throw new CliError('the media-stack installer or its launch aliases are missing (~/install-media-stack.sh, ~/.bashrc.custom)',
                'the user reruns `bash ~/install-media-stack.sh` once; nothing was stopped');
        }
        if (@fileowner($script) !== $where['uid']) {
            throw new CliError("~/install-media-stack.sh in $home is not this user's own file (uid {$where['uid']}); not running it",
                'nothing was stopped; restart it in the PMSS panel');
        }
        $env = ['HOME' => $home];
        list($stop, , $stopErr) = ArrShell::run(['/bin/bash', $script, "--stop-app=$id"], $env, $home);
        list($start, $out, $err) = ArrShell::run(['/bin/bash', $script, "--start-app=$id"], $env, $home);
        if ($stop !== 0 || $start !== 0) {
            $msg = trim(preg_replace('/\x1b\[[0-9;]*m/', '', $start !== 0 ? $err . ' ' . $out : $stopErr));
            throw new CliError("the installer could not restart {$app['name']} (stop exit $stop, start exit $start)"
                . ($msg !== '' ? ': ' . substr($msg, 0, 300) : ''), 'run `stats`; the user can also use the PMSS panel (Media stack)');
        }
        return 'PMSS: install-media-stack.sh --stop-app, then --start-app (as the panel does)';
    }

    /** system/status startTime as unix seconds, or null when the app does not answer. */
    private static function startTime(callable $client)
    {
        try {
            $s = call_user_func($client)->call('GET', 'system/status', [], null, ['timeout' => 5]);
            return ArrRecords::time(is_array($s) ? $s : [], 'startTime') ?: null;
        } catch (CliError $e) {
            return null;
        }
    }
}
