<?php
/** tools/system/browse/BrowseBinary.php: the agent-browser pin read from the Hermes lock, fetched and checked (ADR-0077). */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/browse.inc.php';

/** A temp local/ and a lock pinning an npm-shaped tarball (package/bin/agent-browser-<target>) built here; fetch copies it. */
function browseBinaryWorld($corrupt = false)
{
    $t = testTempDir('pk-browse-bin');
    $target = HermesInstall::target() !== '' ? HermesInstall::target() : 'linux-x64';
    mkdir("$t/src/package/bin", 0700, true);
    file_put_contents("$t/src/package/bin/agent-browser-$target", "#!/bin/sh\necho 'agent-browser 9.9.9'\n");
    file_put_contents("$t/src/package/bin/agent-browser-other-arch", "not this one\n");
    exec('tar -czf ' . escapeshellarg("$t/ab.tgz") . ' -C ' . escapeshellarg("$t/src") . ' package', $o, $code);
    check($code === 0, 'fixture tarball');
    $sha = hash_file('sha256', "$t/ab.tgz");
    $lock = ['packages' => ['agent-browser' => ['version' => '9.9.9', 'artifacts' => ['any' => ['sha256' => $corrupt ? str_repeat('0', 64) : $sha,
        'url' => 'https://registry.example.org/agent-browser-9.9.9.tgz']]]]];
    file_put_contents("$t/lock.json", json_encode($lock));
    mkdir("$t/local", 0700);
    return ['t' => $t, 'target' => $target, 'sha' => $sha, 'lock' => $lock];
}

function browseBinaryIn(array $w, callable $fn)
{
    $fetched = [];
    BrowsePaths::$local = $w['t'] . '/local';
    BrowseBinary::$lockFile = $w['t'] . '/lock.json';
    BrowseBinary::$fetch = function ($url, $dest) use ($w, &$fetched) {
        $fetched[] = $url;
        return copy($w['t'] . '/ab.tgz', $dest) ? 0 : 22;
    };
    try {
        return $fn(function () use (&$fetched) {
            return $fetched;
        });
    } finally {
        BrowsePaths::$local = null;
        BrowseBinary::$lockFile = null;
        BrowseBinary::$fetch = null;
        testRmTree($w['t']);
    }
}

return [
    'browse binary: the pin is the lock\'s agent-browser entry, this target first, else "any"; a malformed entry is no pin' => function () {
        $any = ['sha256' => str_repeat('a', 64), 'url' => 'https://registry.example.org/a.tgz'];
        $own = ['sha256' => str_repeat('B', 64), 'url' => 'https://registry.example.org/b.tgz'];
        $lock = ['packages' => ['agent-browser' => ['version' => '0.26.0', 'artifacts' => ['any' => $any, 'linux-x64' => $own]]]];
        check(BrowseBinary::pin($lock, 'linux-x64') === ['version' => '0.26.0', 'url' => $own['url'], 'sha256' => str_repeat('b', 64)], 'own target, lower-cased sha');
        check(BrowseBinary::pin($lock, 'linux-arm64')['url'] === $any['url'], 'any for other targets');
        foreach ([['sha256' => 'short', 'url' => $any['url']], ['sha256' => str_repeat('a', 64), 'url' => 'http://registry.example.org/a.tgz'], ['url' => $any['url']]] as $bad) {
            check(BrowseBinary::pin(['packages' => ['agent-browser' => ['version' => '0.26.0', 'artifacts' => ['any' => $bad]]]], 'linux-x64') === null, json_encode($bad));
        }
        check(BrowseBinary::pin(['packages' => ['agent-browser' => ['version' => '0.26.0; rm -rf ~', 'artifacts' => ['any' => $any]]]], 'linux-x64') === null, 'a version that is not a plain version');
        check(BrowseBinary::pin(['packages' => []], 'linux-x64') === null && BrowseBinary::pin([], 'linux-x64') === null, 'no entry');
        check(BrowseBinary::member('linux-x64') === 'package/bin/agent-browser-linux-x64', 'npm tarball member');
    },
    'browse binary: a matching tarball installs only this machine\'s binary, 0700, recorded, and is not fetched twice' => function () {
        $w = browseBinaryWorld();
        browseBinaryIn($w, function ($fetched) use ($w) {
            $pin = BrowseBinary::currentPin();
            check($pin !== null && $pin['sha256'] === $w['sha'], 'pin from the lock file');
            check(BrowseBinary::verify($pin) !== '', 'not installed yet');
            check(BrowseBinary::ensure($pin, $w['target']) === '', 'installed');
            $bin = BrowseBinary::path('9.9.9');
            check(is_file($bin) && (fileperms($bin) & 0777) === 0700 && strpos((string) file_get_contents($bin), '9.9.9') !== false, 'the binary, 0700');
            check(!file_exists(dirname($bin) . '/agent-browser-other-arch') && count(glob(dirname(dirname($bin)) . '/.fetch-*')) === 0, 'nothing else unpacked, no fetch dir left');
            check(BrowseBinary::verify($pin) === '' && BrowseBinary::ensure($pin, $w['target']) === '' && count($fetched()) === 1, 'verified, fetched once');
            file_put_contents($bin, "#!/bin/sh\necho changed\n");
            check(strpos(BrowseBinary::verify($pin), 'changed on disk') !== false, 'a changed binary fails the check');
            check(strpos(BrowseBinary::ensure($pin, $w['target']), 'changed on disk') !== false && count($fetched()) === 1, 'and is never silently replaced');
            check(BrowseBinary::verify($pin, false) === '', 'the cheap check (no hashing) only asks whether it was installed');
        });
    },
    'browse binary: a sha256 mismatch refuses, unpacks nothing and leaves no download behind' => function () {
        $w = browseBinaryWorld(true);
        browseBinaryIn($w, function () use ($w) {
            $pin = BrowseBinary::currentPin();
            $why = BrowseBinary::ensure($pin, $w['target']);
            check(strpos($why, 'sha256 mismatch') !== false && strpos($why, 'not installed') !== false, $why);
            $bin = BrowsePaths::dir() . '/bin';
            check(!file_exists(BrowseBinary::path('9.9.9')) && count(glob("$bin/.fetch-*")) === 0 && count(glob("$bin/*")) === 0, 'nothing installed, nothing left');
        });
    },
    'browse binary: a pin another tarball installed is not the pinned binary' => function () {
        $w = browseBinaryWorld();
        browseBinaryIn($w, function () use ($w) {
            $pin = BrowseBinary::currentPin();
            check(BrowseBinary::ensure($pin, $w['target']) === '', 'installed');
            $other = $pin;
            $other['sha256'] = str_repeat('c', 64);
            check(strpos(BrowseBinary::verify($other), 'another tarball') !== false, 'the lock moved to another tarball of the same version');
        });
    },
];
