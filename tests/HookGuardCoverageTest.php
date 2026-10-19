<?php
/**
 * Guard coverage beyond terminal and execute_code (pre-public security review 2026-10-17): Hermes' file tools on the
 * AI key and stored passwords, text written into a background shell (process_manage), scheduled script jobs
 * (cronjob_manage), and command shapes the earlier rules missed (rsync --del, git clean/reset --hard, crontab -r,
 * the list form of rm in code, web servers, web shells, tunnels and a container API on a network address).
 * guardKindTable() is defined in HookGuardContainersTest.php; this file loads it when run alone.
 */
if (!function_exists('guardKindTable')) {
    require __DIR__ . '/HookGuardContainersTest.php';
}

/** The guard's decoded answer for one tool call, or null for no answer. */
function guardToolAnswer($tool, array $input)
{
    list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
        json_encode(['tool_name' => $tool, 'tool_input' => $input]));
    check($code === 0, "guard exited $code for $tool");
    $d = json_decode($out, true);
    return is_array($d) ? $d : null;
}

return [
    'guard coverage: file tools may not read, search, write or patch the AI key or stored passwords; ordinary files pass' => function () {
        $rows = [
            ['read_file', ['path' => '~/.virsilipasKey'], 'block'],
            ['read_file', ['path' => '/home/u/.pikkumiesKey'], 'block'],
            ['read_file', ['path' => '/home/u/.mcxPikkumies/pikkumies/local/hermes//.env'], 'block'],
            ['read_file', ['path' => '/home/u/.mcxPikkumies/pikkumies/local/hermes/./.env'], 'block'],
            ['search_files', ['pattern' => 'vsk-', 'path' => '~'], 'block'],
            ['search_files', ['pattern' => 'key=', 'path' => '/home/u/.virsilipasKey'], 'block'],
            ['write_file', ['path' => '~/pikkumies/local/secrets/sonarr', 'content' => 'x'], 'block'],
            ['patch', ['path' => '~/.docker/config.json', 'old_string' => 'a', 'new_string' => 'b'], 'block'],
            ['read_file', ['path' => '~/notes/rm -rf ideas.txt'], 'none'],
            ['read_file', ['path' => '~/myapp/.env'], 'none'],
            ['search_files', ['pattern' => 'error', 'path' => '~/logs'], 'none'],
        ];
        foreach ($rows as $r) {
            $d = guardToolAnswer($r[0], $r[1]);
            $got = $d === null ? 'none' : $d['action'];
            check($got === $r[2], "{$r[0]} " . json_encode($r[1]) . ": expected {$r[2]}, got $got");
        }
    },
    'guard coverage: text written into a background shell is checked like a terminal command; poll and kill are not' => function () {
        $d = guardToolAnswer('process_manage', ['action' => 'submit', 'session_id' => 'proc_1', 'data' => 'rm -rf ~/files']);
        check($d !== null && $d['action'] === 'approve' && $d['kind'] === 'delete', 'submit of rm -rf must be asked as a delete (ADR-0073): ' . json_encode($d));
        $d = guardToolAnswer('process_manage', ['action' => 'write', 'session_id' => 'proc_1', 'data' => "cat ~/.virsilipasKey\n"]);
        check($d !== null && $d['action'] === 'block', 'write of a key read must block');
        $d = guardToolAnswer('process_manage', ['action' => 'submit', 'session_id' => 'proc_1', 'data' => 'rm old.log']);
        check($d !== null && $d['action'] === 'approve', 'submit of a plain rm must ask');
        check(guardToolAnswer('process_manage', ['action' => 'kill', 'session_id' => 'proc_1']) === null, 'kill has no opinion');
        check(guardToolAnswer('process_manage', ['action' => 'poll', 'session_id' => 'proc_1', 'data' => 'rm -rf x']) === null, 'poll ignores data');
    },
    'guard coverage: a scheduled job with a script or no-agent mode asks; a plain agent prompt job does not' => function () {
        $in = ['action' => 'create', 'schedule' => '0 3 * * *', 'script' => 'cleanup.sh'];
        $d = guardToolAnswer('cronjob_manage', $in);
        check($d !== null && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-schedule:') === 0, 'script job must ask: ' . json_encode($d));
        check(strpos($d['message'], 'cleanup.sh') !== false, 'the user sees the job');
        $d = guardToolAnswer('cronjob_manage', ['action' => 'update', 'job_id' => 'a1', 'no_agent' => true]);
        check($d !== null && $d['action'] === 'approve', 'switching a job to no-agent mode must ask');
        check(guardToolAnswer('cronjob_manage', ['action' => 'create', 'schedule' => '0 3 * * *', 'prompt' => 'check my quota']) === null, 'prompt job runs');
        check(guardToolAnswer('cronjob_manage', ['action' => 'list']) === null, 'list runs');
    },
    'guard coverage: delete shapes the earlier rules missed ask; their everyday relatives do not' => function () {
        guardKindTable([
            ['rsync -a --del src/ dst/', 'approve', 'delete'],
            ['rsync -a --delete-after src/ dst/', 'approve', 'delete'],
            ['git clean -fdx', 'approve', 'delete'],
            ['git -C ~/app clean --force', 'approve', 'delete'],
            ['git reset --hard origin/main', 'approve', 'delete'],
            ['crontab -r', 'approve', 'delete'],
            ['crontab -l', 'none'],
            ['git status', 'none'],
            ['git clean -n', 'none'],
            ['rsync -a src/ dst/', 'none'],
        ]);
        guardKindTable([['import subprocess; subprocess.run(["rm", "-r", p])', 'approve', 'delete']], 'execute_code');
        guardKindTable([['import subprocess; subprocess.run(["ls", "-la"])', 'none']], 'execute_code');
    },
    'guard coverage: web servers, web shells, tunnels and a network container API ask as exposure; loopback forms do not' => function () {
        guardKindTable([
            ['python3 -m http.server 8000', 'approve', 'exposure'],
            ['cd ~/files && python3 -m http.server', 'approve', 'exposure'],
            ['python3 -m http.server --bind 127.0.0.1 8000', 'none'],
            ['php -S 0.0.0.0:8000 -t ~', 'approve', 'exposure'],
            ['php -S localhost:8000', 'none'],
            ['ttyd bash', 'approve', 'exposure'],
            ['cloudflared tunnel --url http://localhost:8080', 'approve', 'exposure'],
            ['ngrok http 8080', 'approve', 'exposure'],
            ['ngrok --version', 'none'],
            ['rclone rcd --rc-no-auth --rc-addr :5572', 'approve', 'exposure'],
            ['rclone rcd --rc-addr 127.0.0.1:5572 --rc-user u --rc-pass p', 'none'],
            ['dockerd-rootless.sh -H tcp://0.0.0.0:2375', 'approve', 'exposure'],
            ['dockerd-rootless.sh -H unix:///run/user/1000/docker.sock', 'none'],
        ]);
    },
];
