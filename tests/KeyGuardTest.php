<?php
/**
 * guard-destructive's AI key rule (GuardKeyFiles.php, ADR-0101): the key files are refused when globbed, reached by a
 * relative path after a cd, or swept up by a content search from the home folder; and system units' status, which PID 1
 * answers past hidepid=2, is refused for privacy while the account's own units and is-active stay allowed.
 */

if (!function_exists('keyGuardAnswer')) {
    /** The guard's action ('block', 'approve' or 'none') with HOME at a temp home holding the key files and the Pikkumies local dir. */
    function keyGuardAnswer($tool, array $input, $cwd = '')
    {
        $dir = sys_get_temp_dir() . '/pk-kg-' . getmypid();
        @mkdir("$dir/home/subfolder", 0700, true);
        @mkdir("$dir/home/pikkumies/local/hermes-managed", 0700, true);
        @mkdir("$dir/home/pikkumies/local/hermes", 0700, true);
        foreach (['.virsilipasKey', '.pikkumiesKey', 'pikkumies/local/hermes-managed/.env', 'pikkumies/local/hermes/.env', 'subfolder/notes.txt'] as $f) {
            touch("$dir/home/$f");
        }
        $old = getenv('HOME');
        putenv("HOME=$dir/home");
        try {
            $payload = ['tool_name' => $tool, 'tool_input' => $input] + ($cwd !== '' ? ['cwd' => $cwd === 'home' ? "$dir/home" : $cwd] : []);
            list(, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [], json_encode($payload));
        } finally {
            putenv($old === false ? 'HOME' : "HOME=$old");
        }
        $d = json_decode($out, true);
        return is_array($d) ? $d['action'] : 'none';
    }
}

return [
    'key guard: a glob, a relative path after a cd and a content search from the home folder are refused; a subfolder search is not' => function () {
        $rows = [
            ['cat ~/.v*Key', 'block'], ['cat ~/.p?kkumiesKey', 'block'], ['head -1 $HOME/.[vp]*Key', 'block'],
            ['grep -rh ^key= ~', 'block'], ['grep -r key= .', 'block', 'home'],
            ['cd ~/pikkumies/local/hermes-managed && cat .env', 'block'], ['cd ~/pikkumies/local && cat hermes/.env', 'block'],
            ['cd ~/pikkumies/local/hermes && cat ./.env', 'block'], ['cat ~/pikkumies/local/hermes/../hermes-managed/.env', 'block'],
            ['cat ~/subfolder/notes.txt', 'none'], ['grep -r key ~/subfolder', 'none'], ['cat ~/myapp/.env', 'none'], ['ls ~', 'none'],
        ];
        foreach ($rows as $r) {
            $got = keyGuardAnswer('terminal', ['command' => $r[0]], isset($r[2]) ? $r[2] : '');
            check($got === $r[1], "{$r[0]}: expected {$r[1]}, got $got");
        }
        check(keyGuardAnswer('read_file', ['path' => '.env'], sys_get_temp_dir() . '/pk-kg-' . getmypid() . '/home/pikkumies/local/hermes-managed') === 'block', 'read_file of a relative .env inside the managed dir');
        check(keyGuardAnswer('search_files', ['pattern' => 'key=', 'path' => '~'], '') === 'block', 'search_files from the home folder');
        exec('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/pk-kg-' . getmypid()));
    },
    'key guard: Provider::mask shows stars only' => function () {
        require_once dirname(__DIR__) . '/pikkumies/lib/Provider.php';
        foreach (['vsk-abcdef0123456789abcdef', 'short', str_repeat('k', 64)] as $k) {
            check(Provider::mask($k) === '********', "mask($k): " . Provider::mask($k));
        }
    },
    'privacy: a system unit\'s status, its ControlGroup or MainPID and GetUnitProcesses are refused; --user, is-active and is-enabled are not' => function () {
        $rows = [
            ['systemctl status ssh', 'block'], ['systemctl status', 'block'], ['systemctl --no-pager status sshd.service', 'block'],
            ['systemctl show ssh', 'block'], ['systemctl show -p ControlGroup ssh', 'block'], ['systemctl show --property=MainPID nginx', 'block'],
            ['busctl call org.freedesktop.systemd1 /org/freedesktop/systemd1 org.freedesktop.systemd1.Manager GetUnitProcesses s ssh.service', 'block'],
            ['systemctl --user status pikkumies', 'none'], ['systemctl is-active nginx', 'none'], ['systemctl is-enabled ssh', 'none'],
            ['systemctl show -p DefaultTimeoutStopUSec', 'none'], ['systemctl --user show pikkumies', 'none'],
        ];
        foreach ($rows as $r) {
            $d = json_decode((string) runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $r[0]]]))[1], true);
            $got = is_array($d) ? $d['action'] : 'none';
            check($got === $r[1], "{$r[0]}: expected {$r[1]}, got $got");
            if ($r[1] === 'block' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
                check(strpos($d['message'], 'privacy') !== false && strpos($d['message'], '/') === false, "{$r[0]}: the privacy message, naming no path: " . $d['message']);
            }
        }
    },
];
