<?php
/**
 * development/code-review `context` (ADR-0042) against throwaway git repositories: the rules files and the fallback to
 * the bundled principles, the three ways a change is chosen, line counts and the 150-line target, and hostile input
 * (a shell-looking ref, a ref that looks like an option, a repo whose own git config tries to run a command).
 */

const REVIEW_SCRIPT = __DIR__ . '/../pikkumies/tools/development/code-review/code-review.php';

/** Run git in $dir with fixed author settings; throws when it fails. */
function reviewGit($dir, $args)
{
    exec('git -c user.name=t -c user.email=t@example.org -c commit.gpgsign=false -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
    check($code === 0, "git $args failed: " . implode("\n", $out));
    return trim(implode("\n", $out));
}

/** A repo with $files committed, then $second committed on top; returns its path. */
function reviewRepo(array $files, array $second = [])
{
    $dir = testTempDir('pk-review');
    reviewGit($dir, 'init -q .');
    foreach ([$files, $second] as $n => $set) {
        foreach ($set as $path => $text) {
            @mkdir(dirname("$dir/$path"), 0700, true);
            file_put_contents("$dir/$path", $text);
        }
        if ($set) {
            reviewGit($dir, 'add -A');
            reviewGit($dir, 'commit -q -m c' . $n);
        }
    }
    return $dir;
}

/** [exit code, records by kind] of `context` with $args. */
function reviewContext(array $args)
{
    list($code, $out) = runPhp(REVIEW_SCRIPT, array_merge(['context', '--limit', '0'], $args));
    $lines = cliLines($out);
    $by = ['project' => null, 'rules' => [], 'adr' => null, 'files' => [], 'status' => end($lines)];
    foreach ($lines as $r) {
        if (isset($r['root'])) {
            $by['project'] = $r;
        } elseif (isset($r['rules_file'])) {
            $by['rules'][] = $r['rules_file'];
        } elseif (isset($r['adr_dir'])) {
            $by['adr'] = $r;
        } elseif (isset($r['file'])) {
            $by['files'][$r['file']] = $r;
        }
    }
    return [$code, $by];
}

return [
    'review context: no rules of its own -> fallback to the bundled principles; a clean tree lists the last commit' => function () {
        $dir = reviewRepo(['README.md' => "# x\n", 'a.php' => "1\n2\n3\n"], ['a.php' => "1\nTWO\n3\n4\n"]);
        try {
            list($code, $r) = reviewContext(['--path', $dir]);
            check($code === 0 && $r['status']['ok'] === true, 'exit ' . $code);
            check($r['project']['root'] === realpath($dir), 'root: ' . $r['project']['root']);
            check($r['rules'] === [], 'README.md must not count as a rules file: ' . json_encode($r['rules']));
            check($r['project']['fallback'] === true, 'no rules files: fallback must be true');
            check(substr($r['project']['principles'], -37) === 'development/engineering-principles.md' && $r['project']['principles_found'] === true, 'principles: ' . json_encode($r['project']));
            check(strpos($r['project']['compared'], 'HEAD~1..HEAD') !== false, 'compared: ' . $r['project']['compared']);
            check(array_keys($r['files']) === ['a.php'], 'files: ' . json_encode(array_keys($r['files'])));
            $a = $r['files']['a.php'];
            check($a['lines_added'] === 2 && $a['lines_removed'] === 1 && $a['lines'] === 4 && $a['over_150'] === false, 'a.php: ' . json_encode($a));
        } finally {
            testRmTree($dir);
        }
    },
    'review context: AGENTS.md and docs/adr are the project\'s own rules; the newest ADRs come first' => function () {
        $dir = reviewRepo(['AGENTS.md' => "rules\n", 'notes.md' => "x\n", 'docs/adr/0001-a.md' => "a\n", 'docs/adr/0002-b.md' => "b\n", 'development/principles.md' => "p\n"], ['x.php' => "1\n"]);
        try {
            list($code, $r) = reviewContext(['--path', "$dir/docs/adr"]); // a subdirectory still finds the top level
            check($code === 0 && $r['project']['root'] === realpath($dir), 'root from a subdirectory: ' . json_encode($r['project']));
            check($r['project']['fallback'] === false, 'a project with AGENTS.md must not fall back');
            check($r['rules'] === ['AGENTS.md', 'development/principles.md'], 'rules: ' . json_encode($r['rules']));
            check($r['adr']['count'] === 2 && $r['adr']['newest'] === ['0002-b.md', '0001-a.md'], 'adr: ' . json_encode($r['adr']));
        } finally {
            testRmTree($dir);
        }
    },
    'review context: uncommitted and untracked files, binary files and the 150-line target' => function () {
        $dir = reviewRepo(['a.php' => "1\n", 'b.php' => "1\n", 'tracked.bin' => "\0a"]);
        try {
            file_put_contents("$dir/a.php", str_repeat("x\n", 150));   // exactly the target: not over
            file_put_contents("$dir/new.php", str_repeat("y\n", 160)); // untracked and over
            file_put_contents("$dir/blob.bin", "\0\1\2");
            file_put_contents("$dir/tracked.bin", "\0b\n");
            unlink("$dir/b.php");
            list($code, $r) = reviewContext(['--path', $dir]);
            check($code === 0 && strpos($r['project']['compared'], 'uncommitted') !== false, 'compared: ' . $r['project']['compared']);
            $f = $r['files'];
            check(array_keys($f) === ['a.php', 'b.php', 'blob.bin', 'new.php', 'tracked.bin'], 'files: ' . json_encode(array_keys($f)));
            check($f['a.php']['lines'] === 150 && $f['a.php']['over_150'] === false, 'a.php: ' . json_encode($f['a.php']));
            check($f['new.php']['lines_added'] === 160 && $f['new.php']['over_150'] === true, 'new.php: ' . json_encode($f['new.php']));
            check($f['b.php']['lines_removed'] === 1 && $f['b.php']['lines'] === null, 'deleted b.php: ' . json_encode($f['b.php']));
            check($f['blob.bin']['lines'] === null && $f['blob.bin']['lines_added'] === null, 'binary: ' . json_encode($f['blob.bin']));
            check($f['tracked.bin']['lines_added'] === null && $f['tracked.bin']['lines_removed'] === null, 'changed binary (git numstat -): ' . json_encode($f['tracked.bin']));
            check($r['status']['changed_files'] === 5 && $r['status']['files_over_150'] === 1, 'status: ' . json_encode($r['status']));
        } finally {
            testRmTree($dir);
        }
    },
    'review context: --base compares that commit to the working tree' => function () {
        $dir = reviewRepo(['a.php' => "1\n"], ['b.php' => "1\n2\n"]);
        try {
            $first = reviewGit($dir, 'rev-list --max-parents=0 HEAD');
            file_put_contents("$dir/a.php", "1\n2\n");
            list($code, $r) = reviewContext(['--path', $dir, '--base', $first]);
            check($code === 0 && array_keys($r['files']) === ['a.php', 'b.php'], 'files since the first commit: ' . json_encode(array_keys($r['files'])));
            check($r['files']['b.php']['lines_added'] === 2, 'b.php: ' . json_encode($r['files']['b.php']));
            list($code, $r) = reviewContext(['--path', $dir, '--base', 'no-such-branch']);
            check($code === 1 && strpos($r['status']['error'], 'no such commit') !== false, 'unknown ref: ' . json_encode($r['status']));
        } finally {
            testRmTree($dir);
        }
    },
    'review context: a shell-looking ref is never run and a ref starting with "-" is never an option' => function () {
        $dir = reviewRepo(['a.php' => "1\n"], ['a.php' => "2\n"]);
        try {
            list($code, $r) = reviewContext(['--path', $dir, '--base', '$(touch ' . $dir . '/pwned)']);
            check($code === 1 && !file_exists("$dir/pwned"), 'the ref ran in a shell or was accepted: ' . json_encode($r['status']));
            foreach (['--base=--output=' . $dir . '/out.txt', '--base=-x'] as $hostile) {
                list($code, $r) = reviewContext(['--path', $dir, $hostile]);
                check($code === 1 && strpos($r['status']['error'], 'never starts with "-"') !== false, "$hostile: " . json_encode($r['status']));
            }
            check(!file_exists("$dir/out.txt"), 'a ref was read as git\'s --output option');
            list($code) = reviewContext(['--path', sys_get_temp_dir()]);
            check($code === 1, 'outside a git work tree must be an error');
        } finally {
            testRmTree($dir);
        }
    },
    'review context: a repo without commits lists every file as new; a clean first commit lists nothing' => function () {
        $dir = reviewRepo([]);
        try {
            file_put_contents("$dir/staged.php", "1\n2\n");
            file_put_contents("$dir/loose.php", "1\n");
            reviewGit($dir, 'add staged.php');
            list($code, $r) = reviewContext(['--path', $dir]);
            check($code === 0 && strpos($r['project']['compared'], 'no commits yet') !== false, 'compared: ' . json_encode($r['project']));
            check(array_keys($r['files']) === ['loose.php', 'staged.php'] && $r['files']['staged.php']['lines_added'] === 2, 'files: ' . json_encode($r['files']));
            reviewGit($dir, 'add -A');
            reviewGit($dir, 'commit -q -m first');
            list($code, $r) = reviewContext(['--path', $dir]);
            check($code === 0 && $r['files'] === [] && strpos($r['project']['compared'], 'first commit') !== false, 'first commit: ' . json_encode($r));
        } finally {
            testRmTree($dir);
        }
    },
    'review context: the reviewed repo\'s own git config cannot run a command (core.fsmonitor)' => function () {
        $dir = reviewRepo(['a.php' => "1\n"]);
        try {
            reviewGit($dir, 'config core.fsmonitor ' . escapeshellarg('touch ' . $dir . '/fsmonitor-ran; true'));
            file_put_contents("$dir/a.php", "2\n");
            list($code, $r) = reviewContext(['--path', $dir]);
            check($code === 0 && isset($r['files']['a.php']), 'context failed: ' . json_encode($r['status']));
            check(!file_exists("$dir/fsmonitor-ran"), 'git ran the repo\'s core.fsmonitor command');
        } finally {
            testRmTree($dir);
        }
    },
];
