<?php
/**
 * The report unit (tools/documentation/report, ADR-0091): verbs against a throwaway local/ directory (the
 * $reportLocalDir seam), file rules (output only under local/reports, 0600, --out is a name), --strict, the
 * templates from fixture data, and the browser runner's argv and timeout. No browser is started here.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/report/ReportKit.php';
require_once dirname(__DIR__) . '/pikkumies/tools/documentation/report/ReportPrint.php';
require_once dirname(__DIR__) . '/pikkumies/tools/documentation/report/ReportTemplates.php';
require_once dirname(__DIR__) . '/pikkumies/tools/documentation/report/ReportFiles.php';

/** Runs report.php with the local dir (and account record) seams; returns [exit code, decoded lines]. */
function ruRun($local, array $args, $account = null)
{
    $wrap = "$local/wrap.php";
    file_put_contents($wrap, '<?php $reportLocalDir = ' . var_export($local, true) . '; $reportNow = 1760950000; $reportAccount = function () { return '
        . var_export($account, true) . '; }; require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/documentation/report/report.php', true) . ';');
    list($code, $out) = runPhp($wrap, $args);
    return [$code, cliLines($out)];
}

function ruStatus(array $lines)
{
    return $lines ? $lines[count($lines) - 1] : [];
}

return [
    'report unit: example, lint and render --html-only write only under local/reports with 0600 files' => function () {
        $local = testTempDir('pk-report');
        try {
            list($c, $l) = ruRun($local, ['example', '--out', 'ex.json']);
            check($c === 0 && ruStatus($l)['verified'] === true && is_file("$local/reports/ex.json"), 'example: ' . json_encode($l));
            list($c, $l) = ruRun($local, ['lint', "$local/reports/ex.json"]);
            check($c === 0 && ruStatus($l)['review'] === 'clean' && ruStatus($l)['checks'] === 13, 'lint: ' . json_encode($l));
            list($c, $l) = ruRun($local, ['render', "$local/reports/ex.json", '--out', 'ex.pdf', '--html-only']);
            $st = ruStatus($l);
            check($c === 0 && $st['pdf'] === null && $st['verified'] === false && is_file("$local/reports/ex.html"), 'render: ' . json_encode($l));
            check((fileperms("$local/reports/ex.html") & 0777) === 0600 && (fileperms("$local/reports") & 0777) === 0700, 'modes');
            check(strpos((string) file_get_contents("$local/reports/ex.html"), '<section class="rk-cover">') !== false, 'the HTML is not a report page');
            check(!glob("$local/reports/.*.tmp"), 'a temp file was left behind');
        } finally {
            testRmTree($local);
        }
    },
    'report unit: --out is a file name, never a path; a shape error and a missing file are refusals with hints' => function () {
        $local = testTempDir('pk-report');
        try {
            ruRun($local, ['example', '--out', 'ex.json']);
            foreach (['../x.pdf', '/tmp/x.pdf', 'a/b.pdf'] as $bad) {
                list($c, $l) = ruRun($local, ['render', "$local/reports/ex.json", '--out', $bad, '--html-only']);
                check($c === 1 && strpos(ruStatus($l)['error'], 'give a file name') !== false, "$bad accepted: " . json_encode($l));
            }
            file_put_contents("$local/bad.json", '{"sections": []}');
            list($c, $l) = ruRun($local, ['lint', "$local/bad.json"]);
            check($c === 1 && strpos(ruStatus($l)['error'], 'no title') !== false && ruStatus($l)['hint'] !== '', json_encode($l));
            list($c, $l) = ruRun($local, ['lint', "$local/none.json"]);
            check($c === 1 && strpos(ruStatus($l)['error'], 'not a readable file') !== false, json_encode($l));
        } finally {
            testRmTree($local);
        }
    },
    'report unit: --strict refuses to render a report with review errors; without it the findings are records' => function () {
        $local = testTempDir('pk-report');
        try {
            file_put_contents("$local/r.json", json_encode(['title' => 'T', 'kpis' => [['label' => 'Bare', 'value' => 5]],
                'sections' => [['title' => 'S', 'blocks' => [['type' => 'prose', 'text' => 'ok']]]]]));
            list($c, $l) = ruRun($local, ['render', "$local/r.json", '--strict', '--html-only', '--out', 'r.pdf']);
            check($c === 1 && strpos(ruStatus($l)['error'], '--strict') !== false && !is_file("$local/reports/r.html"), json_encode($l));
            list($c, $l) = ruRun($local, ['render', "$local/r.json", '--html-only', '--out', 'r.pdf']);
            check($c === 0 && ruStatus($l)['errors'] === 1 && $l[0]['check'] === 'unsourced' && $l[0]['finding'] === 'error', json_encode($l));
        } finally {
            testRmTree($local);
        }
    },
    'report unit: account-health builds sourced figures from the account record and the telemetry history' => function () {
        $local = testTempDir('pk-report');
        try {
            $now = 1760950000;
            @mkdir("$local/state/telemetry/system-account", 0700, true);
            $lines = '';
            for ($i = 0; $i < 48; $i++) {
                $lines .= json_encode(['ts' => $now - 86400 * 2 + $i * 3600, 'disk_used' => 500000000000 + $i * 1000000000, 'disk_used_pct' => 50 + $i / 10]) . "\n";
            }
            file_put_contents("$local/state/telemetry/system-account/" . gmdate('Ymd', $now - 86400) . '.jsonl', $lines . "not json\n");
            $acct = ['source' => 'userShowLimits', 'at' => gmdate('c', $now - 30), 'disk' => ['used' => 920, 'soft' => 1000], 'traffic' => ['upload_30d' => 10, 'limit' => null],
                'memory' => ['used' => 1, 'limit' => 4, 'oom_kills' => 0], 'processes' => ['used' => null], 'apps' => ['rtorrent', ['x' => 1]]];
            list($c, $l) = ruRun($local, ['templates', 'account-health', '--out', 'ah.json'], $acct);
            check($c === 0 && ruStatus($l)['errors'] === 0, json_encode($l));
            $doc = json_decode((string) file_get_contents("$local/reports/ah.json"), true);
            check($doc['verdict']['tone'] === 'crit' && strpos($doc['verdict']['headline'], '{fig:diskPct}') !== false, 'verdict: ' . json_encode($doc['verdict']));
            check($doc['figures']['diskPct']['value'] == 92 && $doc['figures']['disk']['source'] === 'system-status account (userShowLimits)', 'figures: ' . json_encode($doc['figures']));
            check(!isset($doc['figures']['processes']) && !isset($doc['figures']['uploadLimit']), 'a missing figure was invented');
            $titles = array_column($doc['sections'], 'title');
            check($titles === ['Limits', 'History', 'Everything recorded', 'Running apps'], 'sections: ' . json_encode($titles));
            check($doc['sections'][1]['blocks'][0]['unit'] === 'bytes' && count($doc['sections'][1]['blocks'][0]['series'][0]['points']) === 48, 'history chart');
        } finally {
            testRmTree($local);
        }
    },
    'report unit: services-overview and move-report read their files; templates lists what is present' => function () {
        $local = testTempDir('pk-report');
        try {
            @mkdir("$local/state/moves", 0700, true);
            file_put_contents("$local/state/services.jsonl", json_encode(['nick' => 'hdd1', 'tags' => ['seedbox'], 'provider' => 'pmss', 'host' => 'box.example.com', 'allow_delete' => true]) . "\n"
                . json_encode(['nick' => 'nas', 'tags' => ['backup'], 'provider' => 'generic']) . "\n");
            file_put_contents("$local/state/moves/m1.jsonl", json_encode(['ts' => '2025-10-20T08:00:00Z', 'step' => 'copy', 'torrent' => 'abc', 'result' => 'ok', 'tracker' => 'https://t.example/announce?passkey=SECRET']) . "\n"
                . json_encode(['ts' => '2025-10-20T08:30:00Z', 'step' => 'recheck', 'torrent' => 'abc', 'result' => 'failed', 'message' => 'size differs']) . "\n");
            touch("$local/state/services.jsonl", 1760940000); // the list is dated by its mtime: before the report time seam
            list(, $l) = ruRun($local, ['templates']);
            check(array_column(array_slice($l, 0, 3), 'data', 'template') === ['account-health' => 'present', 'services-overview' => 'present', 'move-report' => 'present'], json_encode($l));
            list($c, $l) = ruRun($local, ['templates', 'services-overview', '--out', 's.json']);
            $s = json_decode((string) file_get_contents("$local/reports/s.json"), true);
            check($c === 0 && ruStatus($l)['errors'] === 0 && $s['verdict']['tone'] === 'warn' && count($s['sections'][0]['blocks'][0]['rows']) === 2, json_encode($l));
            list($c, $l) = ruRun($local, ['templates', 'move-report', '--out', 'm.json']);
            $raw = (string) file_get_contents("$local/reports/m.json");
            $m = json_decode($raw, true);
            check($c === 0 && $m['verdict']['tone'] === 'crit' && $m['kpis'][2]['value']['value'] == 30, json_encode($m['kpis']));
            check(strpos($raw, 'SECRET') === false && strpos($raw, 'announce') === false, 'a field outside the whitelist reached the report');
            list($c, $l) = ruRun($local, ['templates', 'move-report', '--move', '../x']);
            check($c === 1, 'a path as move id was accepted');
        } finally {
            testRmTree($local);
        }
    },
    'report review fix P-1: a probe that hangs (no setsid) is killed at its time limit' => function () {
        $tmp = testTempDir('pk-report');
        try {
            $t = microtime(true);
            $r = ReportPrint::run(['sleep', '30'], 1, $tmp);
            check($r['timedOut'] === true && microtime(true) - $t < 8, 'the probe ran past its limit: ' . round(microtime(true) - $t, 1) . 's');
        } finally {
            testRmTree($tmp);
        }
    },
    'report review fix P-2: move-report redacts passkeys and user:password@ in the free-text detail' => function () {
        $local = testTempDir('pk-report');
        try {
            @mkdir("$local/state/moves", 0700, true);
            file_put_contents("$local/state/moves/m2.jsonl", json_encode(['ts' => '2025-10-20T08:00:00Z', 'step' => 'add', 'result' => 'failed',
                'error' => 'tracker https://tracker.example.org/announce/0123456789abcdef0123456789abcdef refused; qbt login admin:hunter2@10.0.0.5', 'message' => 'passkey=feedfacecafe']) . "\n");
            $doc = ReportTemplates::moveReport($local, 'm2', 1760950000);
            $j = json_encode($doc, JSON_UNESCAPED_SLASHES);
            check(strpos($j, '0123456789abcdef0123456789abcdef') === false && strpos($j, 'hunter2') === false && strpos($j, 'feedfacecafe') === false, 'a secret reached the report: ' . $j);
            check(strpos($j, 'refused') !== false, 'the rest of the detail was lost');
        } finally {
            testRmTree($local);
        }
    },
    'report review fix P-3: HTML larger than the print limit is refused before Chromium starts' => function () {
        check(ReportFiles::printable(str_repeat('x', 100)) === '', 'small HTML refused');
        check(strpos(ReportFiles::printable(str_repeat('x', ReportFiles::MAX_HTML + 1)), 'summarise') !== false, 'oversized HTML accepted');
    },
    'report print: argv runs chromium in its own session and a private network namespace, network off either way' => function () {
        $a = ReportPrint::argv('/usr/bin/chromium', '/p', '/r/x.html', '/r/x.pdf', true);
        check(array_slice($a, 0, 5) === ['setsid', 'unshare', '--map-root-user', '--net', '/usr/bin/chromium'], json_encode($a));
        foreach (['--headless', '--no-sandbox', '--user-data-dir=/p', '--no-pdf-header-footer', '--print-to-pdf=/r/x.pdf', '--host-resolver-rules=MAP * ~NOTFOUND', '--proxy-bypass-list=<-loopback>'] as $f) {
            check(in_array($f, $a, true), "missing $f");
        }
        check(end($a) === 'file:///r/x.html', 'the page is not the last argument');
        $b = ReportPrint::argv('/usr/bin/chromium', '/p', '/r/x.html', '/r/x.pdf', false);
        check($b[0] === 'setsid' && $b[1] === '/usr/bin/chromium' && in_array('--disable-background-networking', $b, true), json_encode($b));
        check(ReportPrint::major('Chromium 141.0.7390.54 built on Debian GNU/Linux 13 (trixie)') === 141 && ReportPrint::major('Google Chrome for Testing 145.0.7632.6') === 145
            && ReportPrint::major('nonsense') === 0, 'version parse');
    },
    'report print: a timeout kills the whole process group and the profile directory is removed' => function () {
        if (ToolProc::which('setsid') === null || ToolProc::which('sh') === null) {
            skip('setsid or sh is not installed here');
        }
        $tmp = testTempDir('pk-report');
        try {
            $t = microtime(true);
            $r = ReportPrint::run(['setsid', 'sh', '-c', 'sleep 30 & sleep 30'], 1, $tmp);
            check($r['timedOut'] === true && microtime(true) - $t < 10, 'not stopped in time: ' . json_encode($r));
            mkdir("$tmp/report-abc/x", 0700, true);
            symlink('/etc', "$tmp/report-abc/link");
            ReportPrint::rmTree("$tmp/report-abc", $tmp);
            check(!file_exists("$tmp/report-abc") && is_dir('/etc'), 'the profile directory was not removed (or the link was followed)');
            mkdir("$tmp/other", 0700);
            ReportPrint::rmTree("$tmp/other", $tmp);
            check(is_dir("$tmp/other"), 'a directory not named report-* was removed');
            check(ReportPrint::browser(null) === null || is_executable((string) ReportPrint::browser(null)), 'browser lookup');
            try {
                ReportPrint::browser('relative/chromium');
                check(false, 'a relative --browser was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'absolute path') !== false, $e->getMessage());
            }
        } finally {
            testRmTree($tmp);
        }
    },
];
