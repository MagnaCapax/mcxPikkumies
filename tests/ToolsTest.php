<?php
// The guard tests run through hooks/orchestrator.php (ADR-0030), in a throwaway tree so its log stays out of this checkout.
// The tests of what the guard asks run its unit script alone: the orchestrator's ask policy turns every question into a
// block or lets the call run (ADR-0073), so only the unit's own answer still shows the question.
if (!function_exists('orchestratorTree')) {
    require __DIR__ . '/OrchestratorTest.php';
}
$guardTree = orchestratorTree();
$guardOrchestrator = "$guardTree/pikkumies/hooks/orchestrator.php";
$guardUnit = "$guardTree/pikkumies/hooks/guard/guard-destructive/guard-destructive.php";
register_shutdown_function(function () use ($guardTree) {
    exec('rm -rf ' . escapeshellarg($guardTree));
});
return [
    'guard hook blocks rm -rf of a whole home folder' => function () use ($guardOrchestrator) {
        $hook = $guardOrchestrator;
        list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -rf /home/x']]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'block' && $d['message'] !== '', 'rm -rf not blocked: ' . $out);
    },
    'guard hook blocks when the check itself fails (regex engine limit)' => function () use ($guardOrchestrator) {
        $hook = $guardOrchestrator;
        // The dd check runs out of backtracking in the first segment before it reaches the real dd.
        $cmd = str_repeat('dd ', 700000) . '| dd if=/dev/zero of=/dev/sdz';
        list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'block', 'regex error let the call through');
    },
    'guard hook asks the user (approve) for data deletes, one decision per command' => function () use ($guardOrchestrator, $guardUnit) {
        list(, $out) = runPhp($guardOrchestrator, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'rclone sync ~/data remote:backup']]));
        check(json_decode($out, true) === ['action' => 'block', 'message' => AskPolicy::refused(true)], 'through the orchestrator, with no yes to check, the delete is blocked: ' . $out);
        $hook = $guardUnit;
        $ask = function ($cmd) use ($hook) {
            list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
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
            "curl --request=DELETE http://127.0.0.1:7878/public-u/radarr/api/v3/queue/5",
            "curl -X DELETE 'https://host.example/public-u/radarr/api/v3/movie/12?deleteFiles=true'",
            "curl -X POST http://127.0.0.1:8989/api/v3/command -d '{\"name\":\"DeleteSeriesFiles\"}'",
            // The form the USAGE files teach names no .php file (review 2026-10-14).
            'php $Q remove 0123456789abcdef --with-data --expect 41.2GiB',
            'Q=~/pikkumies/tools/torrent-clients/qbittorrent/qbittorrent.php; php $Q remove abc --with-data',
            'php $R prune --keep 3',
            "curl -d '{\"method\":\"core.remove_torrent\",\"params\":[\"abc\",true]}' http://127.0.0.1:8112/json",
            'rm ~/data/big.mkv', 'rm -r ~/data/old', 'cd ~/data && rm *.part', "find ~/data -name '*.part' -delete", 'find ~/data -type f -exec rm {} +',
            'truncate -s 0 ~/data/x', 'shred -u ~/x', 'unlink ~/x',
            'yes | bash ~/install-media-stack.sh', 'bash ~/install-media-stack.sh --uninstall=jellyfin', 'bash ~/install-media-stack.sh --uninstall --dry-run',
            // Storage and backup forms the 2026-10-14 storage review found unasked.
            'rclone rc operations/purge fs=remote: remote=old', "curl -X POST 'http://127.0.0.1:5572/operations/deletefile?fs=remote:&remote=x'",
            'borgmatic', 'borgmatic prune', 'restic key remove abc', 'restic repair snapshots --forget', 'restic -r r restore latest --target ~',
            'borg check --repair ~/repo', 'borg extract ~/repo::a', 'kopia snapshot restore k1 ~/x', 'duplicacy restore -r 3 -overwrite',
            'rsync -a' . ' --delete ~/a/ ~/b/', // to another host the SSH check asks too (HookMergeTest, ADR-0068) "lftp -e 'mirror -R --Remove-source-files a b'", 'rclone serve http ~/data --addr :8080',
            'php $C copy ~/a remote:a --replace', 'php $R move ~/data/done gdrive:archive --expect 40', 'php $R sync ~/a b:c --expect=12',
            "curl -X DELETE 'https://host.example/public-u/jellyfin/Items/abc123?api_key=x'", 'curl --request DELETE http://127.0.0.1:8096/Items?ids=a,b',
            "curl -X DELETE http://127.0.0.1:32400/library/metadata/42", "requests.delete(f'{base}/Auth/Keys/{key}')",
            "curl -X DELETE 'https://host.example/public-u/bazarr/api/episodes/subtitles?seriesid=1&episodeid=2'",
            "curl -X DELETE https://host.example/public-u/autobrr/api/filters/7", "requests.delete(f'{b}/api/keys/{k}')",
        ] as $cmd) {
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], $cmd) !== false, "no approval asked for: $cmd");
        }
        $a = $ask('restic -r /a forget --keep-last 1');
        $b = $ask('restic -r /b forget --keep-last 1');
        check($a['message'] !== $b['message'] && strpos($a['rule_key'], 'pikkumies-delete:') === 0 && $a['rule_key'] !== $b['rule_key'], 'two different deletes must not share one approval (rule_key)');
        list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'execute_code', 'tool_input' => ['code' => "import os; os.system('restic -r /a forget --prune')"]]));
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'approve', 'execute_code bypassed the guard');
        foreach (['import subprocess; subprocess.run(["rclone", "purge", "remote:old"])', 'import requests; requests.delete("http://127.0.0.1:8989/api/v3/series/1")', 'rpc("torrent-remove", {"ids": [1], "delete-local-data": True})',
            'subprocess.run(["php", "/h/pikkumies/tools/arr/radarr/radarr.php", "remove", "12", "--with-data"])', 'requests.delete(f"http://127.0.0.1:{p}/api/v3/movie/1")', 'requests.request("DELETE", "http://127.0.0.1:8989/x")'] as $code) {
            list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'execute_code', 'tool_input' => ['code' => $code]]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'approve', "execute_code delete not asked: $code");
        }
        $long = str_repeat('echo ok; ', 15) . 'rclone purge remote:';
        $d = $ask($long);
        check($d['action'] === 'approve' && strpos($d['message'], 'rclone purge remote:') !== false, 'the user must see the whole command they approve');
        $d = $ask(str_repeat('echo ok; ', 500) . 'rclone purge remote:');
        check($d['action'] === 'block', 'a delete too long to show in full must be refused, not half-shown');
        foreach (['import os; os.remove("/home/u/x")', 'import shutil; shutil.rmtree(p)', 'pathlib.Path(p).unlink()'] as $code) {
            list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'execute_code', 'tool_input' => ['code' => $code]]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'approve', "execute_code file delete not asked: $code");
        }
        foreach (['cat ~/pikkumies/local/secrets/qbittorrent', 'ls pikkumies/local/secrets/', 'rclone config show', 'rclone config dump'] as $cmd) {
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === 'block', "stored passwords readable: $cmd");
        }
        foreach (['rclone ls remote:', 'rclone copy ~/a remote:a', 'restic snapshots', 'borg list ~/repo', 'curl http://127.0.0.1:8080/api/v2/torrents/info',
            'php $Q remove abc', 'php $Q list --search firmware', 'grep -r term ~/notes', 'bash ~/install-media-stack.sh --stop-app=sonarr && bash ~/install-media-stack.sh --start-app=sonarr',
            'ls -la ~/data', 'git status', 'borgmatic list', 'rclone serve http ~/data --addr 127.0.0.1:8080', 'rclone rc core/stats', 'restic snapshots --json',
            'rclone config file', 'rclone listremotes --long', 'curl http://127.0.0.1:8096/Items/Latest?userId=u',
            "curl -X POST 'http://127.0.0.1:8096/Items/abc/Refresh?metadataRefreshMode=Default'"] as $cmd) {
            $out = $ask($cmd);
            check($out === null, "read-only command flagged: $cmd");
        }
    },
    // guard-destructive checks the exact message it is about to send against HookUnits::APPROVE_SHOWN (ADR-0020
    // 2026-10-19 amendment), not a longer per-site literal: a merge-time-only check would miss the common case of
    // one unit answering alone, which the orchestrator passes through unchecked (HookAnswers::mergePreToolCall).
    'guard hook: an approve message is asked right up to HookUnits::APPROVE_SHOWN characters, one more is blocked, counted in characters not bytes' => function () use ($guardUnit) {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $ask = function ($cmd) use ($guardUnit) {
            list(, $out) = runPhp($guardUnit, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
            return json_decode($out, true);
        };
        $tail = ' rclone purge remote:'; // keeps the rclone-delete rule matching however much filler precedes it
        $fixed = strlen('Pikkumies: rclone can delete or overwrite files, which cannot be undone. Command []: ') + 16 + strlen($tail);
        foreach ([HookUnits::APPROVE_SHOWN => 'approve', HookUnits::APPROVE_SHOWN + 1 => 'block'] as $total => $want) {
            $cmd = str_repeat('a', $total - $fixed) . $tail;
            $d = $ask($cmd);
            check(is_array($d) && $d['action'] === $want, "$total characters: expected $want: " . json_encode($d));
            if ($want === 'approve') {
                check(strlen($d['message']) === $total, 'length arithmetic off: ' . strlen($d['message']));
            }
        }
        // Three-byte UTF-8 characters must be counted as one character each, not three: a command that only fits
        // under the cap when measured in bytes must still be refused, and one that fits in characters must be asked.
        $multi = str_repeat("\xE2\x82\xAC", HookUnits::APPROVE_SHOWN - $fixed) . $tail; // euro sign, 3 bytes, 1 character
        $d = $ask($multi);
        check(is_array($d) && $d['action'] === 'approve', 'a multibyte command at the character limit was blocked: ' . json_encode($d));
        $d = $ask(str_repeat("\xE2\x82\xAC", HookUnits::APPROVE_SHOWN - $fixed + 1) . $tail);
        check(is_array($d) && $d['action'] === 'block', 'a multibyte command one character over the limit was approved: ' . json_encode($d));
    },
    'guard hook ignores harmless and non-terminal calls' => function () use ($guardOrchestrator) {
        $hook = $guardOrchestrator;
        list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls -la']]));
        check(trim($out) === '', 'harmless command flagged');
        list(, $out) = runPhp($hook, ['pre_tool_call'], json_encode(['tool_name' => 'read_file', 'tool_input' => ['path' => 'x']]));
        check(trim($out) === '', 'non-terminal flagged');
    },
    'memory unit: save needs links or a reason, never overwrites; append adds; search finds it in two steps' => function () {
        $tmp = sys_get_temp_dir() . '/pk-mem-' . getmypid();
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/memory/pikkumies', 'config'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        mkdir("$tmp/pikkumies/memory");
        chmod("$tmp/pikkumies/memory", 0755); // as git clone leaves this tracked directory
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/pikkumies/config/documentationSources.md", "# none\n");
        $tool = "$tmp/pikkumies/tools/memory/pikkumies/pikkumies.php";
        $last = function ($out) {
            $l = cliLines($out);
            return $l ? $l[count($l) - 1] : null;
        };
        file_put_contents("$tmp/a.md", "rtorrent stopped because the disk was full; freed space and restarted it.");
        list($code, $out) = runPhp($tool, ['save', '--topic', 'rtorrent', '--title', 'rtorrent stops on a full disk', '--sources', 'df -h', '--body-file', "$tmp/a.md"]);
        check($code === 1 && strpos($last($out)['error'], 'link') !== false, 'a lesson without links or a reason was saved: ' . $out);
        list($code, $out) = runPhp($tool, ['save', '--topic', 'rtorrent', '--title', 'rtorrent stops on a full disk', '--sources', 'df -h', '--body-file', "$tmp/a.md", '--no-related', 'first lesson on this machine']);
        $first = cliLines($out)[0]['path'];
        check($code === 0 && is_file($first) && strpos($first, '/memory/lessons/rtorrent/') !== false, 'save failed: ' . $out);
        check(strpos(file_get_contents($first), "sources: [\"df -h\"]") !== false, 'sources not in frontmatter');
        check((fileperms("$tmp/pikkumies/memory") & 0777) === 0700, 'memory directory not 0700');
        list($code) = runPhp($tool, ['save', '--topic', 'rtorrent', '--title', 'rtorrent stops on a full disk', '--sources', 'x', '--body-file', "$tmp/a.md", '--no-related', 'x']);
        check($code === 1, 'same lesson saved twice the same day (overwrite)');
        file_put_contents("$tmp/b.md", "Quota hit stops rtorrent too, like [the full disk case](" . $first . ").");
        list($code, $out) = runPhp($tool, ['save', '--topic', 'rtorrent', '--title', 'rtorrent stops at the quota', '--sources', 'quota -s', '--body-file', "$tmp/b.md"]);
        check($code === 0 && cliLines($out)[0]['links'] === [realpath($first)], 'link not recognised: ' . $out);
        file_put_contents("$tmp/c.md", "Also check ~/.rtorrent.socket.");
        list($code, $out) = runPhp($tool, ['append', '--path', $first, '--body-file', "$tmp/c.md"]);
        check($code === 0 && strpos(file_get_contents($first), "## Update ") !== false && strpos(file_get_contents($first), 'freed space') !== false, 'append failed or rewrote: ' . $out);
        list($code) = runPhp($tool, ['append', '--path', "$src/lib/Cli.php", '--body-file', "$tmp/c.md"]);
        check($code === 1, 'append outside memory allowed');
        file_put_contents("$tmp/pikkumies/memory/README.md", "# memory\n");
        list($code) = runPhp($tool, ['append', '--path', "$tmp/pikkumies/memory/README.md", '--body-file', "$tmp/c.md"]);
        check($code === 1, 'append to the tracked README allowed (it would stop updates)');
        file_put_contents("$tmp/d.md", "See [the readme]($tmp/pikkumies/memory/README.md).");
        list($code) = runPhp($tool, ['save', '--topic', 'x', '--title', 'readme link only', '--sources', 'y', '--body-file', "$tmp/d.md"]);
        check($code === 1, 'a link to README.md must not count as a related lesson');
        file_put_contents("$tmp/e.md", "Survey of client defaults, see [the full disk case](" . $first . ").");
        list($code, $out) = runPhp($tool, ['save', '--kind', 'research', '--topic', 'clients', '--title', 'client defaults survey', '--sources', 'docs', '--body-file', "$tmp/e.md"]);
        check($code === 0 && strpos(cliLines($out)[0]['path'], '/memory/research/') !== false, 'research tier not writable: ' . $out);
        list($code, $out) = runPhp($tool, ['search', 'client', 'defaults', 'survey', '--offline']);
        check(strpos($out, '"trust":"research"') !== false, 'research note not found with its trust label: ' . $out);
        list($code) = runPhp($tool, ['save', '--kind', 'secret', '--topic', 'x', '--title', 'y', '--sources', 'z', '--body-file', "$tmp/e.md"]);
        check($code === 1, 'an unknown --kind was accepted');
        list($code, $out) = runPhp($tool, ['search', 'rtorrent', 'disk', 'full', '--offline']);
        $l = cliLines($out);
        check($code === 0 && $l[0]['path'] === realpath($first) && $l[0]['trust'] === 'lessons' && strpos($l[0]['excerpt'], 'disk was full') !== false && $last($out)['matched'] === 'keywords', 'search step 1: ' . $out);
        list(, $out) = runPhp($tool, ['search', 'rtorrent', 'zebra', 'quantum', '--offline']);
        check($last($out)['matched'] === 'any words' && count(cliLines($out)) > 1, 'search step 2 did not retry with any of the words: ' . $out);
        list(, $out) = runPhp($tool, ['search', 'nginx', 'certificate', '--offline']);
        check(isset($last($out)['next']) && count(cliLines($out)) === 1, 'no hit must name the next step: ' . $out);
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
    'cron-tick crontab: one tagged line, idempotent, removed by cron.disabled, never installed on root+no-setpriv' => function () {
        $tmp = sys_get_temp_dir() . '/pk-tick-cron-' . getmypid();
        @mkdir($tmp, 0700, true);
        file_put_contents("$tmp/crontab", "#!/bin/sh\nf=\"$tmp/tab\"\nif [ \"\$1\" = -l ]; then\n  [ -f \"\$f\" ] && exec cat \"\$f\"\n  echo 'no crontab for u' >&2; exit 1\nfi\ncp \"\$1\" \"\$f\"\n");
        chmod("$tmp/crontab", 0700);
        Update::$crontab = "$tmp/crontab";
        $on = Config::defaults();
        CronTick::$setprivPresentOverride = true;
        $msg = Update::scheduleCronTick($on, false);
        check(strpos($msg, 'every minute') === 0, 'first schedule: ' . $msg);
        $tab = file_get_contents("$tmp/tab");
        check(substr_count($tab, Update::CRON_TICK_TAG) === 1 && strpos($tab, '* * * * * ') === 0 && strpos($tab, 'cron-tick') !== false, 'line: ' . $tab);
        // Idempotent: scheduling again must not duplicate the line.
        Update::scheduleCronTick($on, false);
        check(substr_count(file_get_contents("$tmp/tab"), Update::CRON_TICK_TAG) === 1, 'second schedule duplicated the line');
        // cron.disabled removes it.
        $off = Config::merge($on, ['cron' => ['disabled' => true]]);
        $offMsg = Update::scheduleCronTick($off, false);
        check(strpos($offMsg, 'off') === 0, 'off message: ' . $offMsg);
        check(strpos(file_get_contents("$tmp/tab"), Update::CRON_TICK_TAG) === false, 'cron.disabled did not remove the line');
        // Root available + no setpriv: the line is never installed at all (it would only ever refuse); logged once instead.
        CronTick::$setprivPresentOverride = false;
        $log = "$tmp/update.log";
        $refuseMsg = Update::scheduleCronTick($on, true, $log);
        check(strpos($refuseMsg, 'not installed') === 0, 'root+no-setpriv must refuse to install: ' . $refuseMsg);
        check(strpos(file_get_contents("$tmp/tab"), Update::CRON_TICK_TAG) === false, 'refused case must not install the line');
        check(strpos(file_get_contents($log), 'refused') !== false, 'refusal must be logged: ' . file_get_contents($log));
        $before = file_get_contents($log);
        Update::scheduleCronTick($on, true, $log);
        check(file_get_contents($log) === $before, 'a second refusal must not log a second line');
        CronTick::$setprivPresentOverride = null;
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
