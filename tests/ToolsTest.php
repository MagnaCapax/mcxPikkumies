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
        foreach (['TextSearch', 'Config', 'DocSources'] as $lib) {
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
