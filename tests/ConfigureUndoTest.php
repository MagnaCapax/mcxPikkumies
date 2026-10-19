<?php
/**
 * ConfigureUndo (ADR-0059): `pikkumies configure undo`, the person's own shell command. The change log is the agent's
 * to write too, so a forged line must never become an asked change nobody saw: the command shows the change and asks y/N on
 * a terminal only for an asked change (refused without one); a free change is made without asking (amendment 2026-10-19). The person's own changes, an undo from inside an agent and one
 * under an overlay are refused. Everything is in a temp dir ($file, $log), never this checkout's local/.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureUndo.php';

if (!function_exists('cfgUndo')) {
    /** A temp dir whose config.php holds $local and whose log's last line is $line. Returns [dir, file, log]. */
    function cfgUndoDir(array $local, array $line)
    {
        $dir = testTempDir('pk-cfgundo');
        file_put_contents("$dir/config.php", '<?php return ' . var_export($local, true) . ";\n");
        @mkdir("$dir/state", 0700, true);
        file_put_contents("$dir/state/configure-changes.jsonl", json_encode($line) . "\n");
        return [$dir, "$dir/config.php", "$dir/state/configure-changes.jsonl"];
    }

    /** Runs the undo with $answers as the person's input lines; returns [exit code, what it printed]. */
    function cfgUndo($tty, array $answers, $file, $log, array $env = [])
    {
        $printed = '';
        $code = testWithEnv($env + ['PIKKUMIES_DEPTH' => null, 'PIKKUMIES_CONFIG' => null], function () use ($tty, &$answers, $file, $log, &$printed) {
            return ConfigureUndo::run($tty, function () use (&$answers) {
                return $answers ? array_shift($answers) : false;
            }, function ($t) use (&$printed) {
                $printed .= $t;
            }, $file, $log);
        });
        return [$code, $printed];
    }
}

