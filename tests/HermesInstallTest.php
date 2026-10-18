<?php
/**
 * lib/HermesInstall.php: vanilla Hermes as a git checkout at pikkumies/hermes, bootstrapped the way upstream's
 * installers do (pinned uv, bootstrap Python, pm.cli install) and updated on its own path (ADR-0033).
 * Hermetic: a local bare repository stands in for GitHub (file://), a fake uv (in a tarball pinned by
 * sha256 in the fixture's pm/lock.json, fetched by curl from file://), a fake bootstrap Python whose
 * `-m pm.cli install` writes .hermes/bin/hermes, and HermesInstall::$root points at a temp pikkumies/.
 * Never the network, never the real pikkumies/hermes.
 */

/** Write an executable PHP script (fake binary) to $path. */
function hermesTFake($path, $body)
{
    file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $body);
    chmod($path, 0755);
}

/** Run a command line, throw when it fails; returns trimmed stdout. */
function hermesTSh($cmd)
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new Exception("command failed ($code): $cmd: " . implode("\n", $out));
    }
    return trim(implode("\n", $out));
}

/**
 * A temp world: remote.git (tags v0.9.0, v2026.9.24, v0.10.0, then an unreleased main commit) whose tree
 * holds every CONFIG_FACTS text, tests/ website/ apps/, an upstream-style .gitignore, a setup-hermes.sh
 * that must never run, and pm/lock.json pinning the fake uv tarball by sha256 ($badSha pins a wrong one).
 * Also a fake bin dir, a log every fake appends to, a HOME, and pikkumies/ as the root.
 */
