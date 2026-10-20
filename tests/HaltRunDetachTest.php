<?php
/**
 * lib/HaltRun.php detachWrapUp (ADR-0103): with PIKKUMIES_WRAPUP_DETACH=1 the one wrap-up resume starts in its own session,
 * bounded by timeout, logged to a 0600 file, and the run returns without waiting; without the variable nothing changes.
 */
require_once __DIR__ . '/haltRunSupport.php';

if (!function_exists('haltRunDetachRun')) {
    /** Runs detachWrapUp in a subprocess against $tree with $env; returns [exit code, output, seconds]. */
    function haltRunDetachRun($tree, $fake, array $env)
    {
        $script = "$tree/detach-" . mt_rand() . '.php';
        file_put_contents($script, "<?php\nrequire " . var_export("$tree/pikkumies/lib/HaltRun.php", true) . ";\n"
            . 'echo HaltRun::detachWrapUp(' . var_export($fake, true) . ", ['chat', '--resume=sess9', '--query=save a lesson'], 'sess9') ? 'detached' : 'inline';\n");
        $t = microtime(true);
        $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($p), $out, microtime(true) - $t];
    }
}

return [
    'HaltRun: an unattended run hands the wrap-up to its own session and returns at once' => function () {
        $tree = haltRunTree(1);
        try {
            $fake = "$tree/fake-hermes";
            file_put_contents($fake, "#!/bin/sh\nsleep 2\nprintf '%s\\n' \"\$*\" \"oneshot=\$PIKKUMIES_ONESHOT detach=\${PIKKUMIES_WRAPUP_DETACH:-unset}\" > " . escapeshellarg("$tree/ran") . "\n");
            chmod($fake, 0700);
            list($code, $out, $secs) = haltRunDetachRun($tree, $fake, ['PIKKUMIES_WRAPUP_DETACH' => '1']);
            check($code === 0 && trim($out) === 'detached' && $secs < 1.5, "the wrap-up was not detached at once ($secs s): $out");
            $log = "$tree/pikkumies/local/logs/halt-guard/wrapup-" . sha1('sess9') . '.log';
            check(is_file($log) && (fileperms($log) & 0777) === 0600, 'the wrap-up log is missing or not 0600');
            $decisions = (string) @file_get_contents("$tree/pikkumies/local/logs/halt-guard/" . gmdate('Ymd') . '.jsonl');
            check(strpos($decisions, '"verdict":"wrapup-detached"') !== false, 'the detach was not logged: ' . $decisions);
            $deadline = microtime(true) + 8;
            while (!is_file("$tree/ran") && microtime(true) < $deadline) {
                usleep(100000);
            }
            $ran = (string) @file_get_contents("$tree/ran");
            check(strpos($ran, 'chat --resume=sess9 --query=save a lesson') !== false && strpos($ran, 'oneshot=1 detach=unset') !== false,
                'the detached resume did not run as the same resume, one-shot, without the variable: ' . $ran);
        } finally {
            testRmTree($tree);
        }
    },
    'HaltRun: without the variable the wrap-up stays inline' => function () {
        $tree = haltRunTree(1);
        try {
            list($code, $out) = haltRunDetachRun($tree, '/bin/true', ['PIKKUMIES_WRAPUP_DETACH' => '0']);
            check(trim($out) === 'inline' && !is_dir("$tree/pikkumies/local/logs/halt-guard"), 'the wrap-up was detached without the variable: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
];
