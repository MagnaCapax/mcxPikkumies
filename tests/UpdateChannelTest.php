<?php
/**
 * The release channel (ADR-0057): lib/UpdateChannel.php and Update::run($channel), against throwaway
 * repositories under the system temp dir: a bare origin, a working repository that pushes to it, and a
 * clone (file://, so --depth applies) standing in for an install. Needs git; no network.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureSettings.php';

return [
    'channel: latestRelease takes the newest real date tag and ignores peeled lines, junk and impossible dates' => function () {
        $s = str_repeat('a', 40);
        $ls = "$s\trefs/tags/2026-10-01\n$s\trefs/tags/2026-10-01^{}\n$s\trefs/tags/2026-10-09^{}\n$s\trefs/tags/2026-13-01\n"
            . "$s\trefs/tags/2026-02-30\n$s\trefs/tags/v2026.10.20\n$s\trefs/tags/2026-10-20-rc1\n$s\trefs/tags/2026-10-05\nwarning: junk\n$s\trefs/heads/2026-12-01\n";
        check(UpdateChannel::latestRelease($ls) === '2026-10-05', 'newest release: ' . UpdateChannel::latestRelease($ls));
        check(UpdateChannel::latestRelease('') === '' && UpdateChannel::latestRelease("$s\trefs/tags/v1.0\n") === '', 'no release must give empty');
    },
    'channel: a same-day release YYYY-MM-DD.N sorts by date then N; junk suffixes never count' => function () {
        $s = str_repeat('b', 40);
        $line = function (array $tags) use ($s) {
            return implode('', array_map(function ($t) use ($s) {
                return "$s\trefs/tags/$t\n";
            }, $tags));
        };
        $now = gmmktime(12, 0, 0, 10, 18, 2026);
        check(UpdateChannel::latestRelease($line(['2026-10-05', '2026-10-05.1', '2026-10-05.2', '2026-10-05.10', '2026-10-05.9']), $now) === '2026-10-05.10', 'N must sort as a number: .10 after .9');
        check(UpdateChannel::latestRelease($line(['2026-10-05.99', '2026-10-06']), $now) === '2026-10-06', 'a later date beats any N of an earlier one');
        check(UpdateChannel::latestRelease($line(['2026-10-05', '2026-10-05.1']), $now) === '2026-10-05.1', '.1 is after the plain date');
        foreach (['2026-10-05.0', '2026-10-05.100', '2026-10-05.x', '2026-10-05.1.2', '2026-10-05.01', '2026-10-05.', '2026-10-05.-1', '2026-02-30.1'] as $junk) {
            check(!UpdateChannel::isRelease($junk) && UpdateChannel::latestRelease($line(['2026-10-01', $junk]), $now) === '2026-10-01', "junk must never count: $junk");
        }
    },
    'channel: a tag dated after tomorrow (UTC) is ignored, so a typo cannot freeze the channel' => function () {
        $s = str_repeat('c', 40);
        $now = gmmktime(23, 30, 0, 10, 18, 2026);
        $ls = "$s\trefs/tags/2026-10-01\n$s\trefs/tags/9999-12-31\n$s\trefs/tags/2062-10-18\n$s\trefs/tags/2026-10-20\n$s\trefs/tags/2026-10-20.1\n";
        check(UpdateChannel::latestRelease($ls, $now) === '2026-10-01', 'future tags must be ignored: ' . UpdateChannel::latestRelease($ls, $now));
        check(UpdateChannel::latestRelease($ls . "$s\trefs/tags/2026-10-19.3\n", $now) === '2026-10-19.3', 'tomorrow (clock skew) still counts');
        check(UpdateChannel::latestRelease("$s\trefs/tags/2026-10-18\n", $now) === '2026-10-18', 'today counts');
        check(UpdateChannel::latestRelease("$s\trefs/tags/2026-10-01\n$s\trefs/tags/9999-12-31\n") === '2026-10-01', 'the default clock must apply the same limit');
    },
    'channel: valid channels are release and git/<branch>; option-like, traversal, spaces and shell characters are refused' => function () {
        foreach (['release', 'git/main', 'git/feature/x-1', 'git/v1.2', 'git/A_b'] as $ok) {
            check(Config::validChannel($ok), "must accept $ok");
        }
        foreach (['', 'main', 'release ', 'Release', 'git/', 'git/-x', 'git/--upload-pack=x', 'git/a..b', 'git/a b', 'git/a;rm', 'git/$(id)', 'git/a`b`', 'git/a/',
            'git/a.lock', 'git/a.', 'git/a//b', 'git/.x', 'git/a/.b', 'git/a@{1}', 'git/a~1', 'git/a^', 'git/a:b', "git/a\nb", 'git/' . str_repeat('x', 101), null, true, ['git/main']] as $bad) {
            check(!Config::validChannel($bad), 'must refuse ' . json_encode($bad));
        }
        list($ok, $msg) = Update::run('git/-x');
        check($ok === false && strpos($msg, 'update.channel is not valid') === 0, 'run() must refuse a bad channel before any git call: ' . $msg);
    },
    'channel: release fast-forwards a depth-1 clone to the newest date tag, not to main beyond it' => function () {
        $tmp = chanFixture('pk-chan-ff', 1);
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        $c2 = chanCommit("$tmp/dev", 'c2');
        chanGit("$tmp/dev", 'tag -a 2026-10-01 -m r1');
        chanGit("$tmp/dev", 'push -q origin --tags');
        chanCommit("$tmp/dev", 'c3');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $c2) {
            list($ok, $msg) = Update::run('release', $used);
            check($ok && strpos($msg, '(release 2026-10-01)') !== false && $used === 'release 2026-10-01', "annotated tag: $used / $msg");
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $c2, 'HEAD must be the tagged commit, not main');
            $c4 = chanCommit("$tmp/dev", 'c4');
            chanGit("$tmp/dev", 'tag 2026-10-05');
            chanGit("$tmp/dev", 'push -q origin --tags');
            list($ok, $msg) = Update::run('release', $used);
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === $c4 && $used === 'release 2026-10-05', "lightweight tag: $used / $msg");
            list($ok, $msg) = Update::run('release');
            check($ok && strpos($msg, 'already at the latest release 2026-10-05') === 0, "second run: $msg");
            check(UpdateChannel::describe('release') === 'channel release, at release 2026-10-05 (' . chanGit("$tmp/co", 'rev-parse --short HEAD') . ')', UpdateChannel::describe('release'));
        });
    },
    'channel: a checkout ahead of the newest release stays there, also past a shallow boundary' => function () {
        $tmp = chanFixture('pk-chan-ahead', 1);
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanCommit("$tmp/dev", 'c2');
        $c3 = chanCommit("$tmp/dev", 'c3');
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        chanIn($tmp, "$tmp/co", function () use ($tmp, $c3) {
            list($ok, $msg) = Update::run('release');
            check($ok === true && strpos($msg, 'ahead of the latest release 2026-10-01; staying here until a newer release') === 0, "must hold, exit ok: $msg");
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $c3, 'HEAD must not move backwards');
            check(UpdateChannel::describe('release') === 'channel release, at commit ' . chanGit("$tmp/co", 'rev-parse --short HEAD'), UpdateChannel::describe('release'));
            check(strpos(UpdateChannel::describe('git/a..b'), 'not valid') !== false, 'doctor must flag a bad channel');
        });
    },
    'channel: a release that diverged from HEAD is refused and nothing changes' => function () {
        $tmp = chanFixture('pk-chan-div', 1);
        chanGit($tmp, 'clone -q file://' . "$tmp/origin.git co");
        chanCommit("$tmp/dev", 'c2');
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanGit("$tmp/dev", 'push -q origin --tags');
        chanGit("$tmp/co", 'commit -q --allow-empty -m local');
        $before = chanGit("$tmp/co", 'rev-parse HEAD');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $before) {
            list($ok, $msg) = Update::run('release');
            check($ok === false && strpos($msg, 'cannot fast-forward to release 2026-10-01 (history diverged); nothing changed') === 0, $msg);
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $before, 'HEAD must be untouched');
        });
    },
    'channel: a release tag moved on origin is never adopted over the local one' => function () {
        $tmp = chanFixture('pk-chan-moved', 2);
        chanGit("$tmp/dev", 'tag 2026-10-01 HEAD~1');
        chanGit("$tmp/dev", 'push -q origin --tags');
        chanGit($tmp, 'clone -q file://' . "$tmp/origin.git co");
        chanGit("$tmp/co", 'reset -q --hard HEAD~1');
        chanIn($tmp, "$tmp/co", function () use ($tmp) {
            check(Update::run('release')[0] === true, 'first run must succeed');
            $c3 = chanCommit("$tmp/dev", 'c3');
            chanGit("$tmp/dev", "tag -f 2026-10-01 $c3");
            chanGit("$tmp/dev", 'push -q -f origin --tags');
            $before = chanGit("$tmp/co", 'rev-parse HEAD');
            list($ok, $msg) = Update::run('release');
            check($ok === false && strpos($msg, 'fetch of release 2026-10-01 failed') === 0 && chanGit("$tmp/co", 'rev-parse HEAD') === $before, $msg);
        });
    },
    'channel: with no release published, release follows main exactly as before; git/<branch> follows that branch' => function () {
        $tmp = chanFixture('pk-chan-none', 1);
        chanGit("$tmp/dev", 'tag v1.0');
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        $c2 = chanCommit("$tmp/dev", 'c2');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $c2) {
            list($ok, $msg) = Update::run('release', $used);
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === $c2 && $used === 'release (none published yet: following main)', "$used / $msg");
            chanGit("$tmp/dev", 'tag 2026-10-01');
            $c3 = chanCommit("$tmp/dev", 'c3');
            list($ok, $msg) = Update::run('git/main', $used);
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === $c3 && $used === 'git/main', "git/main must follow main past the release: $msg");
            chanGit("$tmp/dev", 'checkout -q -b next');
            chanGit("$tmp/dev", 'commit -q --allow-empty -m n1');
            chanGit("$tmp/dev", 'push -q origin next');
            list($ok, $msg) = Update::run('git/next');
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === chanGit("$tmp/dev", 'rev-parse HEAD'), "git/next must follow next: $msg");
            check(UpdateChannel::describe('git/next') === 'channel git/next, at commit ' . chanGit("$tmp/co", 'rev-parse --short HEAD'), UpdateChannel::describe('git/next'));
        });
    },
    'channel: git/<branch> follows only real branches, never a pull-request head or another ref' => function () {
        $tmp = chanFixture('pk-chan-refs', 1);
        chanGit($tmp, 'clone -q file://' . "$tmp/origin.git co");
        chanGit("$tmp/dev", 'commit -q --allow-empty -m pr');
        chanGit("$tmp/dev", 'push -q origin HEAD:refs/pull/1/head');
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanGit("$tmp/dev", 'push -q origin --tags');
        chanGit("$tmp/dev", 'reset -q --hard HEAD~1');
        $before = chanGit("$tmp/co", 'rev-parse HEAD');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $before) {
            foreach (['git/pull/1/head', 'git/refs/pull/1/head', 'git/2026-10-01'] as $channel) {
                check(Config::validChannel($channel), "$channel is a well-formed name; the fetch must be what refuses it");
                list($ok, $msg) = Update::run($channel);
                check($ok === false && strpos($msg, "fetch failed:\n") === 0 && chanGit("$tmp/co", 'rev-parse HEAD') === $before, "$channel must not be followed: $msg");
            }
            chanGit("$tmp/dev", 'checkout -q -b next');
            chanGit("$tmp/dev", 'commit -q --allow-empty -m n1');
            chanGit("$tmp/dev", 'push -q origin next');
            list($ok, $msg) = Update::run('git/next');
            check($ok && chanGit("$tmp/co", 'rev-parse HEAD') === chanGit("$tmp/dev", 'rev-parse HEAD'), "git/next must follow the branch: $msg");
        });
    },
    'channel: when the shallow history cannot be completed, update says so and changes nothing' => function () {
        $tmp = chanFixture('pk-chan-unsh', 1);
        chanGit("$tmp/dev", 'tag 2026-10-01');
        chanGit("$tmp/dev", 'push -q origin --tags');
        chanCommit("$tmp/dev", 'c2');
        chanGit($tmp, 'clone -q --depth 1 file://' . "$tmp/origin.git co");
        // ls-remote and the tag fetch name their refs; only the unshallow fetch uses the configured refspec.
        chanGit("$tmp/co", 'config remote.origin.fetch +refs/heads/no-such-branch:refs/remotes/origin/no-such-branch');
        $before = chanGit("$tmp/co", 'rev-parse HEAD');
        chanIn($tmp, "$tmp/co", function () use ($tmp, $before) {
            list($ok, $msg) = Update::run('release');
            check($ok === false && strpos($msg, 'could not compare with release 2026-10-01 (fetching the full history failed); nothing changed') === 0, $msg);
            check(chanGit("$tmp/co", 'rev-parse HEAD') === $before, 'HEAD must be untouched');
        });
    },
    'channel: update.channel round-trips through configure and install --channel=, bad values change nothing' => function () {
        check(Config::defaults()['update']['channel'] === 'release', 'the default channel must be release');
        $dir = testTempDir('pk-chan-cfg');
        try {
            $file = "$dir/config.php";
            check(ConfigureSettings::set('update.channel', 'git/main', $file)['value'] === 'git/main', 'set');
            check((require $file)['update']['channel'] === 'git/main', 'stored');
            check(ConfigureSettings::get(Config::merge(Config::defaults(), require $file), 'update.channel')['value'] === 'git/main', 'get');
            $threw = false;
            try {
                ConfigureSettings::set('update.channel', 'git/a..b', $file);
            } catch (CliError $x) {
                $threw = true;
            }
            check($threw && (require $file)['update']['channel'] === 'git/main', 'a bad channel must be refused and leave the file alone');
            ConfigureSettings::set('update.channel', 'release', $file);
            check((require $file)['update']['channel'] === 'release', 'back to release');
        } finally {
            testRmTree($dir);
        }
        list($set, , $err) = Launch::installFlags(['--channel=git/main']);
        check($set === ['update.channel' => 'git/main'] && $err === [], 'install --channel=git/main: ' . json_encode([$set, $err]));
        list($set, , $err) = Launch::installFlags(['--channel=git/-x']);
        check($set === [] && count($err) === 1 && strpos($err[0], '--channel: ') === 0, 'install --channel=git/-x must stop the install: ' . json_encode($err));
    },
];
