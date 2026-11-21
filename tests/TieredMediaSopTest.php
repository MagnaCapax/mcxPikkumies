<?php
/**
 * The tiered media library SOP (ADR-0118): the shipped text stays parseable and reachable. Its commands are run on a
 * throwaway account elsewhere (they need FUSE and a storage box); here only what a text change can break: the marked
 * script blocks, the mover's syntax, the watchdog's shell syntax and the two skills that point to it.
 */
if (!function_exists('tieredSopText')) {
    function tieredSopText()
    {
        return (string) file_get_contents(Config::root() . '/doctrine/SOP/set-up-a-tiered-media-library.md');
    }

    function tieredSopBlock($name)
    {
        return preg_match('~<!-- script:' . $name . ' -->\n```\w+\n(.*?)\n```~s', tieredSopText(), $m) === 1 ? $m[1] : '';
    }

    /** Runs $argv (no shell) with $code on stdin; returns [exit code, output]. */
    function tieredSopRun(array $argv, $code)
    {
        $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [-1, 'proc_open failed'];
        }
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out];
    }
}

return [
    'tiered media SOP: every marked script block is there and the sections the layouts need are named' => function () {
        foreach (['remote', 'mount', 'mover', 'watchdog', 'cron', 'pull'] as $b) {
            check(tieredSopBlock($b) !== '', "script block $b is missing or has no fenced code after its marker");
        }
        $t = tieredSopText();
        foreach (['## 1. Decide the layout from the facts', '## 4. ', '## 5. Check before you say done', '## 6. Layout P', '## What did not work'] as $h) {
            check(strpos($t, $h) !== false, "missing section: $h");
        }
        check(strpos($t, 'never') !== false && strpos($t, '--allow-other') !== false, 'the no --allow-other rule must be stated');
    },
    'tiered media SOP: the mover is PHP that lints, fails closed on an unreadable quota and has no destructive default' => function () {
        $m = tieredSopBlock('mover');
        check(strpos($m, '<?php') === 0, 'the mover block must start with <?php');
        list($rc, $out) = tieredSopRun([PHP_BINARY, '-l'], $m);
        check($rc === 0, 'the mover does not lint: ' . trim($out));
        check(strpos($m, '--dry-run') !== false && strpos($m, "in_array('--run'") !== false, 'the mover must only move with --run');
        check(strpos($m, 'quota unreadable, nothing moved') !== false, 'the mover must stop when the quota cannot be read');
        check(strpos($m, '/tmp') === false, 'the mover must not write a list to /tmp, which every account shares');
        check(strpos($m, 'delete-empty-src-dirs') === false, 'the mover must not delete emptied series folders');
    },
    'tiered media SOP: the watchdog is valid sh with the lock descriptor closed for the daemon and a placeholder for the merge' => function () {
        $w = tieredSopBlock('watchdog');
        check(substr_count($w, '# cold') === 2 && substr_count($w, '# merge') === 1 && strpos($w, 'MERGE_COMMAND') !== false, 'the cold and merge markers and the placeholder must stay');
        check(strpos($w, '9>&-') !== false, 'the mount must not inherit the lock descriptor');
        list($rc, $out) = tieredSopRun(['/bin/sh', '-n'], str_replace('MERGE_COMMAND', 'true', $w));
        check($rc === 0, 'the watchdog does not parse: ' . trim($out));
        $c = tieredSopBlock('cron');
        check(substr_count($c, '# pikkumies-tier') >= 3 && substr_count($c, '>> ~/tier/cron.log 2>&1') === 2 && !preg_match('~\\|\\s*crontab\\s+-~', $c), 'the cron block must keep its tag, log the output of both lines and never pipe into crontab -');
    },
    'tiered media SOP: the rclone skill and the seedbox-apps skill point to it' => function () {
        foreach (['/tools/storage/rclone/skill.md', '/tools/seedbox/seedbox-apps/skill.md'] as $f) {
            check(strpos((string) file_get_contents(Config::root() . $f), 'doctrine/SOP/set-up-a-tiered-media-library.md') !== false, "$f must point to the SOP");
        }
    },
];
