<?php
/**
 * tests/testing/questions: --only takes one id or a comma-separated list, and an unknown id stops the run instead of
 * silently running fewer questions. A rerun of a run's failed questions names them all in one --only.
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Questions.php';

/** Run run.php with $args; returns [exit code, stdout, stderr]. */
function onlyRun(array $args)
{
    $run = dirname(__DIR__) . '/tests/testing/questions/run.php';
    $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($run) . implode('', array_map(function ($a) {
        return ' ' . escapeshellarg($a);
    }, $args)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]);
    $e = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $o, $e];
}

return [
    'questions: Questions::select() takes one id, a comma-separated list with spaces, or nothing for all' => function () {
        $qs = [['id' => 'a', 'tags' => ['x']], ['id' => 'b', 'tags' => ['y']], ['id' => 'c', 'tags' => ['x']]];
        check(array_column(Questions::select($qs, 'b', ''), 'id') === ['b'], 'one id must pick that question');
        check(array_column(Questions::select($qs, 'c, a', ''), 'id') === ['a', 'c'], 'a list must pick each named question in suite order');
        check(count(Questions::select($qs, '', '')) === 3, 'no --only must pick all');
        check(array_column(Questions::select($qs, 'a,b', 'x'), 'id') === ['a'], '--only and --tag must both apply');
        check(Questions::onlyIds(' a, ,b ,') === ['a', 'b'], 'blank list items must be dropped');
    },
    'questions: run.php --list --only lists exactly the named questions, and an unknown id is refused' => function () {
        list($code, $out) = onlyRun(['--list', '--only', 'accept-terms, big-files']);
        $ids = array_map(function ($l) { return strtok($l, ' '); }, array_values(array_filter(explode("\n", $out), 'strlen')));
        check($code === 0 && $ids === ['accept-terms', 'big-files'], 'the two named questions were not listed: ' . $out);
        list($code, , $err) = onlyRun(['--list', '--only', 'accept-terms,no-such-question']);
        check($code !== 0 && strpos($err, 'no-such-question') !== false, 'an unknown id was not refused: ' . $err);
    },
];
