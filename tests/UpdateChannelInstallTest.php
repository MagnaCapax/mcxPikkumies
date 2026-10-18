<?php
/**
 * The first-install move of the release channel (ADR-0057): UpdateChannel::firstInstallMove on fixture
 * clones, and `bin/pikkumies install` itself on a fixture checkout whose release commit holds a stub
 * bin/pikkumies, so the move and the re-run are seen without running the rest of an install (which
 * would touch the real crontab and HOME). Needs git; no network. Fixtures: tests/support.php.
 */
return [
    'channel: a first install moves a pristine clone to the newest release; never with local/, another channel or edits' => function () {
        $tmp = chanFixture('pk-chan-first', 1);
        $c1 = chanGit("$tmp/dev", 'rev-parse HEAD');
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanCommit("$tmp/dev", 'c2');
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        $head = chanGit("$tmp/co", 'rev-parse HEAD');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $c1, $head) {
            mkdir("$tmp/local", 0700);
            check(UpdateChannel::firstInstallMove('release', "$tmp/local") === [false, ''] && chanGit("$tmp/co", 'rev-parse HEAD') === $head, 'local/ exists: no move');
            check(UpdateChannel::firstInstallMove('git/main', "$tmp/nolocal") === [false, ''] && chanGit("$tmp/co", 'rev-parse HEAD') === $head, 'git/main: no move');
            file_put_contents("$tmp/co/f.txt", "edited\n");
            list($moved, $msg) = UpdateChannel::firstInstallMove('release', "$tmp/nolocal");
            check(!$moved && strpos($msg, 'tracked files were changed') !== false && chanGit("$tmp/co", 'rev-parse HEAD') === $head, "edits: no move: $msg");
            chanGit("$tmp/co", 'checkout -q -- f.txt');
            list($moved, $msg) = UpdateChannel::firstInstallMove('release', "$tmp/nolocal");
            check($moved && strpos($msg, '(release 2026-10-01)') !== false && chanGit("$tmp/co", 'rev-parse HEAD') === $c1, "pristine: moved: $msg");
            check(chanGit("$tmp/co", 'rev-parse --abbrev-ref HEAD') === 'main', 'the checkout must stay on branch main');
            check(UpdateChannel::firstInstallMove('release', "$tmp/nolocal") === [false, ''], 'a second call (the re-run install) must not move again');
        });
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $move = strpos($bin, 'UpdateChannel::firstInstallMove($channel, Config::localDir());');
        $defaults = strpos($bin, 'Config::writeDefaultsIfMissing();');
        check($move !== false && $defaults !== false && $move < $defaults, 'install must decide before it creates local/');
    },
    'channel: a checkout that was ever updated is never moved by an install, backwards least of all' => function () {
        $tmp = chanFixture('pk-chan-again', 1);
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        chanCommit("$tmp/dev", 'c2');
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanGit("$tmp/dev", 'push -q origin --tags');
        $c3 = chanCommit("$tmp/dev", 'c3');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $c3) {
            list($ok, $msg) = Update::run('git/main');
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === $c3, "setup: followed main past the release: $msg");
            // local/ deleted and the installer run again: the clone is clean and has no local/, but it has a history.
            check(UpdateChannel::firstInstallMove('release', "$tmp/nolocal") === [false, ''], 'an updated checkout must not move');
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $c3, 'HEAD must stay ahead, never back to the release');
        });
    },
    'channel: bin/pikkumies install on a fresh clone moves to the release and runs install again from its code, with the same flags' => function () {
        $tmp = testTempDir('pk-chan-bin');
        chanGit($tmp, 'init -q --bare -b main origin.git');
        chanGit($tmp, 'clone -q file://' . "$tmp/origin.git dev");
        chanGit("$tmp/dev", 'symbolic-ref HEAD refs/heads/main');
        mkdir("$tmp/dev/pikkumies/bin", 0700, true);
        file_put_contents("$tmp/dev/pikkumies/bin/pikkumies", "<?php\necho 'release code: ' . implode(' ', array_slice(\$argv, 1)) . \"\\n\";\n");
        chanGit("$tmp/dev", 'add -A');
        chanGit("$tmp/dev", 'commit -q -m release');
        $release = chanGit("$tmp/dev", 'rev-parse HEAD');
        $tag = gmdate('Y-m-d');
        chanGit("$tmp/dev", "tag $tag");
        copy(dirname(__DIR__) . '/pikkumies/bin/pikkumies', "$tmp/dev/pikkumies/bin/pikkumies");
        mkdir("$tmp/dev/pikkumies/lib", 0700);
        foreach (glob(dirname(__DIR__) . '/pikkumies/lib/*.php') as $lib) {
            copy($lib, "$tmp/dev/pikkumies/lib/" . basename($lib));
        }
        chanGit("$tmp/dev", 'add -A');
        chanGit("$tmp/dev", 'commit -q -m main');
        chanGit("$tmp/dev", 'push -q origin main --tags');
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        try {
            list($code, $out) = testWithEnv(['PIKKUMIES_CONFIG' => null], function () use ($tmp) {
                return runPhp("$tmp/co/pikkumies/bin/pikkumies", ['install', '--channel=release', '--name=Fixture']);
            });
            check($code === 0 && strpos($out, "(release $tag)") !== false, "the move must be reported: $code $out");
            check(strpos($out, "release code: install --channel=release --name=Fixture\n") !== false, "install must run again from the release's code with the same flags: $out");
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $release && !file_exists("$tmp/co/pikkumies/local"), 'HEAD at the release, nothing written before the move');
        } finally {
            testRmTree($tmp);
        }
    },
];
