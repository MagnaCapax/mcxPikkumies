<?php
/**
 * RffmpegSshWrapper: the generated `commands.ssh` file (Review fixes 2, CWE-295/CWE-863). Upstream's
 * own generate_ssh_command always sends `-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`
 * with no `-F /dev/null`, before `remote.args`, and ssh keeps the FIRST value of a repeated option --
 * so only a wrapper that runs FIRST in argv can restore the pin. This is a real subprocess test (the
 * file is designed to be exec'd standalone by rffmpeg, never called as a PHP class) against a small
 * fake Services stub (this unit's own argv-prepend and fail-closed logic needs no network or A's real
 * class to verify -- tests/RffmpegSshArgvTest.php separately proves the real Services::sshArgv feeds
 * this wrapper's argv correctly) and a fake "ssh" that records exactly what it was called with.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/media/rffmpeg/RffmpegSshWrapper.php';

/** Runs a script with args and no stdin; returns [code, stdout, stderr]. */
function rfRunCapture($script, array $args)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$code, $out, $err];
}

/** A fixture dir with a fake Services stub (refuseListed/secretDir), a fake ssh that logs its argv, and the generated wrapper. */
function rfWrapperFixture($deny = [])
{
    $dir = testTempDir('pk-gputest-sshwrap');
    $denyPhp = var_export($deny, true);
    file_put_contents("$dir/Services.php", "<?php\nclass Services {\n"
        . "    public static \$calls = [];\n"
        . "    public static function refuseListed(\$host) {\n"
        . "        self::\$calls[] = \$host;\n"
        . "        if (in_array(\$host, $denyPhp, true)) { throw new Exception(\"\$host is on the SSH deny list\"); }\n"
        . "    }\n"
        . "    public static function secretDir(\$nick) { return " . var_export("$dir/secrets", true) . " . '/' . \$nick; }\n"
        . "}\n");
    mkdir("$dir/secrets/gpubox", 0700, true);
    touch("$dir/secrets/gpubox/known_hosts");
    $sshLog = "$dir/ssh.log";
    $fakeSsh = "$dir/fake-ssh";
    file_put_contents($fakeSsh, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($sshLog) . "\nexit 7\n");
    chmod($fakeSsh, 0755);
    $wrapper = "$dir/ssh-wrapper.php";
    file_put_contents($wrapper, RffmpegSshWrapper::content("$dir/Services.php", "$dir/state.json", $fakeSsh));
    chmod($wrapper, 0755);
    return ['dir' => $dir, 'wrapper' => $wrapper, 'sshLog' => $sshLog, 'state' => "$dir/state.json"];
}

return [
    'rffmpeg ssh wrapper: refuses, without ever touching ssh, when no host is recorded or the Services list is missing' => function () {
        $fx = rfWrapperFixture();
        try {
            list($code, , $err) = rfRunCapture($fx['wrapper'], ['-q', '-t', 'user@gpubox.example.com']);
            check($code === 1 && strpos($err, 'no GPU host recorded') !== false, 'should refuse with no state.json: ' . $err);
            check(!is_file($fx['sshLog']), 'ssh must never run when there is no recorded host');

            file_put_contents($fx['state'], json_encode(['hosts' => ['gpubox' => ['host' => 'gpubox.example.com']]]));
            unlink($fx['dir'] . '/Services.php');
            list($code, , $err) = rfRunCapture($fx['wrapper'], ['-q', '-t', 'user@gpubox.example.com']);
            check($code === 1 && strpos($err, 'Services list is not installed') !== false, 'should refuse with no Services.php: ' . $err);
            check(!is_file($fx['sshLog']), 'ssh must never run when Services is unavailable');
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'rffmpeg ssh wrapper: a denied host refuses before ssh runs; refuseListed is called with the exact host from user@host' => function () {
        $fx = rfWrapperFixture(['gpubox.example.com']);
        try {
            file_put_contents($fx['state'], json_encode(['hosts' => ['gpubox' => ['host' => 'gpubox.example.com']]]));
            list($code, , $err) = rfRunCapture($fx['wrapper'], ['-q', '-t', '-o', 'StrictHostKeyChecking=no', 'user@gpubox.example.com']);
            check($code === 1 && strpos($err, 'SSH deny list') !== false, 'a denied host must refuse: ' . $err);
            check(!is_file($fx['sshLog']), 'ssh must never run for a denied host');
        } finally {
            testRmTree($fx['dir']);
        }
    },
    'rffmpeg ssh wrapper: prepends the pinned-host-key options FIRST, before every argument upstream passed, and still forwards them' => function () {
        $fx = rfWrapperFixture();
        try {
            file_put_contents($fx['state'], json_encode(['hosts' => ['gpubox' => ['host' => 'gpubox.example.com']]]));
            $upstreamArgs = ['-q', '-t', '-o', 'ConnectTimeout=1', '-o', 'ConnectionAttempts=1', '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null', '-i', '/fake/key', 'fixturegpuuser@gpubox.example.com'];
            list($code) = rfRunCapture($fx['wrapper'], $upstreamArgs);
            check($code === 7, 'the fake ssh exit code (7) must pass through unchanged: ' . $code);
            check(is_file($fx['sshLog']), 'ssh was never actually invoked');
            $logged = explode("\n", trim((string) file_get_contents($fx['sshLog'])));
            $expectPrefix = ['-F', '/dev/null', '-o', 'UserKnownHostsFile=' . $fx['dir'] . '/secrets/gpubox/known_hosts', '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'StrictHostKeyChecking=yes', '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes'];
            check(array_slice($logged, 0, count($expectPrefix)) === $expectPrefix,
                'the security options must be FIRST (ssh keeps the first value of a repeated -o): ' . json_encode($logged));
            check(array_slice($logged, count($expectPrefix)) === $upstreamArgs,
                'everything upstream passed must still be forwarded, unchanged, after the prefix: ' . json_encode($logged));
        } finally {
            testRmTree($fx['dir']);
        }
    },
];
