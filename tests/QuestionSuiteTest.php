<?php
/**
 * The question suite's own plumbing that can be tested without asking anything (ADR-0039): the sandbox reset puts
 * local/config.php back between questions, and the run budget flag's two spellings. No question is asked here: every
 * run.php call below stops at argument checks, before the account check and before any install.
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Sandbox.php';

return [
    'questions: the sandbox reset puts local/config.php back, removes one that was not there, and never follows a link' => function () {
        $root = testTempDir('pk-qa-sandbox');
        try {
            $local = "$root/checkout/pikkumies/local";
            mkdir($local, 0700, true);
            $box = new Sandbox($root);
            file_put_contents("$local/config.php", "<?php return ['privacy' => ['telemetry' => true]];\n");
            $box->snapshot();
            file_put_contents("$local/config.php", "<?php return ['privacy' => ['telemetry' => false]];\n");
            $box->reset();
            check(file_get_contents("$local/config.php") === "<?php return ['privacy' => ['telemetry' => true]];\n", 'a changed setting carried over');
            file_put_contents("$root/outside.php", 'keep');
            unlink("$local/config.php");
            symlink("$root/outside.php", "$local/config.php");
            $box->reset();
            check(!is_link("$local/config.php") && strpos(file_get_contents("$local/config.php"), "'telemetry' => true") !== false && file_get_contents("$root/outside.php") === 'keep', 'a link was followed or kept');
            unlink("$local/config.php");
            $box->snapshot();
            file_put_contents("$local/config.php", "<?php return ['agent' => ['name' => 'x']];\n");
            $box->reset();
            check(!file_exists("$local/config.php"), 'a settings file made by a question was kept');
            $out = testTempDir('pk-qa-outside');
            file_put_contents("$local/config.php", 'x');
            $box->snapshot();
            rename($local, "$root/local-moved");
            symlink($out, $local);
            $box->reset();
            check(!file_exists("$out/config.php"), 'the reset wrote through a linked local/ directory');
            testRmTree($out);
        } finally {
            testRmTree($root);
        }
    },
    'questions: --max-tokens is still read as the run budget; --token-budget and --max-tokens together are refused' => function () {
        $run = dirname(__DIR__) . '/tests/testing/questions/run.php';
        $err = function (array $args) use ($run) {
            $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($run) . implode('', array_map(function ($a) {
                return ' ' . escapeshellarg($a);
            }, $args)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            $e = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($p), $e];
        };
        // A bad --timeout stops the run right after the budget check, so these reach no account check and no install.
        list($code, $e) = $err(['--timeout', 'x']);
        check($code === 2 && strpos($e, '--token-budget N is required') !== false, 'no budget was not refused: ' . $e);
        foreach (['--max-tokens', '--token-budget'] as $flag) {
            list($code, $e) = $err([$flag, '5', '--timeout', 'x']);
            check($code === 2 && strpos($e, '--timeout and --max-turns') !== false, "$flag 5 was not taken as the budget: $e");
        }
        list($code, $e) = $err(['--token-budget', '5', '--max-tokens', '7']);
        check($code === 2 && strpos($e, 'give --token-budget only') !== false, 'both spellings at once were not refused: ' . $e);
    },
];