return [
    'undo: a forged asked change (shorter history) is shown and refused without a terminal; nothing is pruned' => function () {
        // The reviewer's reproduction: a line the agent wrote makes the history 1 day, so Hermes would prune the rest.
        list($dir, $file, $log) = cfgUndoDir(['sessions' => ['retentionDays' => 0]], ['key' => 'sessions.retentionDays', 'old' => 1, 'new' => 0]);
        try {
            list($code, $out) = cfgUndo(false, [], $file, $log);
            check($code === 1 && strpos($out, 'undo: sessions.retentionDays 0 -> 1 (approve)') !== false && strpos($out, 'no terminal') !== false, "no tty: $code $out");
            check((require $file)['sessions']['retentionDays'] === 0, 'the forged change was made');
            list($code, $out) = cfgUndo(true, ["n\n"], $file, $log);
            check($code === 1 && strpos($out, 'Not changed') !== false && (require $file)['sessions']['retentionDays'] === 0, "answered n: $out");
            list($code, $out) = cfgUndo(true, [], $file, $log);
            check($code === 1 && (require $file)['sessions']['retentionDays'] === 0, "end of input is no: $out");
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log);
            check($code === 0 && (require $file)['sessions']['retentionDays'] === 1, "answered y on the terminal: $code $out");
        } finally {
            testRmTree($dir);
        }
    },
    'undo: a forged cron.disabled true -> false is asked too; a free change is made without a terminal' => function () {
        list($dir, $file, $log) = cfgUndoDir(['cron' => ['disabled' => true]], ['key' => 'cron.disabled', 'old' => false, 'new' => true]);
        try {
            list($code, $out) = cfgUndo(false, [], $file, $log);
            check($code === 1 && (require $file)['cron']['disabled'] === true, "cron without a terminal: $out");
            file_put_contents($file, '<?php return ' . var_export(['lowBalance' => ['disabled' => true]], true) . ";\n");
            file_put_contents($log, json_encode(['key' => 'lowBalance.disabled', 'old' => false, 'new' => true]) . "\n");
            list($code, $out) = cfgUndo(false, [], $file, $log);
            check($code === 0 && (require $file)['lowBalance']['disabled'] === false && strpos($out, '(free)') !== false, "a free undo: $code $out");
        } finally {
            testRmTree($dir);
        }
    },
    'undo: the person\'s own change is refused even when they answer y; from inside an agent, under an overlay or changed since, nothing happens' => function () {
        list($dir, $file, $log) = cfgUndoDir(['halt' => ['disabled' => false]], ['key' => 'halt.disabled', 'old' => true, 'new' => false]);
        try {
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log);
            check($code === 1 && strpos($out, 'yours to change by hand') !== false && (require $file)['halt']['disabled'] === false, "person: $out");
            file_put_contents($log, json_encode(['key' => 'lowBalance.disabled', 'old' => true, 'new' => false]) . "\n");
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log, ['PIKKUMIES_DEPTH' => '1']);
            check($code === 1 && strpos($out, 'not from inside an agent') !== false && !isset((require $file)['lowBalance']), "inside an agent: $out");
            file_put_contents("$dir/overlay.php", "<?php return [];\n");
            chmod("$dir/overlay.php", 0600);
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log, ['PIKKUMIES_CONFIG' => "$dir/overlay.php"]);
            check($code === 1 && strpos($out, 'PIKKUMIES_CONFIG overlay') !== false && strpos($out, '[y/N]') === false && !isset((require $file)['lowBalance']), "overlay: $out");
            file_put_contents($log, json_encode(['key' => 'lowBalance.disabled', 'old' => true, 'new' => true]) . "\n");
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log);
            check($code === 1 && strpos($out, 'but it is now false') !== false, "changed since: $out");
            file_put_contents($log, '');
            list($code, $out) = cfgUndo(true, ["y\n"], $file, $log);
            check($code === 1 && strpos($out, 'no settings change to undo') !== false, "empty log: $out");
        } finally {
            testRmTree($dir);
        }
    },
    'undo: control characters in a forged log key never reach the terminal; a key with no rule is refused before it is printed (re-review N5)' => function () {
        foreach (["\x1b[2K\x1b[32mundo: all good\x1b]52;c;aGk=\x07", "host.\x1b[2Kfamily", "nonsense.key"] as $key) {
            list($dir, $file, $log) = cfgUndoDir([], ['key' => $key, 'old' => 1, 'new' => null]);
            try {
                list($code, $out) = cfgUndo(true, ["y\n"], $file, $log);
                check($code === 1 && strpos($out, "\x1b") === false && strpos($out, "\x07") === false && strpos($out, 'no settings change to undo') !== false
                    && strpos($out, 'nonsense') === false, 'forged key ' . json_encode($key) . ': ' . json_encode($out));
            } finally {
                testRmTree($dir);
            }
        }
    },
    'undo: a free change is made on a terminal without a question; an asked one still asks y/N (ADR-0059 amendment 2026-10-19)' => function () {
        list($dir, $file, $log) = cfgUndoDir(['lowBalance' => ['disabled' => true]], ['key' => 'lowBalance.disabled', 'old' => false, 'new' => true]);
        try {
            list($code, $out) = cfgUndo(true, [], $file, $log);
            check($code === 0 && (require $file)['lowBalance']['disabled'] === false && strpos($out, '(free)') !== false && strpos($out, '[y/N]') === false, "free on a terminal: $code $out");
            file_put_contents($file, '<?php return ' . var_export(['feedback' => ['ask' => true]], true) . ";\n");
            file_put_contents($log, json_encode(['key' => 'feedback.ask', 'old' => false, 'new' => true]) . "\n");
            list($code, $out) = cfgUndo(true, [], $file, $log);
            check($code === 0 && (require $file)['feedback']['ask'] === false && strpos($out, '[y/N]') === false, "turning a question off is free: $code $out");
            file_put_contents($log, json_encode(['key' => 'feedback.ask', 'old' => true, 'new' => false]) . "\n");
            list($code, $out) = cfgUndo(true, ["n\n"], $file, $log);
            check($code === 1 && strpos($out, '(approve)') !== false && strpos($out, '[y/N]') !== false && (require $file)['feedback']['ask'] === false, "asked: still asked: $code $out");
        } finally {
            testRmTree($dir);
        }
    },
];
