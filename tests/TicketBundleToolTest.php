<?php
/**
 * ticket bundle (ADR-0100), end to end in a throwaway tree: ticket.auto unset is on on a Pulsed Media server and off
 * elsewhere (off free, on the person's elsewhere); an agent bundle needs the venues and the ten rounds, a user bundle
 * neither; the facts say which privilege applied and the scope follows it; a bundle goes out once through the ticket
 * sender; automatic sends hold to the caps (failed attempts count) and the same-subject rule; an automatic ticket is
 * scrubbed; the guard lets only an agent bundle of this account through unasked and blocks a hand-made one. Every send
 * goes to a php -S stand-in on this machine: no test reaches the real endpoint.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';
foreach (['TicketDraft', 'TicketAuto', 'TicketBundle'] as $c) {
    require_once dirname(__DIR__) . "/pikkumies/tools/system/ticket/$c.php";
}
require_once __DIR__ . '/TicketBundleTest.php';

if (!function_exists('bundleTree')) {
    /** A pikkumies tree with the ticket and feedback units and the guard; run.php sets every seam from seams.json. */
    function bundleTree(array $ticket = [], array $seams = [])
    {
        $tmp = realpath(testTempDir('pk-tkbtool'));
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'tools/system/feedback', 'tools/system/ticket', 'hooks/guard/ticket-send'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/home", 0700, true);
        @mkdir("$tmp/pikkumies/local/state/ticket", 0700, true);
        file_put_contents("$tmp/pikkumies/local/state/ticket/email", "owner@example.org\n");
        file_put_contents("$tmp/home/.virsilipasKey", "endpoint=https://example.invalid/v1\nkey=vsk-" . str_repeat('5a', 24) . "\n");
        chmod("$tmp/home/.virsilipasKey", 0600);
        $config = ['provider' => ['endpoint' => '', 'model' => '', 'keyFile' => "$tmp/home/.none", 'virsilipasKeyFile' => "$tmp/home/.virsilipasKey"]] + ($ticket ? ['ticket' => $ticket] : []);
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        $md = bundleExportFixture([[['terminal', ['command' => 'pikkumies search "rtorrent will not start"'], '{"title":"rtorrent stops on a full disk"}'],
            ['terminal', ['command' => 'php ~/pikkumies/pikkumies/tools/documentation/kb/kb.php search "quota"'], '{"title":"Disk quota"}']]]);
        file_put_contents("$tmp/seams.json", json_encode($seams + ['pmss' => true, 'root' => 'none', 'md' => $md, 'endpoint' => 'http://127.0.0.1:1/none']));
        file_put_contents("$tmp/run.php", '<?php
$s = json_decode(file_get_contents(__DIR__ . "/seams.json"), true);
foreach (["Cli", "ConfigureTiers", "Quota"] as $c) { require_once __DIR__ . "/pikkumies/lib/$c.php"; }
ConfigureTiers::$pmssOverride = $s["pmss"];
Quota::$live = function () { return null; };
$uid = function_exists("posix_geteuid") ? posix_geteuid() : getmyuid();
$ticketPmssDir = "/nonexistent-pmss";
$ticketEndpoint = $s["endpoint"];
$ticketProfile = function () use ($s, $uid) { return ["uid" => $uid, "user" => "fixtureuser", "pmss" => $s["pmss"], "family" => "generic", "stack" => "none",
    "identity" => ["hostname" => "box", "fqdn" => "box.example.org", "primaryIp" => "192.0.2.10"], "os" => ["id" => "debian", "version" => "12"], "apps" => ["rtorrent"], "detectedAt" => "2026-10-20T08:00:00+00:00"]; };
$ticketRoot = function () use ($s) { return ["available" => $s["root"] !== "none", "via" => $s["root"] === "root" ? "uid0" : $s["root"]]; };
$ticketRun = function (array $argv) { file_put_contents(__DIR__ . "/argv.jsonl", json_encode($argv) . "\n", FILE_APPEND);
    return ["code" => 0, "out" => in_array("ps", $argv, true) ? "PID USER ARGS\n1 fixtureuser rtorrent\n" : "journal line\n", "err" => "", "stopped" => false, "cut" => false]; };
$ticketTranscript = function () use ($s) { if ($s["md"] === null) { throw new CliError("no current Pikkumies session to attach", "x"); } return $s["md"]; };
$ticketMetricsDir = __DIR__ . "/metrics";
require_once __DIR__ . "/pikkumies/tools/system/ticket/TicketAuto.php";
if (isset($s["euid"])) { TicketAuto::$euidOverride = $s["euid"]; }
require __DIR__ . "/pikkumies/tools/system/ticket/ticket.php";
');
        // The guard, run through a wrapper that sets the same euid seam.
        file_put_contents("$tmp/hook.php", '<?php
$s = json_decode(file_get_contents(__DIR__ . "/seams.json"), true);
foreach (["Cli", "Config", "ConfigureTiers"] as $c) { require_once __DIR__ . "/pikkumies/lib/$c.php"; }
require_once __DIR__ . "/pikkumies/tools/system/ticket/TicketAuto.php";
if (isset($s["euid"])) { TicketAuto::$euidOverride = $s["euid"]; }
require __DIR__ . "/pikkumies/hooks/guard/ticket-send/ticket-send.php";
');
        file_put_contents("$tmp/diagnosis.md", "## Problem\nrtorrent will not start\n\n## Error\nsession lock held\n\n## Impact\nno torrents\n\n## Tried\nrestart twice\n\n## Roadblocks\n- root: /var/log/syslog is root's only\n");
        file_put_contents("$tmp/review.md", implode("\n", array_map(function ($i) {
            return "$i. angle $i: nothing new";
        }, range(1, 10))) . "\n");
        return $tmp;
    }

    /** Runs the tree's tool with HOME in the tree, after merging $seams into seams.json; returns [exit code, lines, raw]. */
    function bundleRun($tree, array $args, array $seams = [])
    {
        if ($seams) {
            file_put_contents("$tree/seams.json", json_encode($seams + json_decode((string) file_get_contents("$tree/seams.json"), true)));
        }
        list($code, $out) = testWithEnv(['HOME' => "$tree/home", 'PIKKUMIES_CONFIG' => null], function () use ($tree, $args) {
            return runPhp("$tree/run.php", $args);
        });
        return [$code, cliLines($out), $out];
    }

    /** A bundle with the tree's diagnosis (and review for an agent ticket); its id, or "FAILED: ..." with the output. */
    function bundleIn($tree, $subject, $by = 'agent', array $extra = [])
    {
        $args = array_merge(['bundle', '--subject', $subject, '--diagnosis-file', "$tree/diagnosis.md", '--by', $by], $by === 'agent' ? ['--review-file', "$tree/review.md"] : [], $extra);
        list($code, $l, $out) = bundleRun($tree, $args);
        return $code === 0 && isset($l[0]['id']) ? $l[0]['id'] : 'FAILED: ' . $out;
    }

    function bundleDraft($tree, $id)
    {
        return json_decode((string) @file_get_contents("$tree/pikkumies/local/state/ticket/$id.json"), true);
    }

    function bundleFile($tree, $id, $i)
    {
        $p = bundleDraft($tree, $id);
        return is_array($p) ? (string) base64_decode($p['attachments'][$i]['data']) : '';
    }

    /** The tree's guard on a terminal command; the decoded answer, or null for no opinion. */
    function bundleHook($tree, $command, array $env = [])
    {
        list(, $out) = testWithEnv($env + ['HOME' => "$tree/home", 'HERMES_YOLO_MODE' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_CONFIG' => null], function () use ($tree, $command) {
            return runPhp("$tree/hook.php", [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $command], 'session_id' => 's-1']));
        });
        return trim($out) === '' ? null : json_decode($out, true);
    }

    /** A php -S stand-in for the endpoint: /ok answers 201 ticket 777, /down 503; each request is one line in its calls file. */
    function bundleServer()
    {
        $dir = testTempDir('pk-tkbtool-srv');
        file_put_contents("$dir/router.php", '<?php
$mode = explode("/", parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))[1];
file_put_contents(__DIR__ . "/calls", json_encode(["mode" => $mode, "body" => file_get_contents("php://input")]) . "\n", FILE_APPEND);
if ($mode === "ok") { http_response_code(201); echo json_encode(["ticket" => "777"]); return; }
http_response_code(503); echo json_encode(["error" => "temporarily unavailable"]);');
        $port = testFreePort();
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg("$dir/router.php"), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(300000);
        return [$p, "http://127.0.0.1:$port", $dir];
    }

    function bundleCalls($dir)
    {
        return is_file("$dir/calls") ? array_map('json_decode', file("$dir/calls")) : [];
    }
}

