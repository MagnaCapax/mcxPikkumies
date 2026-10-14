<?php
/** `restart` and the home folder: PMSS installer flags, the tmux session, the installer's owner, Docker elsewhere (tests/fixtures/arr/). */
require_once __DIR__ . '/fixtures/arr/harness.php';

/** A fake PMSS installer: logs its arguments, --start-app counts a start and, when $tmux, makes the tmux session. */
function arrInstaller(array $fx, $tmux)
{
    file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"\$HOME/../installer.log\"\n"
        . "case \"\$1\" in --stop-app=*) rm -f \"\$HOME/../tmux-up\" ;;\n"
        . "  --start-app=*) echo x >> \"\$HOME/../restarts\"" . ($tmux ? "; touch \"\$HOME/../tmux-up\"" : '') . " ;; esac\n");
    file_put_contents($fx['home'] . '/.bashrc.custom', "alias sonarr='true'\n");
}

return [
    'arr restart: PMSS runs --stop-app then --start-app as the panel, waits for a newer start and tmux, refuses when off' => function () {
        $fx = arrFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'installer or its launch aliases are missing') !== false, 'no installer: ' . $out);
            arrInstaller($fx, true);
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['tmux'] === 'running' && $l[0]['started_at'] > $l[0]['previous_started_at'],
                'restart: ' . $out);
            $log = file_get_contents($fx['dir'] . '/installer.log');
            check($log === "--stop-app=sonarr\n--start-app=sonarr\n", 'installer args: ' . $log);
            check(!arrCalls($fx, 'POST', 'system/restart'), 'the API restart was used');
            touch($fx['home'] . '/.sonarrDisable');
            list($code, $l, $out) = arrRun($fx, ['restart']);
            $runs = substr_count(file_get_contents($fx['dir'] . '/installer.log'), "\n");
            check($code === 1 && strpos($out, 'turned off') !== false && $runs === 2,
                'restart while off: ' . $out);
            unlink($fx['home'] . '/.sonarrDisable');
            list($code, $l, $out) = arrRun($fx, ['restart'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/not-pmss']);
            check($code === 1 && strpos($out, 'not installed') !== false, 'off PMSS the PMSS folder is not used: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('radarr', ['where' => 'servarr']);
        try {
            list($code, $l, $out) = arrRun($fx, ['restart'], ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/not-pmss']);
            check($code === 1 && strpos($out, 'done by the provider') !== false, 'generic restart: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr restart: on PMSS an app that answers again but has no tmux session is not a successful restart' => function () {
        $fx = arrFixture('sonarr');
        try {
            arrInstaller($fx, false); // the app comes back (newer start time), but outside its tmux session
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && $l[count($l) - 1]['ok'] === false && strpos($out, 'tmux session `sonarr` is not running') !== false,
                'no tmux: ' . $out);
            check(strpos((string) file_get_contents($fx['dir'] . '/tmux.log'), 'has-session -t =sonarr') !== false,
                'the watchdog\'s own test was not used');
        } finally {
            arrDone($fx);
        }
    },
    'arr restart: an installer that is not this user\'s own file is never run' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $fx = arrFixture('sonarr', ['start' => false]);
        try {
            arrInstaller($fx, true);
            // the folder, seen by another uid: the installer in it (ours) is that account's foreign file
            $where = ['app' => 'sonarr', 'source' => 'pmss', 'home' => $fx['home'], 'uid' => $uid + 1, 'config' => $fx['config'],
                'family' => 'pmss'];
            $msg = '';
            try {
                ArrRestart::run(ArrApps::get('sonarr'), $where, function () {
                    throw new CliError('not reachable');
                });
            } catch (CliError $e) {
                $msg = $e->getMessage();
            }
            check(strpos($msg, 'not this user\'s own file') !== false && !file_exists($fx['dir'] . '/installer.log'),
                'a foreign installer ran: ' . $msg);
        } finally {
            arrDone($fx);
        }
    },
    'arr home: HOME is used only when it is a folder this uid owns, else the passwd entry' => function () {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $own = sys_get_temp_dir() . '/pk-arr-home-' . getmypid() . '-' . mt_rand();
        $foreign = $own . '-foreign';
        mkdir($own, 0700);
        mkdir($foreign, 0700);
        try {
            if ($uid === 0) {
                chown($foreign, 65534); // as root, give it to nobody; otherwise the folder below is root's
            }
            $other = $uid === 0 ? $foreign : '/';
            $pw = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
            $passwd = is_array($pw) ? rtrim($pw['dir'], '/') : '';
            check(ArrLocate::home($uid, $own . '/') === $own, 'own HOME not used');
            check(ArrLocate::home($uid, $other) === $passwd && $passwd !== $other,
                "HOME=$other (not ours) was used: " . ArrLocate::home($uid, $other));
            check(ArrLocate::home($uid, '') === $passwd, 'no HOME: not the passwd entry');
        } finally {
            rmdir($own);
            rmdir($foreign);
        }
    },
];
