<?php
/**
 * RffmpegHost: `add-host`, `status`, `remove` -- the verbs that touch a GPU service (SPEC.md
 * "rffmpeg": transport shell + --allow-shell, the rclone-sftp same-path wrapper, and "remove: undo
 * everything it created, nothing else").
 */
require_once __DIR__ . '/RffmpegRemote.php';
require_once __DIR__ . '/RffmpegVenv.php';

class RffmpegHost
{
    public static function addHost(RffmpegServices $gw, array $args, array $opt, $localDir, $thisHome)
    {
        if (!$args || !is_string($args[0]) || $args[0] === '') {
            throw new CliError('add-host needs a service nick', 'php rffmpeg.php add-host <nick> --allow-shell --mount <remote-path>');
        }
        $nick = $args[0];
        $p = RffmpegState::paths($localDir);
        $state = RffmpegState::read($localDir);
        if ($state['installed'] !== true || !is_file($p['script'])) {
            throw new CliError('rffmpeg is not installed yet', 'run install first: php rffmpeg.php install');
        }
        if (empty($opt['mount']) || !is_string($opt['mount'])) {
            throw new CliError('add-host needs --mount <remote-path> (where this box\'s home is mounted on the GPU host)',
                "the mount point of the rclone sftp mount of this home on that host; plan --gpu $nick --mount <path> checks it first");
        }
        $mount = rtrim($opt['mount'], '/');
        $svc = $gw->shellService($nick, $opt);
        // Review fixes 2, item 8: Services::normalize (lib/Services.php) allows an EMPTY home (only a
        // non-empty one is pattern-checked) -- an empty home would turn the wrapper path into the
        // root-level "/.pikkumies-rffmpeg". Re-validate against the same pattern defensively here.
        if (empty($svc['home']) || !preg_match('#^/[A-Za-z0-9._/-]{1,255}$#', $svc['home'])) {
            throw new CliError("service $nick has no usable home directory", 'set --home on that service (services edit): an absolute path such as /home/xyzjohn');
        }

        if ($state['hosts'] && !isset($state['hosts'][$nick])) {
            // rffmpeg's remote.user/args/ssh are one global block, not per host; a second host must share the first host's identity.
            $first = reset($state['hosts']);
            $sshKey = $gw->secretDir($nick) . '/key';
            if ($first['ssh_user'] !== $svc['user'] || $first['ssh_key'] !== $sshKey) {
                throw new CliError('rffmpeg has one global SSH identity for every host; this service has a different user/key than the first host added',
                    'remove the existing host(s) first, or use one Services identity for every GPU host rffmpeg talks to');
            }
        }

        $remote = RffmpegRemote::probe($gw, $nick);
        if ($remote['ffmpeg_path'] === '' || $remote['ffprobe_path'] === '') {
            throw new CliError("no ffmpeg/ffprobe found on $nick", 'install ffmpeg there first; plan shows the same check');
        }
        if (!$remote['gpu_usable']) {
            throw new CliError("GPU not usable on $nick: {$remote['gpu_gap']}", 'fix that on the GPU host, then retry');
        }
        $mountCheck = RffmpegRemote::mountVisible($gw, $nick, $mount);
        if (!$mountCheck['visible']) {
            throw new CliError("the mount $mount is not visible to a plain SSH-launched command on $nick",
                "this is the systemd --user mount gotcha; test yourself: ssh {$svc['host']} ls $mount -- start the mount a way the SSH session sees, then retry");
        }

        $gpuHome = rtrim($svc['home'], '/');
        $remoteFfmpeg = RffmpegRemote::deployWrapper($gw, $nick, $gpuHome, 'ffmpeg', RffmpegRemote::wrapperScript($thisHome, $mount, $remote['ffmpeg_path']));
        $remoteFfprobe = RffmpegRemote::deployWrapper($gw, $nick, $gpuHome, 'ffprobe', RffmpegRemote::wrapperScript($thisHome, $mount, $remote['ffprobe_path']));

        $sshKey = $gw->secretDir($nick) . '/key';
        RffmpegVenv::writeRemoteConfig($localDir, $svc['user'], $sshKey, $remoteFfmpeg, $remoteFfprobe);

        $venvPython = $p['venv'] . '/bin/python3';
        $r = ToolProc::run([$venvPython, $p['script'], 'add', $svc['host'], '--name', $nick], ['RFFMPEG_CONFIG' => $p['config']]);
        if ($r['code'] !== 0) {
            throw new CliError("rffmpeg add {$svc['host']} failed", trim($r['err']) !== '' ? trim($r['err']) : trim($r['out']));
        }

        $state['hosts'][$nick] = ['host' => $svc['host'], 'ssh_user' => $svc['user'], 'ssh_key' => $sshKey, 'mount' => $mount,
            'remote_home' => $gpuHome, 'ffmpeg_wrapper' => $remoteFfmpeg, 'ffprobe_wrapper' => $remoteFfprobe, 'added_at' => date('c')];
        RffmpegState::write($localDir, $state);
        return [['ok' => true, 'nick' => $nick, 'host' => $svc['host'], 'remote_ffmpeg' => $remoteFfmpeg, 'remote_ffprobe' => $remoteFfprobe,
            'mount' => $mount, 'rffmpeg_add_output' => trim($r['out'])]];
    }

