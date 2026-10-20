<?php
/**
 * ticket bundle (ADR-0100), the attachments: a log outside HOME, under a dot directory or behind a symlink is never
 * read, the process list and the system-wide reads follow Detect::rootAccess (root directly, sudo with sudo -n, none
 * only this account), every attachment stays under 1 MiB, and the search log comes from the export's tool calls.
 */
foreach (['TicketNeeds', 'TicketSearches', 'TicketCollect', 'TicketBundle', 'TicketDraft', 'TicketAuto'] as $c) {
    require_once dirname(__DIR__) . "/pikkumies/tools/system/ticket/$c.php";
}

if (!function_exists('bundleRunRecorder')) {
    /** A process runner that records each argv into $calls and answers from $answers (first argv word => [code, out]). */
    function bundleRunRecorder(array &$calls, array $answers = [])
    {
        return function (array $argv) use (&$calls, $answers) {
            $calls[] = $argv;
            $key = array_values(array_intersect($argv, ['ps', 'tail', 'dd', 'journalctl']));
            $key = $key ? $key[0] : $argv[0];
            $a = isset($answers[$key]) ? $answers[$key] : [0, ''];
            return ['code' => $a[0], 'out' => $a[1], 'err' => '', 'stopped' => false, 'cut' => false];
        };
    }

    /** A session export in Hermes' Markdown shape: assistant turns with "## Tool calls" JSON, then "### Tool — name" results. */
    function bundleExportFixture(array $turns)
    {
        $md = "---\nsession_id: \"s-1\"\ntitle: \"Disk\"\n---\n# Disk\n\n## Messages\n\n### User — 2026-10-20T08:00:00+00:00\nrtorrent is down\n";
        foreach ($turns as $t) {
            $calls = [];
            foreach ($t as $i => $c) {
                $calls[] = ['id' => "c$i", 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode($c[1])]];
            }
            $md .= "\n### Assistant — 2026-10-20T08:01:00+00:00\nLooking.\n\n\n## Tool calls\n\n```json\n" . json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n";
            foreach ($t as $c) {
                $md .= "\n### Tool — {$c[0]} — 2026-10-20T08:01:05+00:00\n" . (isset($c[2]) ? $c[2] : 'ok') . "\n";
            }
        }
        return $md;
    }
}