return [
    'ticket bundle tool: ticket.auto unset is on on a Pulsed Media server and off elsewhere; off is free everywhere, on is the person\'s off PMSS' => function () {
        $file = testTempDir('pk-tkbtool-cfg') . '/config.php';
        try {
            ConfigureTiers::$pmssOverride = true;
            check(TicketAuto::on(Config::defaults()) === true, 'unset on PMSS must be on');
            check(ConfigureTiers::classify('ticket.auto', null, true) === 'free' && ConfigureTiers::classify('ticket.auto', null, false) === 'free', 'PMSS tiers');
            check(Config::set('ticket.auto', false, $file) === false && TicketAuto::on(Config::fileConfig($file)) === false, 'off on PMSS');
            check(Config::set('ticket.auto', true, $file) === true, 'back on, on PMSS, is free');
            ConfigureTiers::$pmssOverride = false;
            check(TicketAuto::on(Config::defaults()) === false, 'unset elsewhere must be off');
            check(ConfigureTiers::classify('ticket.auto', null, true) === 'person' && ConfigureTiers::classify('ticket.auto', true, false) === 'free', 'elsewhere tiers');
            check(Config::set('ticket.auto', false, $file) === false, 'off elsewhere is free');
            $err = '';
            try {
                Config::set('ticket.auto', true, $file);
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
            }
            check(strpos($err, "the person's to do") !== false && TicketAuto::on(Config::fileConfig($file)) === false, 'the agent turned automatic tickets on off PMSS: ' . $err);
            $r = ConfigureSettings::set('ticket.auto', 'off', $file, Config::fileConfig($file), dirname($file) . '/log.jsonl');
            check($r['tier'] === 'free' && $r['value'] === false, json_encode($r));
        } finally {
            ConfigureTiers::$pmssOverride = null;
            testRmTree(dirname($file));
        }
    },
    'ticket bundle tool: a draft saved before by and bundle existed still loads, as the user\'s own, and an unknown "by" is refused' => function () {
        $dir = testTempDir('pk-tkbtool-v1');
        $v1 = ['v' => 1, 'created_at' => 1792300000, 'email' => 'jane@example.com', 'subject' => 'old', 'message' => 'old draft', 'priority' => 'Medium', 'service' => '', 'attachments' => []];
        list($id) = DraftStore::save($dir, json_encode($v1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        list(, $p) = TicketDraft::load($id, $dir);
        check($p['by'] === 'user' && $p['bundle'] === null && $p['subject'] === 'old', json_encode($p));
        list($bad) = DraftStore::save($dir, json_encode($v1 + ['by' => 'robot', 'bundle' => null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $err = '';
        try {
            TicketDraft::load($bad, $dir);
        } catch (CliError $e) {
            $err = $e->getMessage();
        }
        check(strpos($err, 'not a ticket draft') !== false, 'an unknown by was accepted: ' . $err);
        testRmTree($dir);
    },
    'ticket bundle tool: an agent bundle needs memory and docs searches and ten review rounds; a user bundle needs neither, and says why there is no transcript' => function () {
        $tree = bundleTree([], ['md' => bundleExportFixture([[['terminal', ['command' => 'pikkumies search "rtorrent"']]]])]);
        try {
            $id = bundleIn($tree, 'rtorrent will not start');
            check(strpos($id, 'no docs search') !== false && !glob("$tree/pikkumies/local/state/ticket/*.json"), 'an agent bundle without a docs search was saved: ' . $id);
            bundleRun($tree, ['list'], ['md' => null]);
            mkdir("$tree/metrics", 0700);
            file_put_contents("$tree/metrics/searches-" . gmdate('Ym') . '.jsonl', json_encode(['ts' => time(), 'tiers' => [1, 2, 3], 'hits' => ['1' => 0, '2' => 2, '3' => 0], 'words' => 'rtorrent lock']) . "\n");
            file_put_contents("$tree/review.md", implode("\n", array_map(function ($i) {
                return "$i. angle $i";
            }, range(1, 9))) . "\n");
            check(strpos(bundleIn($tree, 'rtorrent will not start'), 'ten numbered rounds') !== false, 'nine rounds were accepted');
            $id = bundleIn($tree, 'please look at my server', 'user');
            check(preg_match('/^[0-9a-f]{32}$/', $id) === 1, 'a user bundle was refused: ' . $id);
            $diag = bundleFile($tree, $id, 1);
            check(strpos($diag, 'memory search metrics of the last 24 hours') !== false && strpos($diag, '"rtorrent lock"') !== false && strpos($diag, 'no review was required') !== false, $diag);
            check(strpos(bundleFile($tree, $id, 2), 'No transcript: no current Pikkumies session') !== false, 'the missing transcript is not explained');
            check(bundleDraft($tree, $id)['by'] === 'user', 'the draft does not say who opened it');
        } finally {
            testRmTree($tree);
        }
    },
    'ticket bundle tool: the facts say which privilege applied and the process list follows it; an automatic ticket or a Pulsed Media server keeps this account only' => function () {
        foreach ([['root', [], false, 'agent', 'all users', '-e', 'ps', ''], ['sudo', [], false, 'agent', 'all users', '-e', 'sudo', ''], ['none', [], false, 'agent', 'this account only', '-u', 'ps', ''],
            ['root', ['auto' => true], false, 'agent', 'this account only', '-u', 'ps', 'automatic ticket'], ['root', [], true, 'user', 'this account only', '-u', 'ps', 'Pulsed Media server']] as $c) {
            $tree = bundleTree($c[1], ['root' => $c[0], 'pmss' => $c[2]]);
            try {
                $id = bundleIn($tree, "check {$c[0]}", $c[3]);
                $sys = bundleFile($tree, $id, 0);
                $p = bundleDraft($tree, $id);
                $label = ['root' => 'root (running as root', 'sudo' => 'sudo (passwordless sudo', 'none' => 'none (no root'][$c[0]];
                check(is_array($p) && $p['bundle']['process_scope'] === $c[4] && $p['bundle']['privilege'] === $c[0] && strpos($sys, "- Privilege: $label") !== false, json_encode($c) . " $id " . substr($sys, 0, 700));
                // The bare word "skipped" also turns up in real /var/log content (anacron, cron.daily, NetworkManager, ...)
                // that root or sudo privilege legitimately attaches (ADR-0100): match the one marker TicketBundleRun
                // writes, never a plain substring search over a whole log attachment it does not control.
                check($c[7] === '' ? strpos($sys, '(system-wide reads skipped: ') === false : strpos($sys, "(system-wide reads skipped: {$c[7]})") !== false, json_encode($c) . ' skip note');
                check(strpos($sys, '- Hostname: box.example.org (box)') !== false && strpos($sys, '- IP addresses: 192.0.2.10') !== false, 'hostname and address');
                $ps = array_values(array_filter(array_map('json_decode', file("$tree/argv.jsonl")), function ($a) {
                    return in_array('ps', $a, true);
                }))[0];
                check($ps[0] === $c[6] && in_array($c[5], $ps, true), json_encode($c) . ' ps ' . json_encode($ps));
            } finally {
                testRmTree($tree);
            }
        }
    },
    'ticket bundle tool: bundle then send posts the ticket once through the ticket sender with three attachments and the framing; an automatic send is recorded' => function () {
        list($proc, $base, $srv) = bundleServer();
        $tree = bundleTree([], ['endpoint' => "$base/ok"]);
        try {
            $id = bundleIn($tree, 'rtorrent will not start');
            list($code, $l) = bundleRun($tree, ['list']);
            check($code === 0 && $l[0]['id'] === $id && !bundleCalls($srv), 'bundle or list sent something');
            list($code, $l, $out) = bundleRun($tree, ['send', $id]);
            check($code === 0 && $l[0]['ticket'] === '777' && $l[0]['auto'] === true, $out);
            $body = json_decode(bundleCalls($srv)[0]->body, true);
            check($body['email'] === 'owner@example.org' && array_column($body['attachments'], 'name') === TicketBundle::NAMES, json_encode(array_keys($body)));
            check(strpos($body['message'], TicketBundle::AGENT_TEXT) !== false && strpos($body['message'], 'The account holder has not seen this ticket.') !== false
                && strpos($body['message'], 'Server: box.example.org (box); IP 192.0.2.10') !== false && strpos($body['message'], 'Needs: root on the server itself') !== false, $body['message']);
            check(is_file("$tree/pikkumies/local/state/ticket/$id.sent.json"), 'the draft was not marked sent');
            list($code, , $out) = bundleRun($tree, ['send', $id]);
            check($code === 1 && strpos($out, 'already sent') !== false && count(bundleCalls($srv)) === 1, 'a sent draft went out again: ' . $out);
            // Two lines for the one attempt: -2 written before posting, then the answer, under the same attempt id.
            $lines = array_map('json_decode', file("$tree/pikkumies/local/state/ticket/auto.jsonl"), array_fill(0, 2, true));
            check(count($lines) === 2 && $lines[0]['status'] === -2 && $lines[1]['status'] === 201 && $lines[1]['ticket'] === '777' && $lines[0]['attempt'] === $lines[1]['attempt']
                && strpos(json_encode($lines), 'vsk-') === false && !isset($lines[1]['email']), json_encode($lines));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($srv);
            testRmTree($tree);
        }
    },
    'ticket bundle tool: automatic sends stop at 2 attempts in 24 hours (failed ones count) and at a repeated subject; a user bundle is not capped' => function () {
        list($proc, $base, $srv) = bundleServer();
        $tree = bundleTree([], ['endpoint' => "$base/down"]);
        try {
            foreach (['one', 'two', 'three'] as $i => $subject) {
                list($code, , $out) = bundleRun($tree, ['send', bundleIn($tree, "outage $subject")]);
                check($code === 1 && ($i < 2 ? strpos($out, 'HTTP 503') !== false : strpos($out, '2 tickets by itself in the last 24 hours') !== false), "attempt $i: $out");
            }
            check(count(bundleCalls($srv)) === 2, 'the third automatic attempt was posted');
            bundleRun($tree, ['list'], ['endpoint' => "$base/ok"]);
            $user = bundleIn($tree, 'asked by the user', 'user');
            list($code, , $out) = bundleRun($tree, ['send', $user]);
            check($code === 0 && count(bundleCalls($srv)) === 3, 'a user bundle was capped: ' . $out);
        } finally {
            testRmTree($tree);
        }
        $tree = bundleTree([], ['endpoint' => "$base/ok"]);
        try {
            list($code) = bundleRun($tree, ['send', bundleIn($tree, 'Disk full')]);
            list($code2, , $out) = bundleRun($tree, ['send', bundleIn($tree, 'disk FULL!')]);
            check($code === 0 && $code2 === 1 && strpos($out, 'with this subject') !== false && count(bundleCalls($srv)) === 4, 'a repeated subject went out: ' . $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($srv);
            testRmTree($tree);
        }
    },
    'ticket bundle tool: an automatic ticket scrubs long key-like runs from the system and transcript files; a user bundle keeps them for the user to read' => function () {
        $tree = bundleTree();
        try {
            $key = 'yAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk=';
            file_put_contents("$tree/home/fixtureuser-app.log", "peer key $key\n");
            foreach (['agent' => true, 'user' => false] as $by => $scrubbed) {
                $id = bundleIn($tree, "key check $by", $by, ['--log', "$tree/home/fixtureuser-app.log"]);
                $sys = bundleFile($tree, $id, 0);
                check(bundleDraft($tree, $id)['bundle']['scrubbed'] === $scrubbed && (strpos($sys, $key) === false) === $scrubbed && (strpos($sys, '[long token removed]') !== false) === $scrubbed, "$by: $id " . substr($sys, -300));
            }
        } finally {
            testRmTree($tree);
        }
    },
    'ticket-send guard: an agent bundle with auto on runs unasked, also under yolo; a user bundle, a system-wide bundle or auto off is asked; a hand-made bundle is blocked' => function () {
        $tree = bundleTree(['auto' => true], ['pmss' => false]);
        try {
            $tool = "$tree/pikkumies/tools/system/ticket/ticket.php";
            $agent = bundleIn($tree, 'rtorrent will not start');
            $user = bundleIn($tree, 'please look', 'user');
            check(bundleHook($tree, "php $tool send $agent") === null && bundleHook($tree, "php $tool send $agent", ['HERMES_YOLO_MODE' => '1']) === null, 'an agent bundle with auto on was held');
            $a = bundleHook($tree, "php $tool send $user");
            check(is_array($a) && $a['action'] === 'approve' && $a['rule_key'] === "pikkumies-send:$user" && strpos($a['message'], '3 diagnostic files (system and logs, diagnosis, transcript)') !== false, json_encode($a));
            $a = bundleHook($tree, "php $tool send $user", ['HERMES_YOLO_MODE' => '1']);
            check(is_array($a) && $a['action'] === 'approve' && $a['rule_key'] === "pikkumies-send:$user", 'yolo (the default run mode) changed the question for a user bundle: ' . json_encode($a));
            // Collected system-wide (auto off, root), then auto turned on: still asked.
            file_put_contents("$tree/pikkumies/local/config.php", str_replace("'auto' => true", "'auto' => false", (string) file_get_contents("$tree/pikkumies/local/config.php")));
            $a = bundleHook($tree, "php $tool send $agent");
            check(is_array($a) && $a['action'] === 'approve', 'an agent bundle with auto off was not asked: ' . json_encode($a));
            bundleRun($tree, ['list'], ['root' => 'root']);
            $wide = bundleIn($tree, 'collected with root');
            file_put_contents("$tree/pikkumies/local/config.php", str_replace("'auto' => false", "'auto' => true", (string) file_get_contents("$tree/pikkumies/local/config.php")));
            check(bundleDraft($tree, $wide)['bundle']['process_scope'] === 'all users', 'the root bundle was not system-wide');
            $a = bundleHook($tree, "php $tool send $wide");
            check(is_array($a) && $a['action'] === 'approve', 'a system-wide agent bundle went out unasked: ' . json_encode($a));
            // Hand-made drafts. Other names (the review's probe): blocked before anything runs, and send refuses it.
            $mk = function (array $files, array $bundle, $message) use ($tree) {
                $att = [];
                foreach ($files as $name => $text) {
                    $att[] = ['name' => $name, 'data' => base64_encode($text)];
                }
                $p = ['v' => 1, 'created_at' => time(), 'email' => 'owner@example.org', 'subject' => 'Pikkumies: x', 'message' => $message, 'priority' => 'Medium',
                    'service' => '', 'attachments' => $att, 'by' => 'agent', 'bundle' => $bundle + ['signature' => 's' . count($att) . strlen($message)]];
                return DraftStore::save("$tree/pikkumies/local/state/ticket", json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))[0];
            };
            $fake = $mk(['secret.txt' => "PrivateKey = yAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk=\n"], ['process_scope' => 'this account only', 'scrubbed' => true], 'hi');
            $a = bundleHook($tree, "php $tool send $fake");
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'not made by bundle') !== false, 'a hand-made bundle was let through: ' . json_encode($a));
            list($code, , $out) = bundleRun($tree, ['send', $fake]);
            check($code === 1 && strpos($out, 'not made by bundle') !== false, 'send took a hand-made bundle: ' . $out);
            // The right names and clean content, but a record that says it was not scrubbed: it never goes out unasked.
            $clean = array_combine(TicketBundle::NAMES, ["facts\n", "diagnosis\n", "talk\n"]);
            $unseen = "Pikkumies opened this ticket.\n" . TicketAuto::UNSEEN . "\nIt reports a problem.\n";
            $fake2 = $mk($clean, ['process_scope' => 'this account only', 'privilege' => 'none', 'scrubbed' => false], $unseen);
            $a = bundleHook($tree, "php $tool send $fake2");
            check(is_array($a) && $a['action'] === 'approve', 'a hand-made, unscrubbed bundle went out unasked: ' . json_encode($a));
            // Everything a bundle record could claim, but the hook runs as root: never unasked (ADR-0100: the record is self-asserted).
            $fake3 = $mk($clean, ['process_scope' => 'this account only', 'privilege' => 'none', 'scrubbed' => true], $unseen);
            check(bundleHook($tree, "php $tool send $fake3") === null, 'the full mimic should pass as non-root (the record is self-asserted; documented)');
            bundleRun($tree, ['list'], ['euid' => 0]);
            $a = bundleHook($tree, "php $tool send $fake3");
            check(is_array($a) && $a['action'] === 'approve', 'as root an automatic ticket went out unasked: ' . json_encode($a));
            bundleRun($tree, ['list'], ['euid' => null]);
        } finally {
            testRmTree($tree);
        }
    },
    'ticket-send guard: a bundle made with ticket.auto off asks even after auto is turned on; as root an automatic bundle has no process list and asks; a bundle collected with root asks; plain files under the bundle names are refused' => function () {
        list($proc, $base, $srv) = bundleServer();
        $tree = bundleTree(['auto' => false], ['pmss' => false, 'endpoint' => "$base/ok"]);
        try {
            $tool = "$tree/pikkumies/tools/system/ticket/ticket.php";
            // N1: bundled for the user's eyes (not scrubbed, "approved"), then auto turned on.
            $early = bundleIn($tree, 'bundled with auto off');
            $p = bundleDraft($tree, $early);
            check($p['bundle']['scrubbed'] === false && strpos($p['message'], 'approved sending this ticket') !== false, 'the auto-off bundle is not what the test needs');
            file_put_contents("$tree/pikkumies/local/config.php", str_replace("'auto' => false", "'auto' => true", (string) file_get_contents("$tree/pikkumies/local/config.php")));
            $a = bundleHook($tree, "php $tool send $early");
            check(is_array($a) && $a['action'] === 'approve', 'a bundle made with auto off went out unasked once auto was on: ' . json_encode($a));
            list($code, $l, $out) = bundleRun($tree, ['send', $early]);
            check($code === 0 && $l[0]['auto'] === false && !is_file("$tree/pikkumies/local/state/ticket/auto.jsonl"), 'the send counted as automatic: ' . $out);
            // N3: running as root, an automatic bundle carries no processes, says so, and asks.
            bundleRun($tree, ['list'], ['euid' => 0, 'root' => 'root']);
            $rootAuto = bundleIn($tree, 'as root with auto on');
            $p = bundleDraft($tree, $rootAuto);
            check(is_array($p) && $p['bundle']['process_scope'] === 'not collected' && strpos(bundleFile($tree, $rootAuto, 0), 'not collected: Pikkumies runs as root') !== false, 'as root, processes were collected: ' . $rootAuto);
            $a = bundleHook($tree, "php $tool send $rootAuto");
            check(is_array($a) && $a['action'] === 'approve', 'as root an automatic bundle went out unasked: ' . json_encode($a));
            // N3: collected with root privilege (as a sudo-capable or root-detected account, not euid 0): asked.
            bundleRun($tree, ['list'], ['euid' => null, 'root' => 'root']);
            $priv = bundleIn($tree, 'root privilege with auto on');
            $p = bundleDraft($tree, $priv);
            check($p['bundle']['privilege'] === 'root' && $p['bundle']['process_scope'] === 'this account only', json_encode($p['bundle']));
            $a = bundleHook($tree, "php $tool send $priv");
            check(is_array($a) && $a['action'] === 'approve', 'a bundle collected with root privilege went out unasked: ' . json_encode($a));
            // N6: a plain draft whose files carry the bundle's names is refused, by the guard and by send.
            $files = [];
            foreach (TicketBundle::NAMES as $name) {
                file_put_contents("$tree/home/$name", "my own file\n");
                $files[] = "$tree/home/$name";
            }
            list($code, $l, $out) = bundleRun($tree, array_merge(['draft', '--subject', 'own files', '--text', 'see the files'], $files));
            $own = $l[0]['id'];
            check($code === 0 && $l[0]['bundle'] === false, 'draft: ' . $out);
            $a = bundleHook($tree, "php $tool send $own");
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'not made by bundle') !== false, 'files under the bundle names were asked about as a bundle: ' . json_encode($a));
            list($code, , $out) = bundleRun($tree, ['send', $own]);
            check($code === 1 && strpos($out, 'not made by bundle') !== false, 'send took files under the bundle names: ' . $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($srv);
            testRmTree($tree);
        }
    },
    'ticket bundle tool: when the automatic-attempt line cannot be written before posting, the ticket is not sent' => function () {
        list($proc, $base, $srv) = bundleServer();
        $tree = bundleTree([], ['endpoint' => "$base/ok"]);
        try {
            mkdir("$tree/pikkumies/local/state/ticket/auto.jsonl", 0700); // a directory where the line would go: no write possible
            list($code, , $out) = bundleRun($tree, ['send', bundleIn($tree, 'record first')]);
            check($code === 1 && strpos($out, 'record could not be written') !== false && !bundleCalls($srv), 'a ticket went out without its attempt on record: ' . $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($srv);
            testRmTree($tree);
        }
    },
];
