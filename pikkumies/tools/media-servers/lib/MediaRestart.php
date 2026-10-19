<?php
/**
 * MediaRestart: restarts a media server the way this host runs it (README.md of this category,
 * "Restart"), then waits for it to answer. Never through the server's own API (no /System/Restart:
 * it is in no allowlist), never by re-running an installer: only its stop-one-app and start-one-app
 * modes, exactly as the PMSS panel's own restart button does.
 */
class MediaRestart
{
    /**
     * $plan: ['how' => 'pmss-installer', 'home', 'app', 'stop_wait' (s, default 15)]
     * | ['how' => 'docker', 'docker' => MediaDocker, 'container' => [...]] | ['how' => 'refuse', 'why', 'hint'].
     * $answers: fn() => bool (the server answers its public status, no credential). Returns one record: how, answered, state.
     */
    public static function run(array $plan, callable $answers, $wait = 90)
    {
        if ($plan['how'] === 'refuse') {
            throw new CliError($plan['why'], $plan['hint']);
        }
        if ($plan['how'] === 'docker') {
            $c = $plan['container'];
            list($ok, $out) = $plan['docker']->restart($c['id']);
            if (!$ok) {
                throw new CliError("docker restart {$c['name']} failed: " . substr($out, -300),
                    'check the container with `docker ps -a` and `docker logs ' . $c['name'] . '`');
            }
            return self::waitUp($answers, $wait, "docker restart {$c['name']} (the user's own rootless Docker)");
        }
        list($home, $app) = [$plan['home'], $plan['app']];
        self::pmssGate($home, $app);
        $run = function ($flag) use ($home) {
            // PMSS etc/skel/www/mediaStack.php:95-104: cd ~ && HOME=~ /bin/bash ~/install-media-stack.sh --stop-app=APP,
            // then --start-app=APP.
            $env = ['HOME' => $home, 'USER' => self::user(), 'LOGNAME' => self::user()];
            return MediaExec::run(['/bin/bash', "$home/install-media-stack.sh", $flag], $env, 120, $home);
        };
        list($code, $out) = $run("--stop-app=$app");
        if ($code !== 0) {
            throw new CliError("the installer could not stop $app (exit $code): " . self::last($out),
                'check `logs`, or use the PMSS panel');
        }
        $stopWait = isset($plan['stop_wait']) ? (int) $plan['stop_wait'] : 15;
        $until = microtime(true) + $stopWait;
        while ($answers()) { // tmux kill-session returns before the process has exited
            if (microtime(true) >= $until) {
                throw new CliError("the installer's --stop-app=$app finished, but $app still answers after $stopWait s: stop not confirmed;"
                    . ' --start-app was not run', 'check `stats` and `logs`, or restart it from the PMSS panel');
            }
            usleep(1000000);
        }
        list($code, $out) = $run("--start-app=$app");
        if ($code !== 0) {
            throw new CliError("the installer could not start $app (exit $code): " . self::last($out),
                'the PMSS panel shows the media-stack status; `logs` shows why it stopped');
        }
        return self::waitUp($answers, $wait, "PMSS installer --stop-app=$app, then --start-app=$app");
    }

    /** Each provider's own way, from its public docs as the research recorded them (README.md of this category, "Restart"). */
    const ELSEWHERE = [
        'ultra' => ['jellyfin' => 'Ultra.cc documents `app-jellyfin restart` for Jellyfin',
            'plex' => 'Ultra.cc runs Plex in its own container: use its control panel (or `app-plex`)'],
        'feral' => ['plex' => 'Feral restarts Plex through a control file in ~/private/plex/control-files/; read its Plex wiki page first'],
        'whatbox' => ['jellyfin' => 'Whatbox restarts Jellyfin from its Settings page',
            'plex' => 'Whatbox restarts Plex from its Settings page'],
    ];

    /** The refusal for a host or install this tool does not restart. */
    public static function elsewhere($family, $unit, $how)
    {
        $hint = $family === 'pmss' ? 'use the PMSS panel, or restart it the way it was started'
            : 'restart it the way your provider documents, or the way it was started';
        $hint = isset(self::ELSEWHERE[$family][$unit]) ? self::ELSEWHERE[$family][$unit] : $hint;
        // Only Jellyfin has a PMSS media-stack install (JellyfinLocate.php); Emby and Plex run in the user's own container.
        $ways = ($unit === 'jellyfin' ? 'as a PMSS media-stack install or ' : '') . "in a container on the user's own rootless Docker";
        $why = 'restart works for ' . ucfirst($unit) . " $ways; this one was found by $how";
        return ['how' => 'refuse', 'why' => $why, 'hint' => $hint];
    }

    /** The checks the PMSS panel makes before its restart (etc/skel/www/mediaStack.php:70-86, userMediaStackPanel.php:155-160). */
    public static function pmssGate($home, $app)
    {
        $marker = "$home/.{$app}Disable";
        if (is_link($marker) || (file_exists($marker) && !is_file($marker))) {
            throw new CliError("~/.{$app}Disable is not a plain file; not restarting",
                'the PMSS panel refuses this too; look at it with ls -la and tell the user what it is');
        }
        if (file_exists($marker)) {
            throw new CliError(ucfirst($app) . " is turned off on this account (~/.{$app}Disable); not restarting",
                'the user starts it in the PMSS panel (media stack) first; the panel refuses a restart while it is off too');
        }
        $needed = ['install-media-stack.sh' => 'the media-stack installer', '.bashrc.custom' => 'the media-stack launch aliases'];
        foreach ($needed as $f => $what) {
            if (!is_file("$home/$f") || is_link("$home/$f") || !is_readable("$home/$f")) {
                throw new CliError("$what (~/$f) is missing or not a plain file; not restarting",
                    'PMSS puts it back with its next update; the PMSS panel shows the media-stack status');
            }
        }
    }

    private static function waitUp(callable $answers, $wait, $how)
    {
        $until = microtime(true) + $wait;
        do {
            if ($answers()) {
                return ['how' => $how, 'answered' => true, 'state' => 'running'];
            }
            usleep(1000000);
        } while (microtime(true) < $until);
        return ['how' => $how, 'answered' => false, 'state' => "started, not answering after $wait s (unknown, not down)"];
    }

    private static function user()
    {
        $p = function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : false;
        return is_array($p) ? $p['name'] : (string) getenv('USER');
    }

    private static function last($out)
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out)), 'strlen'));
        return $lines ? substr(end($lines), 0, Cli::TEXT_MAX) : '(no output)';
    }
}
