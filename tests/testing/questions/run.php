<?php
/**
 * The question suite (ADR-0039): asks the agent real questions against one or more models and grades what it did
 * and said. Not part of tests/run.php or CI: it needs provider keys and spends tokens. Read README.md first.
 *
 *   php run.php --self-test | --list [--targets F] | --regrade DIR
 *   php run.php --targets F --token-budget N [--target NAME] [--only ID] [--tag T] [--max-turns N] [--timeout S]
 *               [--out DIR] [--install DIR] [--keep]
 */
umask(0077);
$repo = dirname(__DIR__, 3);
$here = __DIR__;
require_once "$repo/pikkumies/lib/Provider.php";
foreach (['Questions', 'Checks', 'Judge', 'Targets', 'Sandbox', 'Asker', 'Report', 'CrontabGuard'] as $lib) {
    require_once "$here/lib/$lib.php";
}
$o = ['targets' => (string) getenv('PIKKUMIES_QA_TARGETS'), 'target' => '', 'only' => '', 'tag' => '', 'regrade' => '', 'token-budget' => null, 'max-tokens' => null,
    'max-turns' => '', 'timeout' => '900', 'out' => '', 'install' => '', 'list' => false, 'self-test' => false, 'keep' => false];
for ($i = 1; $i < count($argv); $i++) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argv[$i], $m) || !array_key_exists($m[1], $o)) {
        fail("unknown argument: {$argv[$i]} (see the top of run.php)", 2);
    }
    $o[$m[1]] = is_bool($o[$m[1]]) ? true : (isset($m[2]) ? $m[2] : (isset($argv[$i + 1]) ? $argv[++$i] : fail("--{$m[1]} needs a value", 2)));
}
// --max-tokens is the older spelling of --token-budget (a budget for the whole run, not a per-request output cap).
// Both at once is refused: which one wins would be a guess.
if ($o['token-budget'] !== null && $o['max-tokens'] !== null) {
    fail('give --token-budget only; --max-tokens is its older spelling', 2);
}
if ($o['token-budget'] === null) {
    $o['token-budget'] = $o['max-tokens'];
}
function fail($message, $code = 1)
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

set_exception_handler(function ($x) {
    fail($x->getMessage());
});

$problems = Checks::selfTest("$here/questions", "$here/samples", "$here/fixtures");
if ($o['self-test']) {
    echo $problems ? implode("\n", $problems) . "\nself-test FAILED\n" : "self-test passed\n";
    exit($problems ? 1 : 0);
}
$questions = Questions::select(Questions::load("$here/questions"), $o['only'], $o['tag']);
if ($o['list']) {
    foreach ($questions as $q) {
        $n = array_sum(array_map('count', array_intersect_key($q['meta'], array_flip(Questions::CHECKS))));
        printf("%-44s %-30s %2d checks %s\n", $q['id'], implode(',', $q['tags']), $n, implode('', Questions::values($q, 'family')));
    }
    echo $o['targets'] !== '' ? 'targets: ' . implode(', ', array_keys(Targets::load($o['targets'], false)['targets'])) . "\n" : '';
    exit(0);
}
if ($o['regrade'] !== '') {
    $cache = $o['install'] !== '' ? Sandbox::reuse($o['install'])->root . '/checkout/pikkumies/local/docs-cache' : '';
    echo Report::scorecard(Report::regrade($o['regrade'], $questions, $cache)) . "regraded: {$o['regrade']}/regrade.jsonl\n";
    exit(0);
}
// An asking run. Lint first: a bad question file stops the run before any token is spent.
if ($problems) {
    fail(implode("\n", $problems) . "\nself-test failed; nothing asked");
}
if ($o['token-budget'] === null || !ctype_digit((string) $o['token-budget'])) {
    fail('--token-budget N is required for an asking run (0 = no limit, chosen on purpose)', 2);
}
if (!ctype_digit($o['timeout']) || ($o['max-turns'] !== '' && !ctype_digit($o['max-turns']))) {
    fail('--timeout and --max-turns take a whole number', 2);
}
$uid = posix_geteuid();
$realHome = posix_getpwuid($uid)['dir'];
$account = posix_getpwuid($uid)['name'];
if ($uid === 0 || !is_file("$realHome/.pikkumies-qa-user")) {
    fail("refusing to ask: the agent's terminal runs as this account and a throwaway HOME does not confine it. Run as a dedicated\n"
        . "user that holds no fleet or SSH keys, and create $realHome/.pikkumies-qa-user there (never as root). See README.md.");
}
$t = Targets::load($o['targets'], true, $o['target']);
$targets = $t['targets'];
$questions || fail('nothing to ask: no question selected');
usort($questions, function ($a, $b) {
    return strcmp(implode('', Questions::values($a, 'family')), implode('', Questions::values($b, 'family')));
});
$out = $o['out'] !== '' ? $o['out'] : sys_get_temp_dir() . '/pikkumies-questions-out-' . gmdate('Ymd-His');
$report = new Report($out, $t['keys']);
$guard = new CrontabGuard($out, $report, $realHome, $account);
$tabBefore = $guard->prev;
file_put_contents("$out/crontab-before.txt", $tabBefore . "\n");
$box = $o['install'] !== '' ? Sandbox::reuse($o['install']) : Sandbox::create($repo);
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --short HEAD'))
    . (trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' status --porcelain --untracked-files=no')) !== '' ? '+changes' : '');