function hermesTWorld($badSha = false)
{
    if (trim((string) shell_exec('command -v git 2>/dev/null')) === '' || trim((string) shell_exec('command -v curl 2>/dev/null')) === '') {
        skip('git or curl is not installed here');
    }
    $t = testTempDir('pk-hermes');
    $bin = "$t/fakebin";
    mkdir($bin);
    mkdir("$t/home");
    $log = "$t/fake.log";
    $logLine = 'file_put_contents(getenv("PK_FAKE_LOG"), %s . "\n", FILE_APPEND);';
    // Fake hermes: logs its argv; `hooks test` prints what the real one prints for a block, or the text in PK_FAKE_HOOK_OUT.
    hermesTFake("$bin/fake-hermes", sprintf($logLine, '"hermes " . implode(" ", array_slice($argv, 1))') . '
if (isset($argv[2]) && $argv[1] === "hooks" && $argv[2] === "test") {
    $i = array_search("--payload-file", $argv, true);
    file_put_contents(getenv("PK_FAKE_LOG"), "payload " . ($i !== false ? file_get_contents($argv[$i + 1]) : "none") . "\n", FILE_APPEND);
    $o = getenv("PK_FAKE_HOOK_OUT");
    echo $o !== false && $o !== "" ? $o : "      parsed (Hermes wire shape): {\"action\": \"block\", \"message\": \"Pikkumies safety: recursive force delete. Not run.\"}\n";
}
');
    // Fake bootstrap Python: only `-m pm.cli install ...`, which publishes the tree's launcher like pm does.
    hermesTFake("$bin/boot-python", sprintf($logLine, '"pm " . implode(" ", array_slice($argv, 1)) . " | cwd=" . getcwd() . " | HERMES_HOME=" . getenv("HERMES_HOME") . " | RUNTIME_DIR=" . var_export(getenv("HERMES_RUNTIME_DIR"), true)') . '
if (array_slice($argv, 1, 3) !== ["-m", "pm.cli", "install"]) { exit(2); }
@mkdir(getcwd() . "/.hermes/bin", 0700, true);
copy(' . var_export("$bin/fake-hermes", true) . ', getcwd() . "/.hermes/bin/hermes");
chmod(getcwd() . "/.hermes/bin/hermes", 0755);
');
    // Fake uv: python install puts the bootstrap Python under UV_PYTHON_INSTALL_DIR; python find prints it.
    mkdir("$t/uvsrc/uv-x86_64-unknown-linux-gnu", 0700, true);
    hermesTFake("$t/uvsrc/uv-x86_64-unknown-linux-gnu/uv", sprintf($logLine, '"uv " . implode(" ", array_slice($argv, 1)) . " | PYDIR=" . getenv("UV_PYTHON_INSTALL_DIR") . " | CACHE=" . getenv("UV_CACHE_DIR") . " | NOCONFIG=" . getenv("UV_NO_CONFIG")') . '
$py = getenv("UV_PYTHON_INSTALL_DIR") . "/cpython-" . end($argv) . "/bin/python3";
if (array_slice($argv, 1, 2) === ["python", "install"]) { @mkdir(dirname($py), 0700, true); copy(' . var_export("$bin/boot-python", true) . ', $py); chmod($py, 0755); exit(0); }
if (array_slice($argv, 1, 2) === ["python", "find"]) { if (!is_file($py)) { exit(2); } echo $py, "\n"; exit(0); }
exit(0);
');
    hermesTSh('tar -czf ' . escapeshellarg("$t/uv.tar.gz") . ' -C ' . escapeshellarg("$t/uvsrc") . ' uv-x86_64-unknown-linux-gnu');
    $sha = $badSha ? str_repeat('0', 64) : hash_file('sha256', "$t/uv.tar.gz");

    $w = "$t/work";
    mkdir("$w/pm", 0700, true);
    foreach (HermesInstall::CONFIG_FACTS as $fact) {
        @mkdir(dirname("$w/{$fact[0]}"), 0700, true);
        file_put_contents("$w/{$fact[0]}", (is_file("$w/{$fact[0]}") ? file_get_contents("$w/{$fact[0]}") : '') . $fact[1] . "\n");
    }
    foreach (['tests', 'website', 'apps'] as $d) {
        mkdir("$w/$d");
        file_put_contents("$w/$d/keep.txt", "upstream $d\n");
    }
    $art = ['url' => 'file://' . "$t/uv.tar.gz", 'sha256' => $sha];
    file_put_contents("$w/pm/lock.json", json_encode(['schema' => 1, 'packages' => [
        'python' => ['version' => '3.14.7+20260901', 'artifacts' => []],
        'uv' => ['version' => '0.0.1', 'artifacts' => ['linux-x64' => $art, 'linux-arm64' => $art, 'darwin-x64' => $art, 'darwin-arm64' => $art]],
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents("$w/pm/artifact-mirror.json", "{\n  \"origin\": \"file://$t/mirror\",\n  \"prefix\": \"sha256/\"\n}\n");
    hermesTFake("$w/setup-hermes.sh", sprintf($logLine, '"SETUP SCRIPT RAN"'));
    file_put_contents("$w/.gitignore", ".hermes/\ninstall-stamp.json\n");
    $g = 'git -C ' . escapeshellarg($w) . ' -c user.name=t -c user.email=t@t -c commit.gpgsign=false -c tag.gpgsign=false ';
    hermesTSh('git init -q ' . escapeshellarg($w));
    hermesTSh($g . 'add -A');
    hermesTSh($g . 'commit -q -m one');
    hermesTSh($g . 'tag v0.9.0');
    file_put_contents("$w/two.txt", "two\n");
    hermesTSh($g . 'add -A');
    hermesTSh($g . 'commit -q -m two');
    hermesTSh($g . 'tag v2026.9.24');
    hermesTSh($g . 'tag -a v0.10.0 -m release'); // annotated: ls-remote lists a peeled ^{} line too
    $release = hermesTSh($g . 'rev-parse HEAD');
    file_put_contents("$w/three.txt", "unreleased\n");
    hermesTSh($g . 'add -A');
    hermesTSh($g . 'commit -q -m unreleased');
    hermesTSh('git clone -q --bare ' . escapeshellarg($w) . ' ' . escapeshellarg("$t/remote.git"));
    $root = "$t/pikkumies";
    mkdir($root, 0700);
    return ['t' => $t, 'root' => $root, 'repo' => 'file://' . "$t/remote.git", 'bin' => $bin, 'log' => $log, 'release' => $release, 'work' => $w, 'home' => "$t/home"];
}

/** Run $fn with HermesInstall pointed at the world, fakes first on PATH, and the seams reset afterwards. */
function hermesTIn(array $w, callable $fn, array $env = [])
{
    HermesInstall::$root = $w['root'];
    HermesInstall::$repo = $w['repo'];
    try {
        return testWithEnv(array_merge(['PATH' => $w['bin'] . ':' . getenv('PATH'), 'PK_FAKE_LOG' => $w['log'], 'PIKKUMIES_SKIP_HERMES' => null, 'HOME' => $w['home'], 'HERMES_RUNTIME_DIR' => null], $env), $fn);
    } finally {
        HermesInstall::$root = null;
        HermesInstall::$repo = null;
        CronTick::$setprivPresentOverride = null;
        CronTick::$mightEscalateOverride = null;
    }
}

/** Wait (up to 30 s) until the background build wrote the ready marker. */
function hermesTWaitReady(array $w)
{
    for ($i = 0; $i < 300; $i++) {
        clearstatcache();
        if (is_file($w['root'] . '/include/.hermes-ready')) {
            return;
        }
        usleep(100000);
    }
    throw new Exception('build never finished: ' . @file_get_contents($w['root'] . '/local/install.log'));
}

/** A complete install without a build: tree with .git, the marker, the pm launcher. */
function hermesTFakeComplete(array $w)
{
    $tree = $w['root'] . '/hermes';
    mkdir("$tree/.git", 0700, true);
    mkdir("$tree/.hermes/bin", 0700, true);
    copy($w['bin'] . '/fake-hermes', "$tree/.hermes/bin/hermes");
    chmod("$tree/.hermes/bin/hermes", 0755);
    mkdir($w['root'] . '/include', 0700, true);
    touch($w['root'] . '/include/.hermes-ready');
    return $tree;
}

return [
    'hermes release: the newest stable vX.Y.Z tag, never a date tag, a canary or a peeled line' => function () {
        $sha = str_repeat('a', 40);
        $out = "$sha\trefs/tags/v2026.9.24\n$sha\trefs/tags/v0.21.7\n$sha\trefs/tags/v0.22.1\n$sha\trefs/tags/v0.22.10^{}\n"
            . "$sha\trefs/tags/v0.22.2\n$sha\trefs/tags/v0.22.3+canary.20261016T065950Z\n$sha\trefs/tags/v0.22.9-rc1\n$sha\trefs/tags/0.99.0\n$sha\trefs/heads/main\n";
        check(HermesInstall::latestTag($out) === 'v0.22.2', 'got ' . HermesInstall::latestTag($out));
        check(HermesInstall::latestTag("$sha\trefs/tags/v0.9.0\r\n$sha\trefs/tags/v0.10.0\r\n") === 'v0.10.0', 'version order, not string order');
        check(HermesInstall::latestTag('') === '' && HermesInstall::latestTag("fatal: unable to access\n") === '', 'no tags must give no tag');
    },
    'hermes bootstrap: the machine target and the uv/python pins are read from pm/lock.json like setup-hermes.sh' => function () {
        check(HermesInstall::target('Linux', 'x86_64') === 'linux-x64' && HermesInstall::target('Linux', 'aarch64') === 'linux-arm64'
            && HermesInstall::target('Darwin', 'arm64') === 'darwin-arm64', 'targets');
        check(HermesInstall::target('FreeBSD', 'x86_64') === '' && HermesInstall::target('Linux', 'i686') === '', 'no build: no target');
        $sha = str_repeat('ab', 32);
        $lock = ['packages' => ['python' => ['version' => '3.14.7+20260901'], 'uv' => ['version' => '0.12.3', 'artifacts' => [
            'linux-x64' => ['url' => 'https://github.com/astral-sh/uv/releases/download/0.12.3/uv-x86_64-unknown-linux-gnu.tar.gz', 'sha256' => $sha],
            'linux-arm64' => ['url' => 'http://example.invalid/uv.tar.gz', 'sha256' => $sha],
            'darwin-x64' => ['url' => 'https://x/uv.tar.gz', 'sha256' => 'abc'],
        ]]]];
        check(HermesInstall::uvPin($lock, 'linux-x64') === ['version' => '0.12.3', 'url' => 'https://github.com/astral-sh/uv/releases/download/0.12.3/uv-x86_64-unknown-linux-gnu.tar.gz', 'sha256' => $sha], 'uv pin');
        check(HermesInstall::uvPin($lock, 'linux-arm64') === null && HermesInstall::uvPin($lock, 'darwin-x64') === null && HermesInstall::uvPin($lock, 'darwin-arm64') === null, 'plain http, a bad sha256 or no artifact must not be a pin');
        check(HermesInstall::pythonPin($lock) === '3.14' && HermesInstall::pythonPin(['packages' => ['python' => ['version' => '3.13']]]) === '3.13' && HermesInstall::pythonPin([]) === '', 'python pin');
        $p = HermesInstall::buildProcess('v0.22.2', false);
        check($p[0] === PHP_BINARY && $p[1] === '-r' && strpos($p[2], 'require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/HermesInstall.php', true)) === 0
            && strpos($p[2], "HermesInstall::build('v0.22.2', false)") !== false, 'background process: ' . json_encode($p));
        $env = HermesInstall::bootstrapEnv();
        $home = Config::root() . '/local/hermes';
        check($env === ['HERMES_HOME' => $home, 'UV_NO_CONFIG' => '1', 'UV_PYTHON_INSTALL_DIR' => "$home/bootstrap-python", 'UV_CACHE_DIR' => "$home/cache/uv"], 'everything the bootstrap writes stays under HERMES_HOME: ' . json_encode($env));
    },
    'hermes install: an unmodified checkout of the newest release, bootstrapped by pinned uv + pm.cli install, nothing outside pikkumies/' => function () {
        $w = hermesTWorld();
        try {
            hermesTIn($w, function () use ($w) {
                $msg = HermesInstall::ensure();
                check($msg === 'building v0.10.0 in the background (log: ' . Config::shown($w['root'] . '/local/install.log') . ')', 'ensure: ' . $msg);
                hermesTWaitReady($w);
                $tree = $w['root'] . '/hermes';
                $home = $w['root'] . '/local/hermes';
                $target = HermesInstall::target();
                check(is_dir("$tree/.git") && HermesInstall::head() === $w['release'], 'the v0.10.0 release must be checked out');
                foreach (['tests', 'website', 'apps'] as $d) {
                    check(is_file("$tree/$d/keep.txt"), "$d/ was stripped: vanilla keeps the whole tree");
                }
                check(is_executable("$home/tools/uv-0.0.1-$target/uv"), 'the pinned uv must be staged, flattened, in the pm store slot');
                check(!glob("$home/tools/.bootstrap-*"), 'the staging directory must be removed');
                $log = (string) file_get_contents($w['log']);
                $cfg = " | PYDIR=$home/bootstrap-python | CACHE=$home/cache/uv | NOCONFIG=1";
                check(strpos($log, "uv python install --no-bin --no-registry 3.14$cfg\n") !== false && strpos($log, "uv python find --managed-python 3.14$cfg\n") !== false, 'uv bootstrap steps: ' . $log);
                check(substr_count($log, "pm -m pm.cli install --without agent-browser --without cua-driver | cwd=$tree | HERMES_HOME=$home | RUNTIME_DIR=false\n") === 1, 'pm.cli install once, in the tree, our HERMES_HOME, no inherited HERMES_RUNTIME_DIR: ' . $log);
                check(strpos($log, 'SETUP SCRIPT RAN') === false, 'upstream setup-hermes.sh must never run');
                check(!file_exists("$tree/venv") && is_file($w['root'] . '/include/.hermes-ready') && !file_exists("$tree/.hermes-ready"), 'no venv; the marker lives outside the tree');
                check(HermesInstall::isComplete() && HermesInstall::bin() === "$tree/.hermes/bin/hermes" && HermesInstall::sourceDir() === $tree, 'complete install not recognised');
                check(array_values(array_diff((array) scandir($w['home']), ['.', '..'])) === [], 'nothing may be written to HOME (rc files, ~/.local/bin): ' . json_encode(scandir($w['home'])));
                $st = HermesInstall::status();
                check($st['rev'] === 'v0.10.0' && $st['clean'] === true && $st['official'] === true && $st['sha'] === substr($w['release'], 0, 10), 'status: ' . json_encode($st));
                check(HermesInstall::origin() === $w['repo'], 'origin: ' . HermesInstall::origin());
                // The shallow tag clone can move to a newer release the way an update does (fetch + detach).
                $g = 'git -C ' . escapeshellarg($w['work']) . ' -c user.name=t -c user.email=t@t -c tag.gpgsign=false ';
                hermesTSh($g . 'tag v0.11.0');
                hermesTSh('git -C ' . escapeshellarg($w['work']) . ' push -q ' . escapeshellarg($w['t'] . '/remote.git') . ' v0.11.0');
                hermesTSh('git -C ' . escapeshellarg($tree) . ' fetch -q --depth 1 origin tag v0.11.0');
                hermesTSh('git -C ' . escapeshellarg($tree) . ' -c advice.detachedHead=false checkout -q --detach v0.11.0');
                check(HermesInstall::head() === hermesTSh($g . 'rev-parse v0.11.0^{commit}'), 'the shallow release clone could not move to a newer tag');
                check(HermesInstall::isComplete(), 'an update must not make the install look incomplete (marker outside the tree)');
            }, ['HERMES_RUNTIME_DIR' => '/elsewhere/tools']);
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes install: a uv download whose sha256 differs from pm/lock.json stops the build; nothing is staged or run' => function () {
        $w = hermesTWorld(true);
        try {
            hermesTIn($w, function () use ($w) {
                ob_start();
                $code = HermesInstall::build('v0.10.0');
                $out = (string) ob_get_clean();
                $target = HermesInstall::target();
                check($code === 1 && strpos($out, 'hermes v0.10.0: failed: sha256 mismatch for uv (got ' . hash_file('sha256', $w['t'] . '/uv.tar.gz') . ', pinned ' . str_repeat('0', 64) . ')') !== false, 'build: ' . $out);
                check(!file_exists($w['root'] . "/local/hermes/tools/uv-0.0.1-$target") && !glob($w['root'] . '/local/hermes/tools/.bootstrap-*'), 'nothing may be staged');
                check(!is_file($w['log']) && !is_file($w['root'] . '/include/.hermes-ready') && !HermesInstall::isComplete(), 'no uv, no pm, no marker');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes install: hermes.lazyInstalls keeps the optional tools; a failing pm install leaves no marker; the staged uv is reused' => function () {
        $w = hermesTWorld();
        try {
            hermesTIn($w, function () use ($w) {
                ob_start();
                $code = HermesInstall::build('v0.10.0', true);
                ob_end_clean();
                check($code === 0 && preg_match('/^pm -m pm\.cli install \| cwd=/m', (string) file_get_contents($w['log'])) === 1, 'optional tools: no --without');
                rename($w['root'] . '/hermes', $w['t'] . '/first');
                unlink($w['t'] . '/uv.tar.gz'); // a second download would now fail
                file_put_contents($w['log'], '');
                hermesTFake($w['bin'] . '/boot-python', 'exit(1);');
                ob_start();
                $code = HermesInstall::build('v0.10.0');
                $out = (string) ob_get_clean();
                check($code === 1 && strpos($out, 'hermes v0.10.0: failed: pm install') !== false && !is_file($w['root'] . '/include/.hermes-ready'), 'pm failure: ' . $out);
                check(strpos((string) file_get_contents($w['log']), 'uv python install') !== false, 'the staged uv must be reused without a download: ' . $out);
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes ensure: a complete install is left alone, no git, no uv, no pm' => function () {
        $w = hermesTWorld();
        try {
            hermesTFake($w['bin'] . '/git', 'file_put_contents(getenv("PK_FAKE_LOG"), "git called\n", FILE_APPEND); exit(1);');
            hermesTFakeComplete($w);
            hermesTIn($w, function () use ($w) {
                $msg = HermesInstall::ensure(true);
                check($msg === 'installed (pikkumies/hermes; updates: `pikkumies run update`)', 'ensure: ' . $msg);
                check(!is_file($w['log']), 'a complete install must run nothing: ' . @file_get_contents($w['log']));
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes ensure: unattended never touches an incomplete tree, and refuses an unattended build on root without setpriv' => function () {
        $w = hermesTWorld();
        try {
            $tree = $w['root'] . '/hermes';
            mkdir("$tree/.git", 0700, true);
            file_put_contents("$tree/mine.patch", "user work\n");
            hermesTIn($w, function () use ($w, $tree) {
                $msg = HermesInstall::ensure(true);
                check(strpos($msg, 'not complete: pikkumies/hermes is left from a build that failed or stopped') === 0 && strpos($msg, 'run `pikkumies install`') !== false, 'ensure: ' . $msg);
                check(file_get_contents("$tree/mine.patch") === "user work\n" && !glob($w['root'] . '/hermes.broken-*'), 'unattended must not move or delete the tree');
            });
            rename($tree, $w['t'] . '/gone');
            hermesTIn($w, function () use ($w) {
                CronTick::$setprivPresentOverride = false;
                $msg = HermesInstall::ensure(true, true);
                check($msg === 'not installed: root is available and setpriv is missing, so no unattended build (ADR-0025); run `pikkumies install`', 'ensure: ' . $msg);
                usleep(300000);
                check(!file_exists($w['root'] . '/hermes') && !file_exists($w['root'] . '/local/install.log'), 'nothing may be started');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes ensure: install moves an incomplete tree aside (never deletes it) and builds again; not while Hermes runs from it' => function () {
        $w = hermesTWorld();
        try {
            $tree = $w['root'] . '/hermes';
            mkdir("$tree/.git", 0700, true);
            file_put_contents("$tree/mine.patch", "user work\n");
            hermesTIn($w, function () use ($w, $tree) {
                $session = HermesInstall::lock(false);
                $msg = HermesInstall::ensure();
                fclose($session);
                check(strpos($msg, 'not complete, and a Hermes process still runs from pikkumies/hermes') === 0, 'in use: ' . $msg);
                check(is_file("$tree/mine.patch") && !glob($w['root'] . '/hermes.broken-*'), 'an in-use tree must stay where it is');
                $msg = HermesInstall::ensure();
                $aside = glob($w['root'] . '/hermes.broken-*');
                check(count($aside) === 1 && preg_match('/^hermes\.broken-\d{8}T\d{6}Z$/', basename($aside[0])) === 1, 'aside: ' . json_encode($aside));
                check(file_get_contents($aside[0] . '/mine.patch') === "user work\n", 'the moved tree lost the user file');
                check($msg === 'moved the incomplete tree to ' . basename($aside[0]) . ' (remove it when it is not needed); building v0.10.0 in the background (log: ' . Config::shown($w['root'] . '/local/install.log') . ')', 'ensure: ' . $msg);
                hermesTWaitReady($w);
                check(HermesInstall::isComplete() && !is_file("$tree/mine.patch"), 'the new build must be a fresh clone');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes ensure: a build already running is left alone, nothing else is started' => function () {
        $w = hermesTWorld();
        try {
            mkdir($w['root'] . '/include', 0700, true);
            $h = fopen($w['root'] . '/include/.hermes-build.lock', 'c');
            flock($h, LOCK_EX);
            try {
                hermesTIn($w, function () use ($w) {
                    check(HermesInstall::building(), 'a held build lock must read as building');
                    $msg = HermesInstall::ensure();
                    check($msg === 'building in the background (log: ' . Config::shown($w['root'] . '/local/install.log') . ')', 'ensure: ' . $msg);
                    usleep(300000);
                    check(!file_exists($w['root'] . '/hermes') && !is_file($w['log']), 'a second build was started');
                });
            } finally {
                fclose($h);
            }
            hermesTIn($w, function () {
                check(!HermesInstall::building(), 'a released lock must not read as building');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes lock: sessions share it; an update needs it alone; it frees when the handle closes' => function () {
        $t = testTempDir('pk-hermes-lock');
        HermesInstall::$root = $t;
        try {
            $a = HermesInstall::lock(false);
            $b = HermesInstall::lock(false);
            check(is_resource($a) && is_resource($b), 'two sessions must run at once');
            check(HermesInstall::lock(true) === null, 'an update must not start while a session runs');
            fclose($a);
            fclose($b);
            $u = HermesInstall::lock(true);
            check(is_resource($u), 'an update must get the lock once sessions ended');
            check(HermesInstall::lock(false) === null && HermesInstall::lock(true) === null, 'nothing else may start during an update');
            fclose($u);
            check(is_resource(HermesInstall::lock(false)), 'the lock must free when the update ends');
            check(is_file("$t/local/hermes.lock"), 'the lock file lives in local/');
        } finally {
            HermesInstall::$root = null;
            testRmTree($t);
        }
    },
    'hermes key check: names each config key whose text is gone from the installed source' => function () {
        $t = testTempDir('pk-hermes-keys');
        try {
            foreach (HermesInstall::CONFIG_FACTS as $fact) {
                @mkdir(dirname("$t/{$fact[0]}"), 0700, true);
                file_put_contents("$t/{$fact[0]}", (is_file("$t/{$fact[0]}") ? file_get_contents("$t/{$fact[0]}") : '') . $fact[1] . "\n");
            }
            check(HermesInstall::missingConfigKeys($t) === [], 'all present: ' . json_encode(HermesInstall::missingConfigKeys($t)));
            $f = "$t/hermes_cli/config_defaults.py";
            file_put_contents($f, str_replace('"adopt_external_logins":', '"adopt_logins":', file_get_contents($f)));
            unlink("$t/hermes_cli/_launchers.py");
            check(HermesInstall::missingConfigKeys($t) === ['auth.adopt_external_logins', 'cli.expose_on_path'], 'got ' . json_encode(HermesInstall::missingConfigKeys($t)));
        } finally {
            testRmTree($t);
        }
    },
    'hermes key check: every top-level key HermesConfig writes is in CONFIG_FACTS' => function () {
        $y = HermesConfig::renderYaml(['endpoint' => 'e', 'model' => 'm', 'key' => 'k'], ['/a'], ['pre_tool_call' => ['command' => 'x', 'failClosed' => true, 'timeout' => 5]], ['s'])
            . HermesConfig::sessionsYaml(['sessions' => ['retentionDays' => 3]]);
        preg_match_all('/^([a-z_]+):/m', $y, $m);
        $covered = [];
        foreach (array_keys(HermesInstall::CONFIG_FACTS) as $key) {
            $covered[explode('.', $key)[0]] = true;
        }
        $missing = array_diff(array_unique($m[1]), array_keys($covered));
        check(count($m[1]) >= 15 && !$missing, 'HermesConfig writes keys the update check does not cover: ' . implode(', ', $missing));
    },
    'hermes origin: only the official repository counts' => function () {
        foreach (['https://github.com/NousResearch/hermes-agent.git', 'https://github.com/NousResearch/hermes-agent', 'git@github.com:NousResearch/hermes-agent.git', 'ssh://git@github.com/nousresearch/hermes-agent/'] as $ok) {
            check(HermesInstall::originOfficial($ok), "$ok must count as official");
        }
        foreach (['https://github.com/someone/hermes-agent.git', 'https://github.com/NousResearch/hermes-agent.git.evil', 'http://github.com/NousResearch/hermes-agent.git', 'https://github.com.evil/NousResearch/hermes-agent', '/home/u/hermes', ''] as $bad) {
            check(!HermesInstall::originOfficial($bad), "$bad must not count as official");
        }
    },
    'hermes start check: a moved checkout is verified once (keys + delete guard); a failing one is refused and not recorded' => function () {
        $w = hermesTWorld();
        try {
            hermesTIn($w, function () use ($w) {
                HermesInstall::ensure();
                hermesTWaitReady($w);
                $tree = $w['root'] . '/hermes';
                check(HermesInstall::verifiedCommit() === '', 'nothing verified yet');
                check(HermesInstall::checkBeforeStart() === [], 'a good checkout must be accepted: ' . json_encode(HermesInstall::checkBeforeStart()));
                check(HermesInstall::verifiedCommit() === $w['release'], 'the accepted commit must be recorded');
                $log = (string) file_get_contents($w['log']);
                check(strpos($log, 'hermes hooks test pre_tool_call --for-tool terminal --payload-file ') !== false && strpos($log, 'payload {"args":{"command":"rm -rf /tmp/pikkumies-hook-check"}}') !== false, 'the guard must be tested with rm -rf: ' . $log);
                check(!glob($w['root'] . '/local/hook-check-*'), 'the payload file must be removed');
                // Same commit: no second run, even if the guard would now fail.
                testWithEnv(['PK_FAKE_HOOK_OUT' => "      parsed: <none>\n"], function () use ($w) {
                    file_put_contents($w['log'], '');
                    check(HermesInstall::checkBeforeStart() === [] && (string) file_get_contents($w['log']) === '', 'an unchanged checkout must not be re-checked');
                });
                // The checkout moves (an update or /update): checked again before anything starts.
                file_put_contents("$tree/local-edit.txt", "x\n");
                hermesTSh('git -C ' . escapeshellarg($tree) . ' -c user.name=t -c user.email=t@t -c commit.gpgsign=false add -A');
                hermesTSh('git -C ' . escapeshellarg($tree) . ' -c user.name=t -c user.email=t@t -c commit.gpgsign=false commit -q -m moved');
                $moved = HermesInstall::head();
                testWithEnv(['PK_FAKE_HOOK_OUT' => "      parsed: <none — hook contributed nothing to the dispatcher>\n"], function () use ($w) {
                    $p = HermesInstall::checkBeforeStart();
                    check($p === ['the delete guard did not block rm -rf in `hermes hooks test` (exit 0)'], 'got ' . json_encode($p));
                    check(HermesInstall::verifiedCommit() === $w['release'], 'a failing commit must not be recorded');
                });
                $f = "$tree/hermes_cli/config_defaults.py";
                file_put_contents($f, str_replace('"auto_prune":', '"prune":', file_get_contents($f)));
                check(HermesInstall::checkBeforeStart() === ['Hermes no longer has the config key sessions.auto_prune'], 'a missing key must refuse the start');
                file_put_contents($f, str_replace('"prune":', '"auto_prune":', file_get_contents($f)));
                check(HermesInstall::checkBeforeStart() === [] && HermesInstall::verifiedCommit() === $moved, 'a fixed checkout is accepted and recorded');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes bin: the launcher pm wrote into the tree, and only once the install is complete' => function () {
        $w = hermesTWorld();
        try {
            hermesTIn($w, function () use ($w) {
                $tree = hermesTFakeComplete($w);
                check(HermesInstall::bin() === "$tree/.hermes/bin/hermes", 'launcher: ' . HermesInstall::bin());
                rename("$tree/.hermes", "$tree/.hermes-gone");
                mkdir("$tree/venv/bin", 0700, true);
                copy($w['bin'] . '/fake-hermes', "$tree/venv/bin/hermes");
                chmod("$tree/venv/bin/hermes", 0755);
                check(HermesInstall::bin() === '', 'a venv hermes is not what pm runs: ' . HermesInstall::bin());
                rename("$tree/.hermes-gone", "$tree/.hermes");
                unlink($w['root'] . '/include/.hermes-ready');
                check(HermesInstall::bin() === '' && HermesInstall::sourceDir() === '', 'no marker: not installed');
            });
        } finally {
            testRmTree($w['t']);
        }
    },
    'hermes PATH launchers: lists ~/.local/bin entries that run this tree, nothing else' => function () {
        $t = testTempDir('pk-hermes-path');
        HermesInstall::$root = "$t/pk";
        try {
            mkdir("$t/home/.local/bin", 0700, true);
            file_put_contents("$t/home/.local/bin/hermes", "#!/bin/sh\nexec python3 -c 'sys.path.insert(0, \"$t/pk/hermes\")'\n");
            symlink("$t/pk/hermes/venv/bin/hermes-acp", "$t/home/.local/bin/hermes-acp");
            file_put_contents("$t/home/.local/bin/hermes-agent", "#!/bin/sh\nexec /opt/other/hermes \"\$@\"\n");
            check(HermesInstall::pathLaunchers("$t/home") === ["$t/home/.local/bin/hermes", "$t/home/.local/bin/hermes-acp"], 'got ' . json_encode(HermesInstall::pathLaunchers("$t/home")));
            check(HermesInstall::pathLaunchers("$t/nohome") === [], 'no ~/.local/bin: nothing');
        } finally {
            HermesInstall::$root = null;
            testRmTree($t);
        }
    },
    'hermes start check: the shipped delete guard answers the rm -rf check with the block text verify() expects' => function () {
        // Hermes hands the hook tool_input from the payload's args (agent/shell_hooks.py), so this is what the guard sees.
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
            json_encode(['hook_event_name' => 'pre_tool_call', 'tool_name' => 'terminal', 'tool_input' => ['command' => 'rm -rf /tmp/pikkumies-hook-check'], 'session_id' => 'test-session']));
        $answer = json_decode(trim($out), true);
        check($code === 0 && is_array($answer) && $answer['action'] === 'block' && strpos($answer['message'], HermesInstall::GUARD_BLOCK) === 0, 'guard answer: ' . $out);
    },
    'hermes setpriv: run update and the unattended build use the cron-tick rule' => function () {
        CronTick::$setprivPresentOverride = true;
        check(CronTick::wrap(['/h/hermes', 'update', '--yes'], true) === ['setpriv', '--no-new-privs', '/h/hermes', 'update', '--yes'], 'wrapped');
        CronTick::$setprivPresentOverride = false;
        CronTick::$mightEscalateOverride = false;
        check(CronTick::wrap(['/h/hermes', 'update'], false) === ['/h/hermes', 'update'], 'bare when there is nothing to drop');
        check(CronTick::wrap(['/h/hermes', 'update'], true) === [], 'refused on root without setpriv');
        CronTick::$setprivPresentOverride = null;
        CronTick::$mightEscalateOverride = null;
    },
    'hermes no leftovers: no version pin, no old build paths, no update refusal, separate update documented' => function () {
        $root = dirname(__DIR__);
        $h = Config::defaults()['hermes'];
        check($h === ['lazyInstalls' => false], 'hermes defaults: ' . json_encode($h));
        foreach (array_merge((array) glob("$root/pikkumies/lib/*.php"), ["$root/pikkumies/bin/pikkumies"]) as $f) {
            $text = (string) file_get_contents($f);
            check(!preg_match("/\\['hermes'\\]\\['(version|sha256)'\\]|include\\/hermes\\/|hermes-legacy|currentTag\\(|HermesInstall::cleanup/", $text), basename($f) . ' still has the pinned-build code');
        }
        $hi = (string) file_get_contents("$root/pikkumies/lib/HermesInstall.php");
        check(strpos($hi, 'PY_STANDALONE') === false && strpos($hi, 'function python(') === false && strpos($hi, 'venv/bin/pip') === false && strpos($hi, 'setup-hermes.sh') !== false, 'no own Python or pip install: upstream pm owns Python and dependencies');
        $bin = (string) file_get_contents("$root/pikkumies/bin/pikkumies");
        check(strpos($bin, 'Hermes is pinned by Pikkumies') === false, 'the run update refusal is still there');
        check(strpos($bin, 'HermesInstall::lock($isUpdate)') !== false && strpos($bin, 'CronTick::wrap(array_merge([hermesBin()], $passArgs)') !== false
            && strpos($bin, 'HermesInstall::originOfficial(') !== false && strpos($bin, 'HermesInstall::checkBeforeStart()') !== false, 'run update: lock, setpriv, origin, start check');
        $post = substr($bin, strpos($bin, "case 'post-update':"), 900);
        check(strpos($post, 'HermesInstall::ensure(true,') !== false, 'post-update only builds a missing tree (unattended)');
        $skill = (string) file_get_contents("$root/pikkumies/tools/system/pikkumies-update/skill.md");
        check(strpos($skill, 'Never run `hermes update`, the `/update` command') === false && strpos($skill, '`pikkumies run update`') !== false && strpos($skill, 'installer script') !== false,
            'update skill: allow the Hermes update through pikkumies run update, keep the installer ban');
        $ignore = (string) file_get_contents("$root/.gitignore");
        check(strpos($ignore, "pikkumies/hermes/\n") !== false && strpos($ignore, "pikkumies/hermes.broken-*/\n") !== false && strpos($ignore, "pikkumies/include/.hermes-ready\n") !== false, '.gitignore must cover the checkout, moved-aside trees and the marker');
    },
    'hermes: a session with no runtime starts the build instead of only saying it is missing (a preinstalled account, ADR-0033 addendum)' => function () {
        if (HermesInstall::bin() !== '') {
            skip('Hermes is installed in this checkout, so a session would start it; checked in CI');
        }
        $home = testTempDir('pk-run-nobuild');
        $cmd = 'HOME=' . escapeshellarg($home) . ' PIKKUMIES_SKIP_HERMES=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/pikkumies/bin/pikkumies') . ' run < /dev/null 2>&1';
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        check($code === 1, "expected exit 1, got $code: $text");
        check(strpos($text, 'The agent runtime is not ready yet: skipped (PIKKUMIES_SKIP_HERMES=1)') !== false, 'run did not go through HermesInstall::ensure: ' . $text);
        testRmTree($home);
    },
];
