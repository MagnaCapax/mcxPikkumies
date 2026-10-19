<?php
/**
 * lib/HermesWebUi.php (ADR-0033 addendum 2026-10-19): `pikkumies run dashboard` on a checkout without the web page
 * builds it first with Hermes' own explicit builder, alone on the tree, after saying it takes minutes. A fake hermes
 * stands in for the builder.
 */

/** A temp pikkumies/ root with a complete-looking checkout and a fake hermes that logs argv and env. */
function webUiWorld($makesPage = true)
{
    $t = testTempDir('pk-webui');
    mkdir("$t/hermes/.git", 0700, true);
    mkdir("$t/include", 0700);
    mkdir("$t/local", 0700);
    touch("$t/include/.hermes-ready");
    $bin = "$t/fake-hermes";
    file_put_contents($bin, '#!' . PHP_BINARY . "\n<?php\n"
        . 'file_put_contents(' . var_export("$t/fake.log", true) . ', implode(" ", array_slice($argv, 1)) . " | home=" . getenv("HERMES_HOME") . " managed=" . getenv("HERMES_MANAGED_DIR") . " runtime=" . var_export(getenv("HERMES_RUNTIME_DIR"), true) . "\n", FILE_APPEND);' . "\n"
        . 'echo "building\n";' . "\n"
        . ($makesPage ? '@mkdir(' . var_export("$t/hermes/hermes_cli/web_dist", true) . ', 0700, true); file_put_contents(' . var_export("$t/hermes/hermes_cli/web_dist/index.html", true) . ', "<html>");' . "\n" : '')
        . "exit(0);\n");
    chmod($bin, 0755);
    return ['t' => $t, 'bin' => $bin, 'log' => "$t/fake.log"];
}

/** Run $fn with the world as HermesInstall's root, no setpriv, no escalation, HERMES_RUNTIME_DIR set (to prove it is dropped). */
function webUiIn(array $w, callable $fn)
{
    HermesInstall::$root = $w['t'];
    CronTick::$setprivPresentOverride = false;
    CronTick::$mightEscalateOverride = false;
    try {
        return testWithEnv(['HERMES_RUNTIME_DIR' => '/elsewhere', 'HERMES_WEB_DIST' => null], $fn);
    } finally {
        HermesInstall::$root = null;
        CronTick::$setprivPresentOverride = null;
        CronTick::$mightEscalateOverride = null;
        testRmTree($w['t']);
    }
}

/** HermesWebUi::build with its terminal output and Pikkumies' own lines captured: [result, said, output]. */
function webUiBuild(array $w, $root = false)
{
    $said = [];
    ob_start();
    $r = HermesWebUi::build($w['bin'], $root, function ($line) use (&$said, $w) {
        $said[] = [$line, is_file($w['log'])];
    });
    return [$r, $said, (string) ob_get_clean()];
}

return [
    'web ui: only a dashboard start on a checkout without the page builds; --stop, --status, --skip-build, register, help, serve and chat never do' => function () {
        $w = webUiWorld();
        webUiIn($w, function () use ($w) {
            check(HermesWebUi::needed(['dashboard']) && HermesWebUi::needed(['dashboard', '--host', '0.0.0.0', '--port', '45315']), 'a dashboard start without the page builds');
            foreach ([['dashboard', '--stop'], ['dashboard', '--status'], ['dashboard', '--skip-build'], ['dashboard', 'register'], ['dashboard', '--help'], ['serve'], ['chat'], []] as $args) {
                check(!HermesWebUi::needed($args), 'no build for ' . json_encode($args));
            }
            testWithEnv(['HERMES_WEB_DIST' => '/my/dist'], function () {
                check(!HermesWebUi::needed(['dashboard']), 'the user\'s own dist: Hermes checks it, nothing is built');
            });
            mkdir($w['t'] . '/hermes/hermes_cli/web_dist', 0700, true);
            touch($w['t'] . '/hermes/hermes_cli/web_dist/index.html');
            check(!HermesWebUi::needed(['dashboard']), 'the page is there: nothing to build');
        });
    },
    'web ui: the build is Hermes\' own explicit builder with Pikkumies\' Hermes environment, under setpriv; refused on a root-capable host without it' => function () {
        $w = webUiWorld();
        webUiIn($w, function () use ($w) {
            $argv = HermesWebUi::command('/h/bin/hermes', false);
            check($argv === ['env', '-u', 'HERMES_RUNTIME_DIR', 'HERMES_HOME=' . HermesConfig::home(), 'HERMES_MANAGED_DIR=' . HermesConfig::managedDir(), 'HERMES_GATEWAY_LOCK_DIR=' . HermesConfig::home() . '/gateway-locks',
                '/h/bin/hermes', '--run-module', 'hermes_cli.source_build', '--source', $w['t'] . '/hermes'], json_encode($argv));
            CronTick::$setprivPresentOverride = true;
            check(array_slice(HermesWebUi::command('/h/bin/hermes', true), 6, 3) === ['setpriv', '--no-new-privs', '/h/bin/hermes'], 'setpriv wraps the builder');
            CronTick::$setprivPresentOverride = false;
            check(HermesWebUi::command('/h/bin/hermes', true) === [], 'root available, no setpriv: no command');
            list($r, $said) = webUiBuild($w, true);
            check(strpos($r, 'setpriv is missing') !== false && $said === [] && !is_file($w['log']), 'refused before anything runs: ' . $r);
        });
    },
    'web ui: says it takes minutes before the build starts, runs it once, and frees the Hermes lock; the page there means success' => function () {
        $w = webUiWorld();
        webUiIn($w, function () use ($w) {
            list($r, $said, $out) = webUiBuild($w);
            check($r === '', 'built: ' . $r);
            check(isset($said[0]) && strpos($said[0][0], 'several minutes') !== false && $said[0][1] === false, 'the notice comes before the build: ' . json_encode($said));
            $log = (string) file_get_contents($w['log']);
            check(substr_count($log, "\n") === 1 && strpos($log, '--run-module hermes_cli.source_build --source ' . $w['t'] . '/hermes | home=' . HermesConfig::home() . ' managed=' . HermesConfig::managedDir() . ' runtime=false') === 0, 'log: ' . $log);
            check(strpos($out, 'building') !== false, 'the build\'s own output reaches the terminal');
            check(is_resource(HermesInstall::lock(true)), 'the exclusive lock is free again for the dashboard\'s start');
            check(!HermesWebUi::needed(['dashboard']), 'built once: the next start builds nothing');
        });
    },
    'web ui: a build that leaves no page stops the start; another Hermes session refuses the build without running it' => function () {
        $w = webUiWorld(false);
        webUiIn($w, function () use ($w) {
            list($r) = webUiBuild($w);
            check(strpos($r, 'did not produce the page (exit 0)') !== false, 'no page: ' . $r);
            unlink($w['log']);
            $shared = HermesInstall::lock(false);
            list($r, $said) = webUiBuild($w);
            fclose($shared);
            check(strpos($r, 'needs Hermes alone') !== false && $said === [] && !is_file($w['log']), 'a running session: no build: ' . $r);
        });
    },
    'launcher: the dashboard build runs before the shared Hermes lock, and a refused update says how to stop a dashboard' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $build = strpos($src, 'HermesWebUi::needed($passArgs)');
        $lock = strpos($src, '$hermesLock = HermesInstall::lock($isUpdate);');
        check($build !== false && $lock !== false && $build < $lock, 'the build must come before the session takes its shared lock');
        check(strpos($src, 'stop a dashboard with `pikkumies run dashboard --stop`') !== false, 'the update refusal names the dashboard');
    },
];