$used = $exit = 0;
$snapped = false;
try {
    if ($o['install'] === '') {
        echo "installing into {$box->root} (a runtime build takes minutes)\n";
        if (($problem = $box->install()) !== '') {
            throw new Exception($problem);
        }
        if ($guard->check('install')) {
            throw new Exception("the real crontab changed during install; see $out/crontab-after-install.txt");
        }
    }
    $box->snapshot(); // the base every reset goes back to; the documentation each family downloads is added below
    $snapped = true;
    // Before any token is spent: a login shell in the sandbox must resolve crontab to the fake, or every terminal
    // command Hermes runs risks the account's real crontab (2026-10-18 RCA: a login bash re-reads /etc/profile).
    list($checkCode) = Asker::run($box, ['bash', '-lc', 'command -v crontab'], '', $box->root . '/crontab-check.out', $box->root . '/crontab-check.err', 10);
    $resolved = trim((string) @file_get_contents($box->root . '/crontab-check.out'));
    $wantResolved = $box->home . '/.qa-bin/crontab';
    if ($checkCode !== 0 || $resolved !== $wantResolved) {
        throw new Exception("refusing to ask: a login shell in the sandbox resolves crontab to '$resolved', not '$wantResolved'; "
            . 'fix the sandbox login PATH (Sandbox::ensureBashProfile) before spending tokens');
    }
    $meta = ['hermes_version' => Asker::hermesVersion($box), 'pikkumies_commit' => $commit];
    $refreshed = [];
    foreach ($targets as $name => $s) {
        foreach ($questions as $q) {
            if ((int) $o['token-budget'] > 0 && $used >= (int) $o['token-budget']) {
                echo "token budget reached: $used of {$o['token-budget']} used; the remaining questions were not asked\n";
                break 2;
            }
            $family = implode('', Questions::values($q, 'family')) ?: (isset($s['family']) ? (string) $s['family'] : '');
            $overlay = Targets::overlay($box, Targets::settings($s, $family));
            if (!isset($refreshed[$family])) { // the family's documentation, downloaded once per run, is part of the snapshot
                $box->reset();
                list($code) = Asker::refresh($box, $overlay);
                echo $code === 0 ? '' : "documentation refresh for family '$family' exited $code (see {$box->root}/refresh.out); asking anyway\n";
                if ($guard->check("refresh:$family", $name)) {
                    break 2;
                }
                $box->snapshot();
                $refreshed[$family] = true;
            }
            $box->reset();
            $judge = ['verdicts' => [], 'tokens' => 0, 'error' => ''];
            $missing = Questions::stale($q, "{$box->root}/checkout/pikkumies/local/docs-cache"); // a fact the source no longer states: not asked
            if ($missing) {
                $report->add($row = Report::row($q, $name, $meta, [], ['tokens' => 0, 'error' => ''], $judge, $missing));
                echo $report->line($row);
                continue;
            }
            $error = isset($q['meta']['setup'][0]) && !Asker::setup($box, "$here/fixtures/" . $q['meta']['setup'][0]) ? 'setup copy failed' : '';
            $rec = Asker::ask($box, $q['body'], $overlay, $o['max-turns'], (int) $o['timeout']);
            $rec['error'] = $error ?: $rec['error'];
            if (Questions::values($q, 'judge')) {
                $judge = isset($t['judge']) ? Judge::ask($t['judge'], $t['keys']['judge'], Questions::values($q, 'judge'), $rec['text'])
                    : ['verdicts' => [], 'tokens' => 0, 'error' => 'no judge in the targets file'];
            }
            $row = Report::row($q, $name, $meta, Checks::check($q, $rec), $rec, $judge);
            $used += $row['tokens'];
            $report->transcript($name, $q['id'], "{$box->root}/raw.jsonl");
            $report->add($row);
            echo $report->line($row);
            if ($guard->check($q['id'], $name)) {
                break 2;
            }
        }
    }
} catch (Exception $x) {
    fwrite(STDERR, $report->mask($x->getMessage()) . "\n");
    $exit = 1;
    $o['keep'] = true;
} finally {
    Asker::killStray($box->home);
    if ($snapped) {
        $box->reset(); // a kept sandbox is clean for the next --install
    }
    $o['keep'] || $o['install'] !== '' ? print("sandbox kept: {$box->root}\n") : $box->remove();
    echo Report::scorecard($report->rows) . "results: $out/results.jsonl, tokens used: $used\n";
    $tabFinal = CrontabGuard::realTab();
    file_put_contents("$out/crontab-after.txt", $guard->mask($tabFinal) . "\n");
    if ($tabFinal !== $tabBefore || $guard->changedBy) {
        fail("!!! THE ACCOUNT'S REAL CRONTAB CHANGED DURING THIS RUN (during: " . implode(', ', $guard->changedBy ?: ['(after the last check)'])
            . "). Compare it with the copy in $out/crontab-before.txt and repair it. !!!", 3);
    }
}
exit($exit);