return [
    'ticket bundle: privilege follows Detect::rootAccess, and each branch reads processes its own way (root directly, sudo with sudo -n, none this account only)' => function () {
        check(TicketCollect::privilege(['available' => true, 'via' => 'uid0']) === 'root', 'uid0');
        check(TicketCollect::privilege(['available' => true, 'via' => 'sudo']) === 'sudo', 'sudo');
        check(TicketCollect::privilege(['available' => false, 'via' => 'none']) === 'none' && TicketCollect::privilege([]) === 'none', 'none');
        $ps = [0, "  PID  PPID USER %CPU %MEM RSS ELAPSED STAT COMMAND\n    1     0 root 0.0 0.1 100 1-00:00:00 Ss init\n"];
        foreach (['root' => ['ps', '-e', false], 'sudo' => ['sudo', '-e', true], 'none' => ['ps', '-u', false]] as $priv => $want) {
            $calls = [];
            $r = TicketCollect::processes($priv, 4242, bundleRunRecorder($calls, ['ps' => $ps]));
            check(count($calls) === 1 && $calls[0][0] === $want[0] && in_array($want[1], $calls[0], true), "$priv: " . json_encode($calls));
            check(in_array('sudo', $calls[0], true) === $want[2], "$priv must " . ($want[2] ? '' : 'not ') . 'use sudo: ' . json_encode($calls[0]));
            check($priv !== 'none' || (in_array('4242', $calls[0], true) && !in_array('-e', $calls[0], true)), 'none must list only its own uid: ' . json_encode($calls[0]));
            check($r[0] === ($priv === 'none' ? 'this account only' : 'all users') && $r[2] === 1, json_encode($r));
        }
        // /var/log and the system journal: refused without privilege, sudo -n tail with sudo, read directly as root.
        foreach (['none', 'sudo', 'root'] as $priv) {
            $calls = [];
            $got = TicketCollect::readLog('/var/log/pk-tkbundle-none.log', '/nonexistent', 1, $priv, bundleRunRecorder($calls, ['tail' => [0, "line\n"]]));
            if ($priv === 'none') {
                check(strpos($got, 'needs root or passwordless sudo') !== false && !$calls, "none: $got " . json_encode($calls));
            } elseif ($priv === 'sudo') {
                // A file that is not there (or not a regular file) is refused before sudo runs: no planted FIFO or link is read.
                check(!$calls && strpos($got, 'not a regular file') !== false, "sudo, missing file: $got " . json_encode($calls));
                $real = array_values(array_filter((array) glob('/var/log/*'), function ($f) {
                    return preg_match(TicketCollect::VARLOG_RE, $f) && is_file($f) && !is_link($f);
                }));
                if ($real) {
                    $got = TicketCollect::readLog($real[0], '/nonexistent', 1, 'sudo', bundleRunRecorder($calls, ['dd' => [0, "line\n"]]));
                    $skip = max(0, filesize($real[0]) - TicketCollect::LOG_TAIL);
                    check($calls === [['timeout', '10', 'sudo', '-n', 'dd', 'iflag=nofollow,skip_bytes,nonblock', 'if=' . $real[0], 'bs=65536', 'count=1', "skip=$skip", 'status=none']]
                        && $got === ($skip > 0 ? '' : "line\n"), "sudo: $got " . json_encode($calls));
                }
            } else {
                check(!$calls && strpos($got, 'not a regular file') !== false, "root reads directly, never through sudo: $got " . json_encode($calls));
            }
            check(TicketCollect::systemWide($priv, ['journalctl']) === ['root' => ['journalctl'], 'sudo' => ['sudo', '-n', 'journalctl'], 'none' => null][$priv], "journal argv for $priv");
        }
    },
    'ticket bundle: a log is read only under HOME, through no final symlink, as a regular file this account owns' => function () {
        $root = realpath(testTempDir('pk-tkbundle-logs'));
        mkdir("$root/homedir/logs", 0700, true);
        mkdir("$root/outside", 0700);
        file_put_contents("$root/homedir/logs/app.log", "old\n" . str_repeat("filler line\n", 10000) . "the newest line\n");
        file_put_contents("$root/outside/secret.log", "not yours\n");
        symlink("$root/outside/secret.log", "$root/homedir/logs/escape.log");
        symlink("$root/homedir/logs/app.log", "$root/homedir/logs/inside-link.log");
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $calls = [];
        $run = bundleRunRecorder($calls);
        $home = "$root/homedir";
        $tail = TicketCollect::readLog("$home/logs/app.log", $home, $uid, 'none', $run);
        check(substr($tail, -16) === "the newest line\n" && strlen($tail) <= TicketCollect::LOG_TAIL && strpos($tail, 'old') !== 0, 'the newest part of an own log was not read');
        foreach (["$root/outside/secret.log", "$home/../outside/secret.log", "$home/logs/escape.log", "$home/logs/inside-link.log", '/etc/passwd', "$home/logs"] as $bad) {
            $got = TicketCollect::readLog($bad, $home, $uid, 'none', $run);
            check(strpos($got, 'not read') === 0, "read $bad: " . substr($got, 0, 60));
        }
        check(strpos(TicketCollect::readLog("$home/logs/app.log", $home, $uid + 1, 'none', $run), 'not read') === 0, 'a file another uid owns was read');
        check(!$calls, 'reading a log under HOME ran a program: ' . json_encode($calls));
        testRmTree($root);
    },
    'ticket bundle: the bundle files go through the ticket\'s one redaction path (TicketDraft::attachment), and a line it cannot check refuses the draft' => function () {
        $key = 'vsk-' . 'Or4cleSupportKey0123456789';
        $facts = ['Hostname' => 'box.example.org'];
        $dir = testTempDir('pk-tkbundle-redact');
        $files = [TicketBundle::NAMES[0] => TicketBundle::systemFile($facts, ['this account only', "PID ARGS\n1 curl -H 'Authorization: Bearer Or4cle01' x", 1], [['app.log', "token = Or4cle02\n$key\n"]], 1792300000),
            TicketBundle::NAMES[1] => TicketBundle::diagnosisFile("## Problem\npassword: Or4cle03\n", "1. checked $key", "- memory: 1\n", 1792300000),
            TicketBundle::NAMES[2] => TicketBundle::transcriptFile("### User\nhere is my key $key\n", '', 1792300000)[0]];
        check(strpos($files[TicketBundle::NAMES[0]], 'Untrusted data') !== false && strpos($files[TicketBundle::NAMES[2]], 'Untrusted data') !== false, 'machine-collected attachments must say they are untrusted');
        $paths = [];
        foreach ($files as $name => $text) {
            file_put_contents("$dir/$name", $text);
            $paths[] = "$dir/$name";
        }
        $rec = TicketDraft::create('Pikkumies: x', 'msg', 'jane@example.com', 'Medium', '', $paths, 1792300000, "$dir/state", 'agent', ['process_scope' => 'this account only']);
        list(, $p) = TicketDraft::load($rec['id'], "$dir/state");
        check($p['by'] === 'agent' && $p['bundle'] === ['process_scope' => 'this account only'], json_encode([$p['by'], $p['bundle']]));
        foreach ($p['attachments'] as $i => $a) {
            $text = base64_decode($a['data']);
            check($a['name'] === TicketBundle::NAMES[$i] && !preg_match('/Or4cle/', $text) && strpos($text, 'REDACTED') !== false, "attachment $i kept a secret: " . substr($text, 0, 400));
        }
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $threw = '';
            try {
                TicketDraft::attachment($paths[0]); // the one path every attached text file takes
            } catch (CliError $e) {
                $threw = $e->getMessage();
            }
            check(strpos($threw, 'too long or too complex') !== false, 'a regex engine failure in redaction did not refuse: ' . $threw);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            testRmTree($dir);
        }
    },
    'ticket bundle: each attachment stays under 1 MiB: logs that do not fit are named, the transcript keeps its newest part, ps at most 300 rows' => function () {
        $logs = [];
        for ($i = 0; $i < 40; $i++) {
            $logs[] = ["log$i", str_repeat("line $i of a long log\n", 3000)];
        }
        $f = TicketBundle::systemFile(['Hostname' => 'h'], ['all users', 'ps', 1], $logs, 1792300000);
        check(strlen($f) <= TicketBundle::FILE_MAX && strpos($f, 'Left out to stay under 1 MiB') !== false && strpos($f, 'log39') !== false, 'system file: ' . strlen($f));
        list($t, $older) = TicketBundle::transcriptFile(str_repeat("old turn\n", 400000) . "the newest turn\n", '', 1792300000);
        check($older === true && strlen($t) <= TicketBundle::FILE_MAX && substr($t, -16) === "the newest turn\n" && strpos($t, 'older part') !== false, 'transcript: ' . strlen($t));
        list($t, $older) = TicketBundle::transcriptFile(null, 'Hermes is not installed here', 1792300000);
        check($older === false && strpos($t, 'No transcript: Hermes is not installed here') !== false, $t);
        $calls = [];
        $ps = "PID ARGS\n" . implode("\n", array_map(function ($i) {
            return "$i proc$i";
        }, range(1, 500))) . "\n";
        $r = TicketCollect::processes('none', 1000, bundleRunRecorder($calls, ['ps' => [0, $ps]]));
        check($r[2] === 500 && substr_count($r[1], "\n") === 301 && strpos($r[1], '200 more rows left out') !== false && strpos($r[1], 'proc301') === false, 'ps rows: ' . $r[2]);
    },
    'ticket bundle: the search log is read from the export\'s tool calls with venue, keywords and matches, and the venues gate names what is missing' => function () {
        $md = bundleExportFixture([
            [['terminal', ['command' => "P=~/pikkumies/pikkumies/tools/memory/pikkumies/pikkumies.php\nphp \$P search \"rtorrent disk full\""], '{"tier":1,"title":"rtorrent stops on a full disk"}'],
                ['terminal', ['command' => 'ls -la ~'], 'total 0']],
            [['terminal', ['command' => 'W=~/pikkumies/pikkumies/tools/documentation/wiki/wiki.php' . "\n" . 'php $W search "rtorrent"'], '{"title":"rTorrent - Wiki"}'],
                ['read_file', ['path' => '/home/fixtureuser/pikkumies/pikkumies/local/docs-cache/pm-kb/quota.md']]],
            [['web_search', ['query' => 'rtorrent session lock stale'], '{"results":[{"title":"Stale lock fix","url":"https://x.example"}]}']],
        ]);
        $s = TicketSearches::fromExport($md);
        $c = TicketSearches::counts($s);
        check($c === ['memory' => 1, 'docs' => 2, 'web' => 1, 'upstream' => 0], 'counts: ' . json_encode($c) . ' ' . json_encode($s));
        check($s[0]['keywords'] === 'rtorrent disk full' && $s[0]['matches'] === ['rtorrent stops on a full disk'], json_encode($s[0]));
        check($s[1]['how'] === 'wiki' && $s[1]['matches'] === ['rTorrent - Wiki'], 'the wiki search through $W was not read as a wiki search: ' . json_encode($s[1]));
        check($s[3]['keywords'] === 'rtorrent session lock stale' && $s[3]['matches'] === ['Stale lock fix'], json_encode($s[3]));
        $log = TicketSearches::markdown($s, 'the transcript');
        check(strpos($log, '[memory]') !== false && strpos($log, '"rtorrent disk full" -> rtorrent stops on a full disk') !== false, $log);
        TicketSearches::check($s, TicketSearches::required(true, false));
        $only = TicketSearches::fromExport(bundleExportFixture([[['terminal', ['command' => 'pikkumies search "disk"']]]]));
        foreach ([[false, false, 'no docs'], [true, false, 'no docs, no web'], [false, true, 'no docs, no upstream']] as $case) {
            $err = '';
            try {
                TicketSearches::check($only, TicketSearches::required($case[0], $case[1]));
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, $case[2] . ' search') !== false, 'venues gate: ' . json_encode($case) . ' -> ' . $err);
        }
    },
    'ticket bundle: a named log under a dot directory or in Pikkumies\' own state is refused; the host family\'s own ~/.lighttpd logs and local/logs are read; as root a hard-linked file is refused' => function () {
        $root = realpath(testTempDir('pk-tkbundle-dot'));
        $home = "$root/homedir";
        foreach (['.config/wg', '.lighttpd', 'pkdir/local/state', 'pkdir/local/logs'] as $d) {
            mkdir("$home/$d", 0700, true);
        }
        $files = ['.config/wg/wg0.conf' => "PrivateKey = x\n", '.pgpass' => "h:5432:db:u:pw\n", '.lighttpd/error.log' => "lighttpd error\n", 'app.log' => "app line\n",
            'pkdir/local/state/x.json' => "{}\n", 'pkdir/local/logs/a.log' => "a log line\n", 'pkdir/local/update.log' => "updated\n"];
        foreach ($files as $f => $text) {
            file_put_contents("$home/$f", $text);
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $calls = [];
        $run = bundleRunRecorder($calls);
        $local = "$home/pkdir/local";
        foreach (['.config/wg/wg0.conf', '.pgpass', '.lighttpd/error.log'] as $f) {
            $got = TicketCollect::readLog("$home/$f", $home, $uid, 'none', $run, $local);
            check(strpos($got, 'dot directory') !== false, "a named $f was read: $got");
        }
        check(TicketCollect::readLog("$home/.lighttpd/error.log", $home, $uid, 'none', $run, $local, true) === "lighttpd error\n", 'a listed host log was refused');
        check(strpos(TicketCollect::readLog("$local/state/x.json", $home, $uid, 'none', $run, $local), 'own state') !== false, 'Pikkumies state was read');
        check(TicketCollect::readLog("$local/logs/a.log", $home, $uid, 'none', $run, $local) === "a log line\n" && TicketCollect::readLog("$local/update.log", $home, $uid, 'none', $run, $local) === "updated\n", 'local logs refused');
        $logs = TicketCollect::logs(["$home/.pgpass"], $home, $uid, 'none', ['family' => 'pmss', 'stack' => 'none'], $local, $run);
        $byLabel = array_column($logs, 1, 0);
        check($byLabel["$home/.lighttpd/error.log"] === "lighttpd error\n" && strpos($byLabel["$home/.pgpass"], 'dot directory') !== false, json_encode($byLabel));
        link("$home/app.log", "$home/app2.log");
        check(strpos(TicketCollect::readLog("$home/app.log", $home, 0, 'none', $run, $local), 'hard link') !== false, 'as root, a hard-linked file was read');
        check(TicketCollect::readLog("$home/app.log", $home, $uid, 'none', $run, $local) === "app line\n", 'as the account, a hard-linked own file is still read');
        testRmTree($root);
    },
    'ticket bundle: scrubTokens removes long key-like runs with letters and digits and keeps words, short tokens and long letter runs; the diagnosis is framed as untrusted text' => function () {
        $key = 'yAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk=';
        $out = TicketBundle::scrubTokens("wg peer $key ok\nabcdefghijklmnopqrstuvwxyzabcdefghij short9 deadbeef\n");
        check(strpos($out, $key) === false && strpos($out, '[long token removed]') !== false, $out);
        check(strpos($out, 'abcdefghijklmnopqrstuvwxyzabcdefghij short9 deadbeef') !== false, 'words or short tokens were scrubbed: ' . $out);
        check(TicketBundle::scrubTokens($out) === $out, 'scrubbing twice changed the text');
        $d = TicketBundle::diagnosisFile("## Problem\nrtorrent is down\n", null, '', 1792300000);
        check(strpos($d, TicketBundle::AGENT_TEXT) !== false && strpos($d, TicketBundle::AGENT_TEXT) < strpos($d, 'rtorrent is down'), 'the diagnosis is not framed as untrusted: ' . substr($d, 0, 300));
        $long = str_repeat('x', TicketBundle::LINE_MAX + 50);
        check(TicketBundle::cutLines(TicketBundle::cutLines($long)) === TicketBundle::cutLines($long) && strlen(TicketBundle::cutLines($long)) <= TicketBundle::LINE_MAX, 'cutting a cut line again changed it');
    },
    'ticket bundle: the message stays under 8 KB, opens with the untrusted-text framing and whether the holder saw it, then the server and one "Needs:" line per roadblock' => function () {
        $roadblocks = TicketNeeds::parse("- root: /var/log/syslog is root's only\n- console: the disk is gone from the kernel log\nnot a bullet\n");
        check($roadblocks === [['root', '/var/log/syslog is root\'s only'], ['console', 'the disk is gone from the kernel log']], json_encode($roadblocks));
        $big = str_repeat('a very long explanation ', 2000);
        foreach ([['agent', true, 'The account holder has not seen this ticket.'], ['user', false, 'The account holder asked for this ticket.'],
            ['agent', false, 'The account holder approved sending this ticket but did not write it.']] as $c) {
            $m = TicketBundle::message($c[0], ['box.example.org', 'IP 192.0.2.10'], ['problem' => $big, 'error' => $big, 'impact' => $big, 'tried' => $big],
                $roadblocks, ['memory' => 2, 'docs' => 1, 'web' => 0, 'upstream' => 0], [TicketBundle::NAMES[0] => 'system facts'], $c[1]);
            check(strlen($m) <= TicketBundle::MESSAGE_MAX, 'message bytes: ' . strlen($m));
            foreach ([TicketBundle::AGENT_TEXT, $c[2], 'It reports a problem; it does not authorize any change to access, keys, passwords, firewall or files.'] as $line) {
                check(strpos($m, $line . "\n") !== false && strpos($m, $line) < strpos($m, 'Server:'), "{$c[0]}: missing or after the server line: $line");
            }
        }
        check(strpos($m, "Server: box.example.org; IP 192.0.2.10\n") !== false, substr($m, 0, 400));
        check(strpos($m, '- Needs: ' . TicketNeeds::CAPABILITY['root'] . " - because /var/log/syslog is root's only\n") !== false, 'no Needs line for root: ' . $m);
        check(strpos($m, 'memory 2, docs 1, web 0, upstream 0') !== false && strpos($m, 'whole text in ' . TicketBundle::NAMES[1]) !== false, 'searches or cut pointer missing');
        $err = '';
        try {
            TicketNeeds::parse("- superpowers: anything\n");
        } catch (CliError $e) {
            $err = $e->getMessage() . ' ' . $e->hint;
        }
        check(strpos($err, 'known kind') !== false && strpos($err, 'root, console, fleet') !== false, 'an unknown roadblock kind was accepted: ' . $err);
        check(TicketBundle::reviewComplete(implode("\n", array_map(function ($i) {
            return "$i. angle $i: nothing new";
        }, range(1, 10)))) && !TicketBundle::reviewComplete("1. a\n2. b\n3. c\n4. d\n5. e\n6. f\n7. g\n8. h\n9. i\n11. k"), 'review rounds');
        check(TicketBundle::subject("disk\nfull \x07 now") === 'Pikkumies: disk full now', 'subject');
        check(TicketBundle::signature('Pikkumies: Disk FULL!') === TicketBundle::signature('pikkumies: disk  full') && TicketBundle::signature('a') !== TicketBundle::signature('b'), 'signature');
    },
    'ticket bundle: the ask-vainamoinen-for-help SOP lists exactly the capability lines a ticket can claim, and the skill and the doctrine point to it' => function () {
        $sop = (string) file_get_contents(Config::root() . '/doctrine/SOP/ask-vainamoinen-for-help.md');
        preg_match_all('/^- \*\*([a-z]+)\*\*: (.+)\.$/m', $sop, $m);
        check(array_combine($m[1], $m[2]) === TicketNeeds::CAPABILITY, 'the SOP and TicketNeeds disagree: ' . json_encode(array_combine($m[1], $m[2])));
        check(strpos((string) file_get_contents(Config::root() . '/tools/system/ticket/skill.md'), 'doctrine/SOP/ask-vainamoinen-for-help.md') !== false, 'the ticket skill must point to the SOP');
        check(strpos((string) file_get_contents(Config::root() . '/doctrine/doctrine.md'), 'follow SOP/ask-vainamoinen-for-help') !== false, 'the Search first rule must name the SOP');
    },
    'ticket bundle: checkFiles refuses a bundle draft written by hand (other names, unredacted text, an unscrubbed token, an oversized file, no bundle) and passes one as bundle makes it' => function () {
        $att = function (array $texts) {
            $out = [];
            foreach ($texts as $name => $t) {
                $out[] = ['name' => $name, 'data' => base64_encode($t)];
            }
            return $out;
        };
        $n = TicketBundle::NAMES;
        $good = [$n[0] => "facts\n", $n[1] => "diagnosis\n", $n[2] => "talk\n"];
        $p = ['message' => "hello\n", 'attachments' => $att($good), 'bundle' => ['scrubbed' => true]];
        TicketAuto::checkFiles($p);
        $wg = 'PrivateKey = yAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk=';
        $cases = [
            'file names' => ['attachments' => $att(['secret.txt' => "x\n", 'a.txt' => "y\n", 'b.txt' => "z\n"])],
            'unredacted' => ['attachments' => $att(array_merge($good, [$n[1] => "$wg\n"]))],
            'unscrubbed' => ['attachments' => $att(array_merge($good, [$n[0] => 'token ' . str_repeat('a1', 20) . "\n"]))],
            'oversized' => ['attachments' => $att(array_merge($good, [$n[2] => str_repeat("x\n", TicketBundle::FILE_MAX)]))],
            'long message' => ['message' => str_repeat("m\n", TicketBundle::MESSAGE_MAX)],
            'no bundle' => ['bundle' => null],
        ];
        foreach ($cases as $what => $change) {
            $err = '';
            try {
                TicketAuto::checkFiles(array_merge($p, $change));
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, 'not made by bundle') !== false, "$what was accepted: $err");
        }
        // On the automatic path the scrub check runs whatever the record says: a bundle that says it was not scrubbed is refused.
        $err = '';
        try {
            TicketAuto::checkFiles(array_merge($p, $cases['unscrubbed'], ['bundle' => ['scrubbed' => false]]), true);
        } catch (CliError $e) {
            $err = $e->getMessage();
        }
        check(strpos($err, 'automatic-ticket pass') !== false, 'an unscrubbed bundle passed the automatic check because its record said scrubbed:false: ' . $err);
        TicketAuto::checkFiles(array_merge($p, $cases['unscrubbed'], ['bundle' => ['scrubbed' => false]])); // asked path: the user reads it
    },
    'ticket bundle: the caps count automatic attempts that were sent or can be tried again (201, no answer, 429, 5xx), not final refusals, and refuse a repeated subject' => function () {
        $at = function ($status, $sig = 's') {
            return ['ts' => 1, 'status' => $status, 'signature' => $sig];
        };
        foreach ([[[201, 0], true], [[429, 503], true], [[401, 400], false], [[201], false], [[-1, -1], false]] as $c) {
            $err = '';
            try {
                TicketAuto::checkCaps(array_map(function ($s) use ($at) {
                    return $at($s, "sig$s");
                }, $c[0]), 'new');
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            check((strpos($err, '2 tickets by itself') !== false) === $c[1], json_encode($c) . ' -> ' . $err);
        }
        $err = '';
        try {
            TicketAuto::checkCaps([$at(201, 'same')], 'same');
        } catch (CliError $e) {
            $err = $e->getMessage();
        }
        check(strpos($err, 'with this subject') !== false, 'a repeated subject went out: ' . $err);
    },
    'ticket bundle: an automatic attempt counts once by its attempt id, a -2 line with nothing after it counts (a crash mid-send), a -1 does not; the over-cap hint says how to send now' => function () {
        $l = function ($attempt, $status) {
            return ['attempt' => $attempt, 'ts' => 1, 'status' => $status, 'signature' => "s$attempt"];
        };
        foreach ([[[$l('a', -2), $l('a', 503), $l('b', -2), $l('b', 201)], true], [[$l('a', -2), $l('a', 503)], false], [[$l('a', -2), $l('b', -2)], true],
            [[$l('a', -2), $l('a', -1), $l('b', -2), $l('b', -1)], false], [[$l('a', -2), $l('a', 401), $l('b', -2)], false]] as $c) {
            $err = '';
            $hint = '';
            try {
                TicketAuto::checkCaps($c[0], 'new');
            } catch (CliError $e) {
                $err = $e->getMessage();
                $hint = $e->hint;
            }
            check((strpos($err, '2 tickets by itself') !== false) === $c[1], json_encode($c) . ' -> ' . $err);
        }
        $err = '';
        try {
            TicketAuto::checkCaps([$l('a', -2), $l('b', -2)], 'new');
        } catch (CliError $e) {
            $err = $e->hint;
        }
        check(strpos($err, 'turn ticket.auto off and send it with the user\'s yes, or wait 24 hours') !== false, 'over-cap hint: ' . $err);
    },
    'ticket bundle: as root a /var/log file is read through the open handle after its device and inode match the path' => function () {
        $real = array_values(array_filter((array) glob('/var/log/*'), function ($f) {
            return preg_match(TicketCollect::VARLOG_RE, $f) && is_file($f) && !is_link($f) && is_readable($f) && filesize($f) > 0;
        }));
        if (!$real) {
            skip('no readable regular file directly under /var/log here');
        }
        $calls = [];
        $got = TicketCollect::readLog($real[0], '/nonexistent', 0, 'root', bundleRunRecorder($calls));
        $size = filesize($real[0]);
        $want = (string) file_get_contents($real[0], false, null, max(0, $size - TicketCollect::LOG_TAIL));
        check(!$calls && $got === TicketCollect::fromLineStart($want, $size > TicketCollect::LOG_TAIL), 'root read differs or ran a program: ' . json_encode($calls));
    },
];