    public static function status($localDir)
    {
        $p = RffmpegState::paths($localDir);
        $state = RffmpegState::read($localDir);
        $rec = ['installed' => $state['installed'] === true, 'script_commit' => $state['script_commit'], 'hosts' => array_keys($state['hosts']),
            'config' => $p['config']];
        $venvPython = $p['venv'] . '/bin/python3';
        if ($rec['installed'] && is_file($venvPython) && is_file($p['script'])) {
            $r = ToolProc::run([$venvPython, $p['script'], 'status'], ['RFFMPEG_CONFIG' => $p['config']]);
            $rec['rffmpeg_status'] = trim($r['out']);
            $rec['rffmpeg_status_rc'] = $r['code'];
        }
        // Review fixes 2, item 6: compare the venv's actually-installed click/PyYAML versions against
        // what install() pinned, so a hand-edited venv (or a future reinstall with a different pin) is
        // visible here rather than silently trusted.
        if ($rec['installed'] && is_file($venvPython)) {
            $f = ToolProc::run([$venvPython, '-m', 'pip', 'freeze']);
            $installed = [];
            foreach (explode("\n", trim($f['out'])) as $line) {
                $eq = strpos($line, '==');
                if ($eq !== false) {
                    $installed[strtolower(substr($line, 0, $eq))] = substr($line, $eq + 2);
                }
            }
            $pins = isset($state['pip_pins']) && is_array($state['pip_pins']) ? $state['pip_pins'] : [];
            $drift = [];
            foreach ($pins as $name => $pinnedVersion) {
                $have = isset($installed[strtolower($name)]) ? $installed[strtolower($name)] : null;
                if ($have !== $pinnedVersion) {
                    $drift[$name] = ['pinned' => $pinnedVersion, 'installed' => $have];
                }
            }
            $rec['pip_pins'] = $pins;
            $rec['pip_drift'] = $drift;
        }
        return [$rec];
    }

    /** --with-data removes the local install (the delete-guard convention, ADR-0020: "with-data, or named prune/forget/purge");
     * --allow-shell (per host, same gate as add-host) also cleans up the GPU-side wrapper files. */
    public static function remove(RffmpegServices $gw, array $opt, $localDir)
    {
        $state = RffmpegState::read($localDir);
        if (empty($opt['with-data'])) {
            return [['ok' => false, 'dry_run' => true, 'would_remove_local' => RffmpegState::paths($localDir)['base'],
                'would_remove_remote_hosts' => array_keys($state['hosts']), 'hint' => 'give --with-data to actually remove it']];
        }
        $allowShell = !empty($opt['allow-shell']);
        $remoteResults = [];
        foreach ($state['hosts'] as $nick => $h) {
            if (!$allowShell) {
                $remoteResults[$nick] = ['ok' => false, 'skipped' => true,
                    'reason' => "no --allow-shell; remote wrapper files left at {$h['ffmpeg_wrapper']} and {$h['ffprobe_wrapper']} on $nick"];
                continue;
            }
            // Review fixes 2, item 10: the RECORDED wrapper paths, never re-derived from the
            // service's current `home` -- a changed or re-added service record must not leave the
            // real files orphaned while `remove` reports success.
            try {
                $remoteResults[$nick] = RffmpegRemote::removeWrapper($gw, $nick, $h['ffmpeg_wrapper'], $h['ffprobe_wrapper']);
            } catch (CliError $e) {
                $remoteResults[$nick] = ['ok' => false, 'skipped' => false, 'stderr' => $e->getMessage()];
            }
        }
        $removedLocal = RffmpegVenv::removeLocal($localDir);
        @unlink(RffmpegState::paths($localDir)['state_file']);
        return [['ok' => true, 'removed_local' => $removedLocal, 'remote' => $remoteResults]];
    }
}
