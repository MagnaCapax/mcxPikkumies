<?php
/**
 * The guard on ~/.media-stack-credentials.txt (ADR-0078 amendment 2026-10-19): the agent's terminal, code and file tools
 * may not read, copy, search or write it, the way local/secrets is blocked; the login verbs, which read it in their own
 * PHP and never name it, get no answer. guardToolAnswer() is defined in HookGuardCoverageTest.php.
 */
if (!function_exists('guardToolAnswer')) {
    require __DIR__ . '/HookGuardCoverageTest.php';
}

/**
 * The guard's answer with HOME set to a temp home that holds the credentials file and a subfolder; $cwd: the payload's cwd
 * ('home' for that folder). The guard expands globs and resolves search roots against it, so the file must exist.
 */
function guardMediaAnswer($tool, array $input, $cwd = '')
{
    $dir = sys_get_temp_dir() . '/pk-gmc-' . getmypid();
    @mkdir("$dir/home/sub", 0700, true);
    touch("$dir/home/.media-stack-" . 'credentials.txt');
    touch("$dir/home/sub/notes.txt");
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

return [
    'guard: a command, code or file tool call that names the media-stack credentials file is blocked and says where it is' => function () {
        $f = '.media-stack-' . 'credentials.txt';
        $rows = [
            ['terminal', ['command' => "cat ~/$f"]],
            ['terminal', ['command' => "grep SONARR /home/u/$f"]],
            ['terminal', ['command' => 'sed -n 1p ~/.media-stack-cred*']],
            ['terminal', ['command' => "cp ~/$f /tmp/x && cat /tmp/x"]],
            ['terminal', ['command' => "c\"at\" ~/'$f'"]],
            ['execute_code', ['code' => "print(open('/home/u/$f').read())"]],
            ['process_manage', ['action' => 'submit', 'session_id' => 'p1', 'data' => "less ~/$f"]],
            ['read_file', ['path' => "~/$f"]],
            ['read_file', ['path' => "/home/u//$f"]],
            ['search_files', ['pattern' => 'PASSWORD', 'path' => "/home/u/$f"]],
            ['write_file', ['path' => "~/$f", 'content' => 'X = y']],
            ['patch', ['patch' => "*** Begin Patch\n*** Update File: /home/u/$f\n@@\n-a\n+b\n*** End Patch"]],
        ];
        foreach ($rows as $r) {
            $d = guardToolAnswer($r[0], $r[1]);
            check($d !== null && $d['action'] === 'block' && strpos($d['message'], '~/.media-stack-credentials.txt') !== false
                && strpos($d['message'], 'in their own terminal') !== false, "{$r[0]} " . json_encode($r[1]) . ' => ' . json_encode($d));
        }
    },
    'guard: the login, repair and config verbs get no answer; the installer is still asked, never blocked' => function () {
        $t = '~/pikkumies/tools';
        foreach (["php $t/arr/sonarr/sonarr.php login-check", "php $t/arr/radarr/radarr.php set-login", "php $t/arr/prowlarr/prowlarr.php repair",
            "php $t/arr/sonarr/sonarr.php config show host", "php $t/arr/sonarr/sonarr.php config set ui firstDayOfWeek 1",
            "php $t/media-servers/jellyfin/jellyfin.php set-login", "php $t/media-servers/jellyfin/jellyfin.php config set MinResumePct 5",
            "php $t/arr/autobrr/autobrr.php set-login", "php $t/arr/bazarr/bazarr.php config set general debug true",
            "php $t/arr/jackett/jackett.php login-check",
            'ls -la ~/.config/sonarr'] as $cmd) {
            $d = guardToolAnswer('terminal', ['command' => $cmd]);
            check($d === null, "$cmd => " . json_encode($d));
        }
        check(guardToolAnswer('read_file', ['path' => '~/.config/sonarr/config.xml.notes']) === null, 'an ordinary file was blocked');
        $d = guardToolAnswer('terminal', ['command' => 'bash ~/install-media-stack.sh --secure-app=sonarr']); // asked as before (an installer run), never blocked
        check($d !== null && $d['action'] === 'approve', 'installer: ' . json_encode($d));
    },
    'guard: a glob that expands to the credentials file is blocked; one that cannot reach it is not' => function () {
        $rows = [
            ['cat ~/.media*', 'block'], ['cat ~/.media-stack-c*', 'block'], ['cat ~/.m?dia-stack-credentials.txt', 'block'],
            ['head $HOME/.[m]edia*', 'block'], ['cat ${HOME}/.m*', 'block'], ['tar czf /tmp/x.tgz ~/.*', 'block'],
            ['cat ~/.media-stack-{cred,x}*', 'block'], ['cat .m*', 'block', 'home'], ['cd ~ && cat .me*', 'block'],
            ['ls ~/*', 'none'], ['cat ~/sub/.media*', 'none'], ['cat .m*', 'none', '/tmp'], ["grep 'a.*' ~/sub/notes.txt", 'none'],
            ['ls ~/sub/*.txt', 'none'],
        ];
        foreach ($rows as $r) {
            $got = guardMediaAnswer('terminal', ['command' => $r[0]], isset($r[2]) ? $r[2] : '');
            check($got === $r[1], "{$r[0]}: expected {$r[1]}, got $got");
        }
        check(guardMediaAnswer('execute_code', ['code' => 'import glob; print(open(glob.glob("' . sys_get_temp_dir() . '/pk-gmc-' . getmypid() . '/home/.m*")[0]).read())']) === 'block', 'a quoted path glob in code');
    },
    'guard: a content search from the home folder itself is blocked unless it excludes the file; from a subfolder it is allowed' => function () {
        $rows = [
            ['grep -r password ~', 'block'], ['grep -Rn password $HOME/', 'block'], ['egrep --recursive pass ~', 'block'],
            ['rg password ~', 'block'], ['ag password ~', 'block'], ['find ~ -type f -exec grep -l pass {} +', 'block'],
            ['find ~ -type f | xargs grep pass', 'block'], ['cd ~ && grep -r pass .', 'block'], ['grep -r pass', 'block', 'home'],
            ['rg pass', 'block', 'home'],
            ['grep -r password ~/sub', 'none'], ['rg password ~/sub', 'none'], ['find ~ -name "*.log"', 'none'],
            ['find ~/sub -type f -exec grep pass {} +', 'none'], ['grep password ~/sub/notes.txt', 'none'], ['grep -r pass .', 'none', '/tmp'],
            ["grep -r pass ~ --exclude='.media-stack-*'", 'none'], ["rg pass ~ -g '!.media-stack-*'", 'none'], ['ag pass ~ --ignore .media-stack-*', 'none'],
            ["find ~ -type f ! -name '.media-stack-*' -exec grep pass {} +", 'none'], ["rg pass ~ -g '*.log'", 'block'],
        ];
        foreach ($rows as $r) {
            $got = guardMediaAnswer('terminal', ['command' => $r[0]], isset($r[2]) ? $r[2] : '');
            check($got === $r[1], "{$r[0]}: expected {$r[1]}, got $got");
        }
        foreach ([[['pattern' => 'pass', 'path' => '~'], 'block'], [['pattern' => 'pass', 'path' => '$HOME'], 'block'],
            [['pattern' => 'pass', 'path' => '.'], 'block', 'home'], [['pattern' => 'pass', 'path' => '~', 'target' => 'files'], 'none'],
            [['pattern' => 'pass', 'path' => '~', 'file_glob' => '*.log'], 'none'], [['pattern' => 'pass', 'path' => '~/sub'], 'none'],
            [['pattern' => 'pass', 'path' => '.'], 'none', '/tmp']] as $r) {
            $got = guardMediaAnswer('search_files', $r[0], isset($r[2]) ? $r[2] : '');
            check($got === $r[1], 'search_files ' . json_encode($r[0]) . ": expected {$r[1]}, got $got");
        }
        exec('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/pk-gmc-' . getmypid()));
    },
];
