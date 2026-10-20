<?php
/**
 * RffmpegRemote's argv shape against the REAL pikkumies/lib/Services.php (owner A, docs/adr/0083):
 * proves the fix for the integration bug (A's Services::sshArgv single-quotes EACH remote word for
 * transport shell; sending a whole script as ONE word made the remote shell read the dequoted script
 * text as a single, unknown COMMAND NAME, not as something to run). `['sh', '-c', $script, name,
 * data...]` is the correct shape: this test builds that argv with the real Services::sshArgv against
 * a throwaway services.jsonl + dummy key/known_hosts under a temp PIKKUMIES home, asserts the exact
 * quoting, then feeds the joined remote words to a local `sh -c` -- exactly what ssh hands the remote
 * default shell -- to prove it actually runs, against a real (temp) directory.
 *
 * Skips cleanly when lib/Services.php is not in the tree (it is A's file, not part of this unit's own
 * patch): every other RffmpegRemote test already covers the same scripts through a fake runner.
 */
if (!function_exists('rfRequireRealServices')) {
    function rfRequireRealServices()
    {
        $file = dirname(__DIR__) . '/pikkumies/lib/Services.php';
        if (!is_file($file)) {
            skip('needs the real pikkumies/lib/Services.php (owner A); not landed in this tree yet -- RffmpegHostTest/RffmpegPlanTest already cover these scripts through a fake runner');
        }
        require_once $file;
    }

    /** The tail of a real Services::sshArgv argv, after '-- <host>': what ssh would join and hand the remote shell. */
    function rfSshTail(array $argv)
    {
        $i = array_search('--', $argv, true);
        check($i !== false && isset($argv[$i + 1]), 'no -- <host> separator in argv: ' . json_encode($argv));
        return [$argv[$i + 1], array_slice($argv, $i + 2)];
    }

    function rfQuoted($w)
    {
        return "'" . str_replace("'", "'\\''", (string) $w) . "'";
    }
}

return [
    'rffmpeg + real Services::sshArgv: the mount-check argv is quoted exactly as one word per element, and runs through a local sh -c' => function () {
        rfRequireRealServices();
        $tmp = testTempDir('pk-rffmpeg-services');
        try {
            mkdir("$tmp/local/secrets/services/gpubox", 0700, true);
            file_put_contents("$tmp/local/secrets/services/gpubox/key", "dummy\n");
            chmod("$tmp/local/secrets/services/gpubox/key", 0600);
            file_put_contents("$tmp/local/secrets/services/gpubox/known_hosts", "gpubox.example.com ssh-ed25519 AAAAdummy\n");
            chmod("$tmp/local/secrets/services/gpubox/known_hosts", 0600);
            Services::$localDir = "$tmp/local";
            Services::put(['nick' => 'gpubox', 'host' => 'gpubox.example.com', 'user' => 'fixturegpuuser', 'port' => 22,
                'transport' => 'shell', 'home' => '/home/fixturegpuuser']);

            $mountDir = "$tmp/mount with space";
            mkdir($mountDir, 0700, true);
            touch("$mountDir/afile.txt");

            $script = 'mount="$1"; test -d "$mount" && ls -A "$mount" 2>&1 | head -5; echo "RC=$?"';
            $argv = Services::sshArgv('gpubox', ['sh', '-c', $script, 'rf-mount', $mountDir]);
            list($host, $tail) = rfSshTail($argv);
            check($host === 'gpubox.example.com', "wrong host in argv: $host");
            $want = [rfQuoted('sh'), rfQuoted('-c'), rfQuoted($script), rfQuoted('rf-mount'), rfQuoted($mountDir)];
            check($tail === $want, 'the real Services::sshArgv did not single-quote each word exactly as expected: ' . json_encode($tail));

            // What ssh hands the remote default shell: the trailing words joined by one space each (lib/Services.php).
            $joined = implode(' ', $tail);
            $r = ToolProc::run(['sh', '-c', $joined]);
            check($r['code'] === 0 && strpos($r['out'], 'afile.txt') !== false && strpos($r['out'], 'RC=0') !== false,
                'the quoted argv did not run correctly through a local sh -c (simulating the remote shell): ' . json_encode($r));
        } finally {
            Services::$localDir = null;
            testRmTree($tmp);
        }
    },
    'rffmpeg + real Services::sshArgv: sending a whole script as ONE word (the bug) makes the remote shell see it as an unknown command' => function () {
        rfRequireRealServices();
        $tmp = testTempDir('pk-rffmpeg-services');
        try {
            mkdir("$tmp/local/secrets/services/gpubox", 0700, true);
            file_put_contents("$tmp/local/secrets/services/gpubox/key", "dummy\n");
            chmod("$tmp/local/secrets/services/gpubox/key", 0600);
            file_put_contents("$tmp/local/secrets/services/gpubox/known_hosts", "gpubox.example.com ssh-ed25519 AAAAdummy\n");
            chmod("$tmp/local/secrets/services/gpubox/known_hosts", 0600);
            Services::$localDir = "$tmp/local";
            Services::put(['nick' => 'gpubox', 'host' => 'gpubox.example.com', 'user' => 'fixturegpuuser', 'port' => 22,
                'transport' => 'shell', 'home' => '/home/fixturegpuuser']);

            // The old (buggy) shape this unit sent before the fix: one array element holding a whole script.
            $argv = Services::sshArgv('gpubox', ['echo hi; echo bye']);
            list(, $tail) = rfSshTail($argv);
            check(count($tail) === 1, 'a single-element remoteArgv must stay one quoted word: ' . json_encode($tail));
            $r = ToolProc::run(['sh', '-c', implode(' ', $tail)]);
            check($r['code'] !== 0, 'the bug is gone upstream: a whole script as one word now runs -- re-check the fix is still needed: ' . json_encode($r));
        } finally {
            Services::$localDir = null;
            testRmTree($tmp);
        }
    },
];
