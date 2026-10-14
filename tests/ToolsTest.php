<?php
return [
    'guard hook blocks rm -rf' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -rf /home/x']]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'block' && $d['message'] !== '', 'rm -rf not blocked: ' . $out);
    },
    'guard hook blocks when the check itself fails (regex engine limit)' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        // The dd check runs out of backtracking in the first segment before it reaches the real dd.
        $cmd = str_repeat('dd ', 700000) . '| dd if=/dev/zero of=/dev/sdz';
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'block', 'regex error let the call through');
    },
    'guard hook asks the user (approve) for data deletes, one decision per command' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        $ask = function ($cmd) use ($hook) {
            list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
            return json_decode($out, true);
        };
        foreach ([
            'rclone sync ~/data remote:backup',
            'rclone --config ~/.config/rclone/rclone.conf purge remote:old',
            'restic -r /backup forget --keep-last 3 --prune',
            'borg prune --keep-daily 7 ~/repo',
            "curl -s 'http://127.0.0.1:8080/api/v2/torrents/delete' -d 'hashes=abc&deleteFiles=true'",
            'curl -X DELETE http://localhost:8989/api/v3/series/1?deleteFiles=true',
            'php ~/pikkumies/tools/torrent-clients/rtorrent/rtorrent.php remove abc --with-data',
            'php ~/pikkumies/tools/backup/restic/restic.php prune --keep 3',
            // A quoted argument with ";" or "&" before the flag must not hide it.
            'php ~/pikkumies/tools/torrent-clients/qbittorrent/qbittorrent.php remove "Tom & Jerry 1940" --with-data',
            'php ~/pikkumies/tools/torrent-clients/deluge/deluge.php remove "a;b" --with-data',
            'rclone --config "a&b" delete remote:x',
            'rclone bisync ~/a remote:a',
            "curl http://127.0.0.1:8989/api/v3/movie/1 -X DELETE",
            "curl --request 'DELETE' http://0.0.0.0:7878/api/v3/movie/2",
            'php ~/pikkumies/tools/backup/restic/restic.php --human prune',
        ] as $cmd) {
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], $cmd) !== false, "no approval asked for: $cmd");
        }
        $a = $ask('restic -r /a forget --keep-last 1');
        $b = $ask('restic -r /b forget --keep-last 1');
        check($a['message'] !== $b['message'] && strpos($a['rule_key'], 'pikkumies-delete:') === 0 && $a['rule_key'] !== $b['rule_key'], 'two different deletes must not share one approval (rule_key)');
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'execute_code', 'tool_input' => ['code' => "import os; os.system('restic -r /a forget --prune')"]]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'approve', 'execute_code bypassed the guard');
        foreach (['import subprocess; subprocess.run(["rclone", "purge", "remote:old"])', 'import requests; requests.delete("http://127.0.0.1:8989/api/v3/series/1")', 'rpc("torrent-remove", {"ids": [1], "delete-local-data": True})'] as $code) {
            list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'execute_code', 'tool_input' => ['code' => $code]]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'approve', "execute_code delete not asked: $code");
        }
        $long = str_repeat('echo ok; ', 40) . 'rclone purge remote:';
        $d = $ask($long);
        check($d['action'] === 'approve' && strpos($d['message'], 'rclone purge remote:') !== false, 'the user must see the whole command they approve');
        $d = $ask(str_repeat('echo ok; ', 500) . 'rclone purge remote:');
        check($d['action'] === 'block', 'a delete too long to show in full must be refused, not half-shown');
        foreach (['rclone ls remote:', 'rclone copy ~/a remote:a', 'restic snapshots', 'borg list ~/repo', 'curl http://127.0.0.1:8080/api/v2/torrents/info'] as $cmd) {
            $out = $ask($cmd);
            check($out === null, "read-only command flagged: $cmd");
        }
    },
    'guard hook ignores harmless and non-terminal calls' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls -la']]));
        check(trim($out) === '', 'harmless command flagged');
        list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'read_file', 'tool_input' => ['path' => 'x']]));
        check(trim($out) === '', 'non-terminal flagged');
    },
    'memory: save writes the lesson, no overwrite; docs search finds it' => function () {
        $tmp = sys_get_temp_dir() . '/pk-mem-' . getmypid();
        @mkdir("$tmp/pikkumies/tools/memory", 0700, true);
        mkdir("$tmp/pikkumies/memory");
        chmod("$tmp/pikkumies/memory", 0755); // as git clone leaves this tracked directory
        copy(dirname(__DIR__) . '/pikkumies/tools/memory/memory.php', "$tmp/pikkumies/tools/memory/memory.php");
        @mkdir("$tmp/pikkumies/lib", 0700, true);
        foreach (['TextSearch', 'Config', 'DocSources', 'Http', 'Host'] as $lib) {
            copy(dirname(__DIR__) . "/pikkumies/lib/$lib.php", "$tmp/pikkumies/lib/$lib.php");
        }
        @mkdir("$tmp/pikkumies/tools/docs", 0700, true);
        copy(dirname(__DIR__) . '/pikkumies/tools/docs/docs.php', "$tmp/pikkumies/tools/docs/docs.php");
        file_put_contents("$tmp/body.md", "rtorrent stopped because the disk was full; freed space and restarted it.");
        $tool = "$tmp/pikkumies/tools/memory/memory.php";
        list($code, $out) = runPhp($tool, ['save', 'rtorrent stops on full disk', "$tmp/body.md", '--tags', 'rtorrent,disk']);
        check($code === 0 && strpos($out, 'saved:') !== false, 'save failed: ' . $out);
        check((fileperms("$tmp/pikkumies/memory") & 0777) === 0700, 'lessons directory not 0700');
        list($code) = runPhp($tool, ['save', 'rtorrent stops on full disk', "$tmp/body.md"]);
        check($code !== 0, 'overwrite allowed');
        $docs = "$tmp/pikkumies/tools/docs/docs.php";
        list(, $out) = runPhp($docs, ['search', 'rtorrent disk full', '--offline']);
        check(strpos($out, 'rtorrent-stops-on-full-disk') !== false && strpos($out, '[lessons]') !== false, 'search missed lesson: ' . $out);
        list(, $out) = runPhp($docs, ['search', 'nginx certificate', '--offline']);
        check(strpos($out, 'no matches') !== false, 'unrelated query matched: ' . $out);
        list($code, $out) = runPhp($tool, ['search', 'x']);
        check($code === 2, 'memory.php search must point to docs.php');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'auto-update cron: one tagged line, other lines kept, off removes it, a failing crontab -l writes nothing' => function () {
        $tmp = sys_get_temp_dir() . '/pk-cron-' . getmypid();
        @mkdir($tmp, 0700, true);
        // A fake crontab command: never the real one.
        file_put_contents("$tmp/crontab", "#!/bin/sh\nf=\"$tmp/tab\"\nif [ \"\$1\" = -l ]; then\n  [ -f \"$tmp/fail\" ] && { echo 'crontab: permission denied' >&2; exit 1; }\n  [ -f \"\$f\" ] && exec cat \"\$f\"\n  echo 'no crontab for u' >&2; exit 1\nfi\ncp \"\$1\" \"\$f\"\n");
        chmod("$tmp/crontab", 0700);
        Update::$crontab = "$tmp/crontab";
        $on = Config::defaults();
        check(strpos(Update::schedule($on, 1061), 'daily') === 0, 'first schedule');
        $tab = file_get_contents("$tmp/tab");
        check(substr_count($tab, Update::CRON_TAG) === 1 && strpos($tab, '41 17 * * * ') === 0 && strpos($tab, ' update > ') !== false, 'line: ' . $tab);
        file_put_contents("$tmp/tab", "@reboot ~/start-rtorrent\n" . $tab . "0 1 * * * echo keep");
        Update::schedule($on, 1061);
        $tab = file_get_contents("$tmp/tab");
        check(substr_count($tab, Update::CRON_TAG) === 1 && strpos($tab, "@reboot ~/start-rtorrent\n") === 0 && strpos($tab, "0 1 * * * echo keep\n") !== false, 'foreign lines lost: ' . $tab);
        $off = Config::merge($on, ['autoUpdate' => ['disabled' => true]]);
        Update::schedule($off, 1061);
        check(strpos(file_get_contents("$tmp/tab"), Update::CRON_TAG) === false, 'off did not remove the line');
        $before = file_get_contents("$tmp/tab");
        touch("$tmp/fail");
        check(strpos(Update::schedule($on, 1061), 'not changed') === 0 && file_get_contents("$tmp/tab") === $before, 'wrote after a failed crontab -l');
        Update::$crontab = 'crontab';
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'update: fast-forwards a depth-1 clone, refuses local edits' => function () {
        $tmp = sys_get_temp_dir() . '/pk-upd-' . getmypid();
        $g = function ($dir, $args) {
            exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $o, $c);
            check($c === 0, "git $args: " . implode(' ', $o));
            return trim(implode("\n", $o));
        };
        @mkdir("$tmp/src", 0700, true);
        $g("$tmp/src", 'init -q -b main');
        file_put_contents("$tmp/src/a.txt", "1\n");
        $g("$tmp/src", 'add a.txt');
        $g("$tmp/src", 'commit -q -m one');
        $g($tmp, 'clone -q --depth 1 file://' . "$tmp/src" . ' co');
        file_put_contents("$tmp/src/a.txt", "2\n");
        $g("$tmp/src", 'commit -q -am two');
        Update::$root = "$tmp/co";
        list($ok, $msg) = Update::run();
        check($ok && strpos($msg, 'updated') === 0, 'shallow update failed: ' . $msg);
        check(file_get_contents("$tmp/co/a.txt") === "2\n", 'content not updated');
        file_put_contents("$tmp/co/a.txt", "local\n");
        list($ok) = Update::run();
        check(!$ok && file_get_contents("$tmp/co/a.txt") === "local\n", 'local edit not protected');
        Update::$root = null;
        exec('rm -rf ' . escapeshellarg($tmp));
    },
];
