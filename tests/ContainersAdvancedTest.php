<?php
/**
 * The docker unit's advanced verbs (ADR-0034 amendment of 2026-10-19): show --record, fix-owner, expose,
 * diagnose --network and dump. Every test runs docker.php against the fake docker (tests/fixtures/media/docker.php,
 * containers mode) in a throwaway home; expose uses a fake lighttpd that follows include lines. Nothing reads the real
 * pikkumies/local/, /proc, /proc/net or /run/user, and no real daemon or web server is used.
 */
require_once __DIR__ . '/fixtures/containers/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/containers/docker/DockerCli.php';
foreach (['DockerRefs', 'DockerExposure', 'DockerOps', 'DockerDiagnose', 'DockerLaunch', 'DockerRecord', 'DockerOwner', 'DockerExpose', 'DockerNetwork', 'DockerDump'] as $advPart) {
    if (is_file(dirname(__DIR__) . "/pikkumies/tools/containers/docker/$advPart.php")) { // a missing class fails its own tests, not the run
        require_once dirname(__DIR__) . "/pikkumies/tools/containers/docker/$advPart.php";
    }
}

if (!function_exists('advC')) {
    /** A fake-docker container record. */
    function advC($name, array $extra = [])
    {
        return $extra + ['id' => hash('sha256', 'adv-' . $name), 'name' => $name, 'image' => 'nginx:alpine', 'state' => 'running', 'exit_code' => 0,
            'ports' => [], 'labels' => [], 'env' => [], 'mounts' => [], 'started_at' => '2026-10-19T10:00:00.000000000Z'];
    }

    /** The image every fake container reports in inspect (`Image`), so `image inspect` of it answers. */
    function advImage(array $extra = [])
    {
        return $extra + ['id' => 'sha256:' . str_repeat('ab', 32), 'repository' => 'nginx', 'tag' => 'alpine', 'size' => 5000000, 'created' => '2026-10-01 10:00:00 +0000 UTC'];
    }

    /** The status line and the records before it. */
    function advSplit(array $recs)
    {
        return [$recs ? $recs[count($recs) - 1] : [], array_slice($recs, 0, -1)];
    }

    /** /proc/net/tcp text with one LISTEN line for $port on 127.0.0.1 owned by $uid. */
    function advTcp($port, $uid)
    {
        return "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
            . sprintf("   0: 0100007F:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000 %5d        0 4242 1 0 100 0 0 10 0\n", $port, $uid);
    }

    function advUid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }

    /** A process of this test's uid in the fixture's proc dir, with $argv as its command line. */
    function advProc(array $fx, $pid, array $argv)
    {
        mkdir($fx['dir'] . "/proc/$pid", 0700, true);
        file_put_contents($fx['dir'] . "/proc/$pid/cmdline", implode("\0", $argv) . "\0");
    }

    /**
     * A fake lighttpd: `-t -f <conf>` reads the config and every file its include lines name (globs too), logs the
     * call, and fails (exit 255) when any of them holds BROKEN or the text in FAKE_LIGHTTPD_FAIL_ON.
     */
    function advLighttpd(array $fx)
    {
        $bin = $fx['dir'] . '/bin/lighttpd';
        file_put_contents($bin, '#!' . PHP_BINARY . "\n" . '<?php
$conf = $argv[3];
$texts = [(string) file_get_contents($conf)];
preg_match_all(\'/^include "([^"]+)"/m\', $texts[0], $m);
foreach ($m[1] as $pattern) {
    foreach ((array) glob($pattern) as $f) {
        $texts[] = (string) file_get_contents($f);
    }
}
file_put_contents(dirname(__DIR__) . "/lighttpd.log", json_encode(["args" => array_slice($argv, 1), "files" => count($texts)]) . "\n", FILE_APPEND);
$bad = getenv("FAKE_LIGHTTPD_FAIL_ON");
foreach ($texts as $t) {
    if (strpos($t, "BROKEN") !== false || ($bad !== false && $bad !== "" && strpos($t, $bad) !== false)) {
        fwrite(STDERR, "(configfile.c.1) source: x line: 3 pos: 1 parser failed somehow near here: (EOL)\n");
        exit(255);
    }
}
echo "Syntax OK\n";
');
        chmod($bin, 0700);
        mkdir($fx['home'] . '/.lighttpd/custom.d', 0700, true);
        file_put_contents($fx['home'] . '/.lighttpd.conf', "server.port = 1\ninclude \"{$fx['home']}/.lighttpd/custom.d/*.conf\"\n");
        mkdir($fx['dir'] . '/pmss');
        return ['pmss_dir' => $fx['dir'] . '/pmss', 'user' => 'exampleuser', 'lighttpd_bin' => $bin, 'listener_tables' => [advTcp(38001, advUid()), '']];
    }

    function advLighttpdCalls(array $fx)
    {
        $f = $fx['dir'] . '/lighttpd.log';
        return is_file($f) ? array_map(function ($l) {
            return json_decode($l, true);
        }, array_values(array_filter(explode("\n", (string) file_get_contents($f)), 'strlen'))) : [];
    }

    /** A fixture with the seams fix-owner needs: this uid as the account, its sub-ids 100000-165535. */
    function advOwnerSeams(array $fx)
    {
        $uid = advUid();
        $gid = function_exists('posix_getegid') ? posix_getegid() : getmygid();
        file_put_contents($fx['dir'] . '/subuid', "$uid:100000:65536\n");
        file_put_contents($fx['dir'] . '/subgid', "$uid:100000:65536\n");
        return ['subuid_file' => $fx['dir'] . '/subuid', 'subgid_file' => $fx['dir'] . '/subgid', 'gid' => $gid];
    }
}

return [
    'containers advanced: show --record writes the settings (env names, never values) with a recreate line and its gaps; a changed record keeps the old one' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/docker/web/data', 0700, true);
            file_put_contents($fx['home'] . '/docker/web/env', "DB_PASS=s3cretvalue99\n");
            $data = realpath($fx['home'] . '/docker/web/data');
            $st = containersState($fx);
            $st['images'] = [advImage()];
            $st['containers'] = [advC('web-1', ['ports' => [['ip' => '127.0.0.1', 'host' => 38001, 'container' => 80, 'proto' => 'tcp']],
                'mounts' => [['Type' => 'bind', 'Source' => $data, 'Destination' => '/data', 'Mode' => '', 'RW' => true]],
                'env' => ['PATH=/usr/bin', 'DB_PASS=s3cretvalue99'], 'labels' => ['pikkumies.env_file' => $fx['home'] . '/docker/web/env'], 'restart' => 'unless-stopped'])];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, $recs, $out) = containersRun($fx, ['show', 'web-1', '--record', '~/docker/records']);
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['ok'] === true, 'show --record failed: ' . $out);
            $file = $fx['home'] . '/docker/records/web-1.json';
            check($rows[0]['recorded_to'] === realpath($fx['home']) . '/docker/records/web-1.json' && $rows[0]['earlier_kept'] === null, 'recorded_to: ' . json_encode($rows[0]));
            check(is_file($file) && (fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'the record must be 600 in a 700 folder');
            $text = (string) file_get_contents($file);
            check(strpos($text, 's3cretvalue99') === false && strpos($out, 's3cretvalue99') === false, 'an env value reached the record or the output');
            $rec = json_decode($text, true);
            check($rec['env_names'] === ['PATH', 'DB_PASS'] && $rec['image'] === 'nginx:alpine', 'record fields: ' . $text);
            foreach (['launch-plan', '--image=nginx:alpine', '--name=web-1', '--port=38001:80', "--volume=$data:/data", '--env-file=' . $fx['home'] . '/docker/web/env'] as $want) {
                check(strpos($rec['recreate'], $want) !== false, "recreate lacks $want: " . $rec['recreate']);
            }
            check(strpos($rec['recreate'], '--public') === false, 'a loopback port must not recreate as --public');
            check((bool) preg_grep('/^cmd: not recorded/', $rec['recreate_gaps']), 'an unknown command must be a gap: ' . json_encode($rec['recreate_gaps']));
            // Same settings again: rewritten, nothing kept.
            list($code, $recs) = containersRun($fx, ['show', 'web-1', '--record', '~/docker/records']);
            check($code === 0 && $recs[0]['earlier_kept'] === null && count(glob($fx['home'] . '/docker/records/*')) === 1, 'same settings must not keep a copy');
            // Stopped since: run state is not a setting, so still no copy.
            $st = containersState($fx);
            $st['containers'][0]['state'] = 'exited';
            $st['containers'][0]['exit_code'] = 143;
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, $recs) = containersRun($fx, ['show', 'web-1', '--record', '~/docker/records']);
            check($code === 0 && $recs[0]['earlier_kept'] === null && count(glob($fx['home'] . '/docker/records/*')) === 1, 'a stop must not keep a copy: ' . json_encode($recs[0]));
            // Another port now: the earlier record is kept beside it.
            $st = containersState($fx);
            $st['containers'][0]['ports'][0]['host'] = 38002;
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, $recs) = containersRun($fx, ['show', 'web-1', '--record', '~/docker/records']);
            check($code === 0 && is_string($recs[0]['earlier_kept']) && is_file($recs[0]['earlier_kept']), 'a changed record must keep the earlier one: ' . json_encode($recs[0]));
            check(strpos((string) file_get_contents($recs[0]['earlier_kept']), '38001:80') !== false && strpos((string) file_get_contents($file), '38002:80') !== false, 'kept copy and new record hold their own ports');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: show --record refuses a folder outside the home, the home itself and Pikkumies\' own tree, writing nothing' => function () {
        $fx = containersFixture(['images' => [advImage()], 'containers' => [advC('web-1')]]);
        try {
            foreach ([$fx['dir'] . '/outside', '~/', dirname(__DIR__) . '/pikkumies/tools', 'relative/dir'] as $dir) {
                list($code, $recs) = containersRun($fx, ['show', 'web-1', '--record', $dir]);
                $last = $recs[count($recs) - 1];
                check($code === 1 && strpos($last['error'], '--record') === 0, "$dir: expected a refusal, got $code " . json_encode($last));
            }
            check(!file_exists($fx['dir'] . '/outside') && !file_exists(dirname(__DIR__) . '/pikkumies/tools/web-1.json'), 'a refused --record wrote something');
            list($code, $recs) = containersRun($fx, ['show', 'web-1']);
            check($code === 0 && !isset($recs[0]['recorded_to']), 'plain show must not write a record');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: fix-owner id mapping: 0 is the account, 1.. walk the sub-id ranges; ids outside them have no container id' => function () {
        $m = ['self' => 1000, 'rootless' => true, 'ranges' => [[100000, 165535]]];
        check(DockerOwner::toHost($m, 0) === 1000 && DockerOwner::toHost($m, 1) === 100000 && DockerOwner::toHost($m, 1000) === 100999, 'toHost');
        check(DockerOwner::toHost($m, 65536) === 165535 && DockerOwner::toHost($m, 65537) === null, 'toHost past the range');
        check(DockerOwner::toContainer($m, 1000) === 0 && DockerOwner::toContainer($m, 100999) === 1000 && DockerOwner::toContainer($m, 5) === null, 'toContainer');
        $two = ['self' => 1000, 'rootless' => true, 'ranges' => [[100000, 100009], [200000, 200009]]];
        check(DockerOwner::toHost($two, 11) === 200000 && DockerOwner::toContainer($two, 200000) === 11, 'a second range continues the numbering');
        $root = ['self' => 0, 'rootless' => false, 'ranges' => []];
        check(DockerOwner::toHost($root, 1000) === 1000 && DockerOwner::toContainer($root, 1000) === 1000, 'a root daemon maps ids to themselves');
        check(DockerOwner::runAs(['Config' => ['User' => '1000:1001']]) === ['uid' => 1000, 'gid' => 1001, 'from' => 'user'], 'numeric user');
        check(DockerOwner::runAs(['Config' => ['User' => '', 'Env' => ['PUID=911', 'PGID=912']]]) === ['uid' => 911, 'gid' => 912, 'from' => 'PUID'], 'PUID/PGID');
        check(DockerOwner::runAs(['Config' => ['User' => '']]) === ['uid' => 0, 'gid' => 0, 'from' => 'default'], 'image default is root');
        check(DockerOwner::runAs(['Config' => ['User' => 'nginx']])['uid'] === null, 'a user name is unknown here');
    },
    'containers advanced: fix-owner scan records what to change, counts what it leaves, and lists folders it cannot open' => function () {
        if (advUid() === 0) {
            skip('root opens every folder; the unreadable-folder case needs a normal account');
        }
        $dir = testTempDir('pk-adv-scan');
        try {
            mkdir("$dir/a/locked", 0700, true);
            file_put_contents("$dir/a/f", 'x');
            file_put_contents("$dir/a/locked/g", 'y');
            symlink('/etc/passwd', "$dir/link");
            chmod("$dir/a/locked", 0);
            // Pretend owners by path: a and a/f are a sub-uid's, link is the account's, the root is unmapped.
            $owners = ['' => 99, 'a' => 100005, 'a/f' => 100005, 'a/locked' => 100005, 'link' => 1000];
            $stat = function ($p) use ($dir, $owners) {
                $st = @lstat($p);
                $rel = ltrim(substr($p, strlen($dir)), '/');
                if ($st && isset($owners[$rel])) {
                    $st['uid'] = $owners[$rel];
                    $st['gid'] = $owners[$rel];
                }
                return $st;
            };
            $scope = function ($uid) {
                return $uid === 1000 ? 'done' : ($uid >= 100000 ? 'change' : 'unmapped');
            };
            $r = DockerOwner::scan($dir, $scope, microtime(true) + 30, 1000, '', false, $stat);
            $rels = array_column($r['entries'], 0);
            sort($rels);
            check($rels === ['a', 'a/f', 'a/locked'] && $r['unmapped'] === 1 && $r['locked'] === ['a/locked'] && !$r['cut'], 'scan: ' . json_encode($r));
            $cut = DockerOwner::scan($dir, $scope, microtime(true) + 30, 1, '', false, $stat);
            check($cut['cut'] === true, 'more entries than the limit must cut the scan');
        } finally {
            @chmod("$dir/a/locked", 0700);
            testRmTree($dir);
        }
    },
    'containers advanced: fix-owner --to container records every entry first, runs one chown helper from the container\'s image, and --restore puts the owners back' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/docker/app/config/sub', 0700, true);
            file_put_contents($fx['home'] . '/docker/app/config/a.conf', 'x');
            file_put_contents($fx['home'] . '/docker/app/config/sub/b.conf', 'y');
            $src = realpath($fx['home'] . '/docker/app/config');
            $st = containersState($fx);
            $st['images'] = [advImage()];
            $st['containers'] = [advC('app', ['user' => '1000:1000', 'mounts' => [['Type' => 'bind', 'Source' => $src, 'Destination' => '/config', 'RW' => true]]])];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            $seams = advOwnerSeams($fx);
            list($code, $recs, $out) = containersRun($fx, ['fix-owner', '~/docker/app/config/', '--to', 'container'], $seams);
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['changed'] === 4, 'fix-owner: ' . $out);
            $r = $rows[0];
            check($r['container'] === 'app' && $r['owner_inside'] === '1000:1000' && $r['owner_on_server'] === '100999:100999' && $r['method'] === 'recursive', 'record: ' . json_encode($r));
            check(strpos($r['cause'], 'runs as uid 1000, gid 1000 inside (its user setting)') !== false, 'the cause must name the container\'s user: ' . $r['cause']);
            $one = containersState($fx)['oneshots'];
            check(count($one) === 1, 'one helper call expected: ' . json_encode($one));
            foreach (['--rm', '--pull=never', '--network=none', '--user=0:0', '--entrypoint=chown', "--mount=type=bind,source=$src,target=/pk-target", 'sha256:' . str_repeat('ab', 32), '-R', '-h', '1000:1000', '/pk-target'] as $w) {
                check(in_array($w, $one[0], true), "helper argv lacks $w: " . json_encode($one[0]));
            }
            check(!in_array('-d', $one[0], true) && !preg_grep('/^--force|^-f$/', $one[0]), 'the helper is a one-shot, never forced');
            $m = json_decode((string) file_get_contents($r['manifest']), true);
            check((fileperms($r['manifest']) & 0777) === 0600 && strpos($r['manifest'], $fx['dir'] . '/local/containers/fix-owner/') === 0, 'manifest place and mode');
            $rels = array_column($m['entries'], 0);
            sort($rels);
            check($rels === ['', 'a.conf', 'sub', 'sub/b.conf'] && $m['entries'][0][3] === 0 && $m['path'] === $src, 'manifest entries: ' . json_encode($m));
            check($r['verified'] === 0 && count($r['not_verified']) === 4, 'the fake cannot chown: nothing may be reported verified: ' . json_encode($r));
            check(strpos($r['undo'], 'fix-owner --restore ' . $r['manifest']) !== false, 'undo line: ' . $r['undo']);
            // Restore: one helper per original owner, then a read-back.
            list($code, $recs, $out) = containersRun($fx, ['fix-owner', '--restore', $r['manifest']], $seams);
            check($code === 0 && $recs[0]['restored'] === 4 && $recs[0]['verified'] === 4, 'restore: ' . $out);
            $one = containersState($fx)['oneshots'];
            check(count($one) === 2 && in_array('0:0', $one[1], true) && in_array('/pk-target/sub/b.conf', $one[1], true) && !in_array('-R', $one[1], true), 'restore argv: ' . json_encode($one[1]));
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: fix-owner changes nothing when nothing needs it, and refuses unmounted, home, key and malformed targets before any helper runs' => function () {
        $fx = containersFixture();
        try {
            mkdir($fx['home'] . '/docker/app', 0700, true);
            mkdir($fx['home'] . '/.ssh', 0700, true);
            mkdir($fx['home'] . '/loose', 0700, true);
            $src = realpath($fx['home'] . '/docker/app');
            $st = containersState($fx);
            $st['images'] = [advImage()];
            $st['containers'] = [advC('app', ['mounts' => [['Type' => 'bind', 'Source' => $src, 'Destination' => '/data', 'RW' => true]]]),
                advC('named', ['user' => 'www-data', 'mounts' => [['Type' => 'bind', 'Source' => $src, 'Destination' => '/data', 'RW' => true]]])];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            $seams = advOwnerSeams($fx);
            list($code, $recs) = containersRun($fx, ['fix-owner', $src, '--to', 'me'], $seams);
            check($code === 0 && $recs[0]['changed'] === 0 && $recs[0]['manifest'] === null && $recs[0]['method'] === 'none', 'account-owned files: nothing to change: ' . json_encode($recs));
            foreach ([
                [[$fx['home'] . '/loose', '--to', 'me'], 'no container mounts'],
                [['~/', '--to', 'me'], 'cannot be changed here'],
                [[$fx['home'] . '/.ssh', '--to', 'me'], 'cannot be changed here'],
                [[$fx['home'] . '/nope', '--to', 'me'], 'no file or folder'],
                [[$src, '--to', 'everyone'], '--to takes me'],
                [[$src, '--to', 'container', '--container', 'named'], 'runs as a user named inside its image'],
                [[$src, '--to', 'container'], 'run as different users'],
            ] as $case) {
                list($code, $recs) = containersRun($fx, array_merge(['fix-owner'], $case[0]), $seams);
                $last = $recs[count($recs) - 1];
                check($code === 1 && strpos($last['error'], $case[1]) !== false, json_encode($case[0]) . ': ' . json_encode($last));
            }
            check(empty(containersState($fx)['oneshots']), 'a refusal or a no-op ran a helper');
            check(!is_dir($fx['dir'] . '/local/containers/fix-owner'), 'a refusal wrote a manifest');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: fix-owner reports an image without chown and keeps the manifest, so nothing is left unrecorded' => function () {
        $fx = containersFixture(['engine' => ['oneshot_code' => 127, 'oneshot_err' => 'docker: Error response from daemon: failed to create task: exec: "chown": executable file not found in $PATH']]);
        try {
            mkdir($fx['home'] . '/docker/app', 0700, true);
            $src = realpath($fx['home'] . '/docker/app');
            $st = containersState($fx);
            $st['images'] = [advImage()];
            $st['containers'] = [advC('app', ['env' => ['PUID=1000', 'PGID=1000'], 'mounts' => [['Type' => 'bind', 'Source' => $src, 'Destination' => '/config', 'RW' => true]]])];
            file_put_contents($fx['dir'] . '/run/docker.json', json_encode($st));
            list($code, $recs, $out) = containersRun($fx, ['fix-owner', $src, '--to', 'container'], advOwnerSeams($fx));
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['complete'] === false && strpos($rows[0]['error_text'], 'no chown program') !== false, 'no chown: ' . $out);
            check(strpos($rows[0]['cause'], 'its PUID/PGID setting') !== false && is_file($rows[0]['manifest']), 'cause and manifest: ' . json_encode($rows[0]));
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: expose writes one panel-login fragment after lighttpd -t passes, refuses a second, and --remove deletes only its own' => function () {
        $fx = containersFixture(['containers' => [advC('web-1', ['ports' => [['ip' => '127.0.0.1', 'host' => 38001, 'container' => 80, 'proto' => 'tcp']]])]]);
        try {
            $seams = advLighttpd($fx);
            list($code, $recs, $out) = containersRun($fx, ['expose', 'web', '--port', '38001'], $seams);
            check($code === 0, 'expose: ' . $out);
            $r = $recs[0];
            $file = $fx['home'] . '/.lighttpd/custom.d/pikkumies-web.conf';
            check($r['url_path'] === '/user-exampleuser/web/' && $r['access'] === 'panel-login' && $r['fragment'] === $file && $r['config_check'] === 'ok', 'record: ' . json_encode($r));
            check(strpos($r['takes_effect'], 'within about two minutes') === 0 && strpos($r['undo'], 'expose web --remove') !== false, 'takes_effect/undo: ' . json_encode($r));
            check(is_file($file) && (fileperms($file) & 0777) === 0600, 'the fragment must exist with mode 600');
            $text = (string) file_get_contents($file);
            check(strpos($text, "# pikkumies expose app=web port=38001 access=panel path=strip\n") === 0, 'marker line: ' . $text);
            foreach (['$HTTP["url"] =~ "^/user-exampleuser/web($|/)" {', '"port" => 38001', 'setenv.set-request-header = ( "Authorization" => "" )', '"/user-exampleuser/web/"  => "/"', '"upgrade" => "enable"'] as $want) {
                check(strpos($text, $want) !== false, "fragment lacks $want:\n$text");
            }
            $calls = advLighttpdCalls($fx);
            check(count($calls) === 3 && $calls[0]['args'][0] === '-t' && $calls[1]['args'][2] !== $fx['home'] . '/.lighttpd.conf' && $calls[2]['args'][2] === $fx['home'] . '/.lighttpd.conf',
                'expected checks: before, a private copy with the fragment, then the real config: ' . json_encode($calls));
            check(!glob($fx['dir'] . '/local/containers/expose-check-*'), 'the private check copy must be removed');
            list($code, $recs) = containersRun($fx, ['expose', 'web', '--port', '38001'], $seams);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], 'is already there') !== false, 'a second expose must be refused');
            file_put_contents($fx['home'] . '/.lighttpd/custom.d/mine.conf', "# the user's own\n");
            list($code, $recs) = containersRun($fx, ['expose', 'mine', '--remove'], $seams);
            check($code === 1 && is_file($fx['home'] . '/.lighttpd/custom.d/mine.conf'), 'a fragment of the user (not this tool\'s name) must not be removed');
            rename($fx['home'] . '/.lighttpd/custom.d/mine.conf', $fx['home'] . '/.lighttpd/custom.d/pikkumies-mine.conf');
            list($code, $recs) = containersRun($fx, ['expose', 'mine', '--remove'], $seams);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], 'was not written by this tool') !== false && is_file($fx['home'] . '/.lighttpd/custom.d/pikkumies-mine.conf'), 'a file without the marker must stay');
            list($code, $recs) = containersRun($fx, ['expose', 'web', '--remove'], $seams);
            check($code === 0 && $recs[0]['removed'] === true && !file_exists($file) && strpos($recs[0]['again'], 'expose web --port 38001') !== false, 'remove: ' . json_encode($recs));
            check(containersState($fx)['calls'] === [], 'expose must not call docker');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: expose refuses another account\'s port, a free port, reserved names, a shadowed www folder, a URL another fragment has, a broken config, and off PMSS' => function () {
        $fx = containersFixture();
        try {
            $seams = advLighttpd($fx);
            mkdir($fx['home'] . '/www/shadow', 0700, true);
            file_put_contents($fx['home'] . '/.lighttpd/custom.d/other.conf', '$HTTP["url"] =~ "^/user-exampleuser/taken/" {' . "\n}\n");
            foreach ([
                [['expose', 'web', '--port', '38001'], ['listener_tables' => [advTcp(38001, 4242), '']], 'belongs to another account'],
                [['expose', 'web', '--port', '38002'], [], 'nothing of this account listens on port 38002'],
                [['expose', 'deluge', '--port', '38001'], [], 'already uses'],
                [['expose', 'Bad_Name', '--port', '38001'], [], 'expose takes one name'],
                [['expose', 'shadow', '--port', '38001'], [], 'would be hidden'],
                [['expose', 'taken', '--port', '38001'], [], 'already handles /user-exampleuser/taken'],
                [['expose', 'web', '--port', '38001'], ['pmss_dir' => $fx['dir'] . '/no-pmss'], 'not PMSS'],
                [['expose', 'web'], [], '--port takes'],
            ] as $case) {
                list($code, $recs) = containersRun($fx, $case[0], $case[1] + $seams);
                $last = $recs[count($recs) - 1];
                check($code === 1 && strpos($last['error'], $case[2]) !== false, json_encode($case[0]) . ': ' . json_encode($last));
            }
            check(advLighttpdCalls($fx) === [], 'a refusal before the config check ran lighttpd');
            file_put_contents($fx['home'] . '/.lighttpd/custom.d/broken.conf', "BROKEN\n");
            list($code, $recs) = containersRun($fx, ['expose', 'web', '--port', '38001'], $seams);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], 'already fails its check') !== false, 'a config that already fails must be refused');
            unlink($fx['home'] . '/.lighttpd/custom.d/broken.conf');
            list($code, $recs) = containersRun($fx, ['expose', 'web', '--port', '38001', '--public'], $seams, ['FAKE_LIGHTTPD_FAIL_ON' => 'access=public']);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], 'config check fails with the fragment') !== false, 'a failing check must refuse: ' . json_encode($recs));
            check(!glob($fx['home'] . '/.lighttpd/custom.d/pikkumies-*') && !glob($fx['dir'] . '/local/containers/expose-check-*'), 'a failing check must leave no fragment and no copy');
            list($code, $recs) = containersRun($fx, ['expose', 'web', '--port', '38001', '--public', '--keep-path'], $seams);
            $text = (string) @file_get_contents($fx['home'] . '/.lighttpd/custom.d/pikkumies-web.conf');
            check($code === 0 && $recs[0]['url_path'] === '/public-exampleuser/web/' && $recs[0]['access'] === 'public', 'public: ' . json_encode($recs));
            check(strpos($text, '"^/web($|/)"') !== false && strpos($text, 'Authorization') === false && strpos($text, 'map-urlpath') === false, "public, kept path:\n$text");
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: diagnose --network reads rootlesskit\'s options, a missing network helper, and published ports nothing listens on or another account holds' => function () {
        $fx = containersFixture(['engine' => ['networks' => [['Name' => 'bridge', 'Driver' => 'bridge'], ['Name' => 'media', 'Driver' => 'bridge']]], 'containers' => [
            advC('web-1', ['ports' => [['ip' => '127.0.0.1', 'host' => 38001, 'container' => 80, 'proto' => 'tcp']]]),
            advC('web-2', ['ports' => [['ip' => '0.0.0.0', 'host' => 38002, 'container' => 80, 'proto' => 'tcp']]]),
            advC('web-3', ['ports' => [['ip' => '127.0.0.1', 'host' => 38003, 'container' => 80, 'proto' => 'tcp']]])]]);
        try {
            advProc($fx, 101, ['rootlesskit', '--state-dir=/run/user/1/dockerd-rootless', '--net=slirp4netns', '--mtu=65520', '--port-driver=builtin', '--disable-host-loopback', '--copy-up=/etc', 'dockerd-rootless.sh']);
            $tables = [advTcp(38001, advUid()) . substr(advTcp(38003, 4242), strpos(advTcp(38003, 4242), "\n") + 1), ''];
            list($code, $recs, $out) = containersRun($fx, ['diagnose', '--network'], ['listener_tables' => $tables]);
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['ok'] === true, 'diagnose --network: ' . $out);
            $s = $rows[0];
            check($s['kind'] === 'network-stack' && $s['rootless_net'] === 'slirp4netns' && $s['port_driver'] === 'builtin' && $s['mtu'] === 65520
                && $s['host_loopback'] === 'disabled' && $s['helper_running'] === false && $s['networks'] === ['media'] && $s['ports_checked'] === 3, 'summary: ' . json_encode($s));
            $kinds = array_column(array_slice($rows, 1), 'kind');
            check($kinds[0] === 'network-helper-missing', 'the missing helper must come first: ' . json_encode($kinds));
            check(in_array('port-taken', $kinds, true) && in_array('port-not-listening', $kinds, true) && in_array('host-loopback-disabled', $kinds, true), 'findings: ' . json_encode($kinds));
            foreach (array_slice($rows, 1) as $f) {
                if ($f['kind'] === 'port-not-listening') {
                    check($f['name'] === 'web-2', 'only web-2 has no listener: ' . json_encode($f));
                }
                if ($f['kind'] === 'port-taken') {
                    check($f['name'] === 'web-3', 'web-3\'s port is another account\'s: ' . json_encode($f));
                }
            }
            check(!preg_grep('/^(run|exec|pull|start|stop|restart|rm)$/', array_column(containersState($fx)['calls'], 0)), 'diagnose --network must only read: ' . json_encode(containersState($fx)['calls']));
            advProc($fx, 102, ['slirp4netns', '--mtu', '65520', '-c', '4242', 'tap0']);
            list($code, $recs) = containersRun($fx, ['diagnose', '--network'], ['listener_tables' => $tables]);
            check($code === 0 && $recs[0]['helper_running'] === true && !in_array('network-helper-missing', array_column($recs, 'kind'), true), 'with the helper alive: ' . json_encode($recs));
            list($code) = containersRun($fx, ['diagnose', 'web-1', '--network']);
            check($code === 1, 'diagnose takes one form at a time');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: dump streams a MariaDB dump to a new 600 file through docker exec, naming the password variable, never its value' => function () {
        $dump = "-- MariaDB dump 10.19\nCREATE DATABASE app;\n-- Dump completed on 2026-10-19 10:00:00\n";
        $fx = containersFixture(['containers' => [advC('db', ['image' => 'mariadb:11', 'env' => ['MARIADB_ROOT_PASSWORD=s3cretvalue99', 'PATH=/usr/bin'], 'exec_out' => $dump])]]);
        try {
            list($code, $recs, $out) = containersRun($fx, ['dump', 'db']);
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['complete'] === true, 'dump: ' . $out);
            $r = $rows[0];
            check($r['engine'] === 'mysql' && $r['as'] === 'root' && $r['complete'] === true && $r['bytes'] === strlen($dump), 'record: ' . json_encode($r));
            check(preg_match('#^' . preg_quote(realpath($fx['home']), '#') . '/docker/dumps/db-\d{8}-\d{6}Z\.sql$#', $r['file']) === 1, 'default file: ' . $r['file']);
            check((string) file_get_contents($r['file']) === $dump && (fileperms($r['file']) & 0777) === 0600 && $r['sha256'] === hash('sha256', $dump), 'content, mode, hash');
            $exec = array_values(array_filter(containersState($fx)['calls'], function ($c) {
                return $c[0] === 'exec';
            }));
            check(count($exec) === 1 && $exec[0][2] === 'sh' && $exec[0][3] === '-c' && strpos($exec[0][4], 'MARIADB_ROOT_PASSWORD') !== false && strpos($exec[0][4], 'MYSQL_PWD') !== false, 'exec argv: ' . json_encode($exec));
            check(strpos(json_encode(containersState($fx)['calls']), 's3cretvalue99') === false && strpos($out, 's3cretvalue99') === false, 'the password value reached a docker call or the output');
            list($code, $recs) = containersRun($fx, ['dump', 'db', '--file', $r['file']]);
            check($code === 1 && strpos($recs[count($recs) - 1]['error'], 'never overwritten') !== false, 'an existing file must be refused');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: dump keeps an unfinished dump as .partial, scrubs env values from errors, and refuses what it cannot dump' => function () {
        $fx = containersFixture(['containers' => [
            advC('db', ['image' => 'lscr.io/linuxserver/mariadb:latest', 'env' => ['MYSQL_ROOT_PASSWORD=s3cretvalue99'], 'exec_out' => "-- MariaDB dump\nINSERT 1;\n"]),
            advC('bad', ['image' => 'postgres:16-alpine', 'env' => ['POSTGRES_PASSWORD=s3cretvalue99'], 'exec_code' => 1, 'exec_err' => 'pg_dumpall: error: auth failed for s3cretvalue99']),
            advC('web', ['image' => 'nginx:alpine']),
            advC('off', ['image' => 'mariadb:11', 'state' => 'exited', 'env' => ['MARIADB_ROOT_PASSWORD=x']]),
            advC('nopw', ['image' => 'mysql:8', 'env' => ['MYSQL_RANDOM_ROOT_PASSWORD=yes']])]]);
        try {
            list($code, $recs) = containersRun($fx, ['dump', 'db', '--file', '~/dumps/db.sql']);
            list($status, $rows) = advSplit($recs);
            check($code === 0 && $status['complete'] === false && $rows[0]['complete'] === false && substr($rows[0]['file'], -12) === '.sql.partial', 'unfinished: ' . json_encode($recs));
            check(strpos($rows[0]['error_text'], 'completion line') !== false && !file_exists($fx['home'] . '/dumps/db.sql'), 'no final file without the completion line');
            list($code, $recs, $out) = containersRun($fx, ['dump', 'bad']);
            list(, $rows) = advSplit($recs);
            check($code === 0 && $rows[0]['complete'] === false && $rows[0]['file'] === null && strpos($rows[0]['error_text'], 'REDACTED') !== false && strpos($out, 's3cretvalue99') === false, 'scrubbed error: ' . $out);
            check(!glob($fx['home'] . '/docker/dumps/bad-*'), 'an empty failed dump leaves no file');
            foreach ([['web', 'does not recognise'], ['off', 'is not running'], ['nopw', 'keeps no database login'], ['nope', 'no container']] as $case) {
                list($code, $recs) = containersRun($fx, ['dump', $case[0]]);
                check($code === 1 && strpos($recs[count($recs) - 1]['error'], $case[1]) !== false, $case[0] . ': ' . json_encode($recs));
            }
            check(DockerDump::engine('registry.example.com:5000/postgres:16') === 'postgres' && DockerDump::engine('docker.io/library/mysql@sha256:' . str_repeat('a', 64)) === 'mysql', 'engine from image names');
            check(DockerDump::mysqlMode(['MARIADB_USER', 'MARIADB_PASSWORD', 'MARIADB_DATABASE']) === 'mysql-user' && DockerDump::mysqlMode(['MARIADB_USER']) === null, 'mysql modes');
        } finally {
            containersDone($fx);
        }
    },
    'containers advanced: the delete guard asks before expose --public (exposure) and lets the other advanced verbs run unasked' => function () {
        $guard = dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php';
        $D = dirname(__DIR__) . '/pikkumies/tools/containers/docker/docker.php';
        foreach ([["php $D expose web --port 38001 --public", 'approve'], ["php $D expose web --port 38001", 'none'], ["php $D fix-owner ~/docker/app/config --to me", 'none'],
            ["php $D dump db", 'none'], ["php $D diagnose --network", 'none'], ["php $D show web-1 --record ~/docker/records", 'none']] as $case) {
            list(, $out) = runPhp($guard, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $case[0]]]));
            $d = json_decode($out, true);
            check((is_array($d) ? $d['action'] : 'none') === $case[1], $case[0] . ': ' . $out);
        }
    },
];
