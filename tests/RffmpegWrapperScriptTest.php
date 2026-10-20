<?php
/**
 * RffmpegRemote::wrapperScript's generated content, run for real under /bin/sh (not just inspected as
 * a string): the same-path rewrite, and Review fixes 2 item 8's quoting fix (`${pkrf_a#"$from"}`,
 * `${TMPDIR#"$from"}`) -- unquoted, the pattern inside `#` is a GLOB, so a `from` value that happens
 * to contain `*` or `[...]` would strip a variable-length match instead of the literal prefix.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegRemote.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegServices.php';

/** Writes the wrapper to a temp file, runs it with $args, and returns the real binary's own received argv (one per line, via a fake "real" dump script). */
function rfRunWrapper($from, $to, array $args, $env = [])
{
    $dir = testTempDir('pk-gputest-wrapscript');
    $real = "$dir/real-dump";
    $dump = "$dir/argv.out";
    file_put_contents($real, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done > " . escapeshellarg($dump) . "\nprintf '%s' \"\$TMPDIR\" > " . escapeshellarg("$dir/tmpdir.out") . "\n");
    chmod($real, 0755);
    $wrapper = "$dir/wrapper";
    file_put_contents($wrapper, RffmpegRemote::wrapperScript($from, $to, $real));
    chmod($wrapper, 0755);
    $cmd = escapeshellarg($wrapper);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $envPrefix = '';
    foreach ($env as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    exec($envPrefix . $cmd . ' 2>&1', $o, $code);
    $got = is_file($dump) ? explode("\n", (string) file_get_contents($dump)) : [];
    if ($got && end($got) === '') {
        array_pop($got);
    }
    $tmpdir = is_file("$dir/tmpdir.out") ? file_get_contents("$dir/tmpdir.out") : null;
    testRmTree($dir);
    return [$code, $got, $tmpdir];
}

return [
    'rffmpeg wrapper script: rewrites an exact-prefix argument to the mount, and leaves a non-matching one alone' => function () {
        list($code, $got) = rfRunWrapper('/home/fixtureuserA', '/mnt/userA', ['-i', '/home/fixtureuserA/movie.mkv', '-f', 'mp4', '/home/fixtureuserB/out.mp4']);
        check($code === 0, "wrapper exited $code");
        check($got === ['-i', '/mnt/userA/movie.mkv', '-f', 'mp4', '/home/fixtureuserB/out.mp4'], 'rewrite wrong: ' . json_encode($got));
    },
    'rffmpeg wrapper script: a literal "*" or "[...]" in the from/to values is never treated as a glob (Review fixes 2, item 8)' => function () {
        // from itself contains a literal glob metacharacter; an argument that merely LOOKS similar must not match.
        list(, $got) = rfRunWrapper('/home/fixtureuser*', '/mnt/x', ['/home/fixtureuserANYTHING/movie.mkv']);
        check($got === ['/home/fixtureuserANYTHING/movie.mkv'], 'an unquoted glob pattern would have wrongly stripped a variable-length match: ' . json_encode($got));

        list(, $got2) = rfRunWrapper('/home/fixtureuser*', '/mnt/x', ['/home/fixtureuser*/movie.mkv']);
        check($got2 === ['/mnt/x/movie.mkv'], 'the exact literal prefix must still rewrite: ' . json_encode($got2));
    },
    'rffmpeg wrapper script: TMPDIR under the home is rewritten to the mount and exported' => function () {
        list(, , $tmpdir) = rfRunWrapper('/home/fixtureuserA', '/mnt/userA', ['-version'], ['TMPDIR' => '/home/fixtureuserA/tmp']);
        check($tmpdir === '/mnt/userA/tmp', 'TMPDIR not rewritten: ' . json_encode($tmpdir));
    },
    'rffmpeg mountVisible: the real generated script, run under a real /bin/sh, reports an ordinary (unmounted) non-empty directory as NOT visible -- the original bug (head\'s exit status) reported it visible' => function () {
        $dir = testTempDir('pk-gputest-mountcheck');
        mkdir("$dir/mnt", 0700, true);
        file_put_contents("$dir/mnt/afile.txt", 'x');
        $gw = new RffmpegServices(['run' => function ($nick, array $remoteArgv, $stdin = null, $timeout = 120) {
            $cmd = 'sh';
            foreach (array_slice($remoteArgv, 1) as $w) { // skip remoteArgv[0] ("sh" itself); argv[1]="-c" etc
                $cmd .= ' ' . escapeshellarg($w);
            }
            exec($cmd . ' 2>&1', $o, $code);
            return ['rc' => $code, 'stdout' => implode("\n", $o) . "\n", 'stderr' => ''];
        }]);
        $r = RffmpegRemote::mountVisible($gw, 'x', "$dir/mnt");
        check($r['visible'] === false, 'an ordinary non-empty directory must not be reported as a visible mount: ' . json_encode($r));
        testRmTree($dir);
    },
];
