<?php
/**
 * Edge cases of the deluge unit found in review: DelugeAdd's private placeholder-label logic (via Reflection,
 * the same technique as tests/SecretWriteTest.php and tests/DetectTest.php), DelugeRpc's three distinct
 * transport-error causes, DelugeLocate's hand-rolled config scanner (braces inside a quoted string, an escaped
 * quote) and its found-config-but-no-binary distinction, DelugeRestart's mount-namespace process filter, and the
 * add path's case-normalized duplicate id. Self-contained: requires its own dependencies directly.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Http.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeAdd.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeRpc.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeLocate.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/deluge/DelugeRestart.php';
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/CliTest.php';
require_once __DIR__ . '/fixtures/deluge/helpers.php';

/** DelugeAdd::label() is private; the same Reflection technique as SecretWriteTest.php/DetectTest.php reaches it. */
function delugeEdgeLabel($item)
{
    $r = new ReflectionMethod('DelugeAdd', 'label');
    $r->setAccessible(true);
    return $r->invoke(null, $item);
}

return [
    'DelugeAdd::label strips the query string from a URL item, and falls back to "magnet" for a magnet with no dn' => function () {
        check(delugeEdgeLabel('http://example.com/show.torrent?ref=abc') === 'show.torrent', 'a URL item\'s label kept the query string: ' . delugeEdgeLabel('http://example.com/show.torrent?ref=abc'));
        check(delugeEdgeLabel('magnet:?xt=urn:btih:' . str_repeat('1', 40)) === 'magnet', 'a magnet with no dn did not fall back to "magnet": ' . delugeEdgeLabel('magnet:?xt=urn:btih:' . str_repeat('1', 40)));
        // defect this catches: using basename() on the raw item without first stripping the query string (or
        // reusing the URL branch's regex for the magnet branch) would show the user "show.torrent?ref=abc" or an
        // empty label while Deluge fetches the item, before it has a real name to report.
    },
    'DelugeRpc::transportError maps a timeout, another account\'s port, and a bare refusal to three non-overlapping, actionable messages' => function () {
        $timeout = DelugeRpc::transportError('connection timed out');
        $other = DelugeRpc::transportError('the port belongs to another account');
        $refused = DelugeRpc::transportError('Connection refused');
        check(strpos($timeout->getMessage(), 'busy') !== false, 'a timeout was not reported as busy: ' . $timeout->getMessage());
        check($other->getMessage() === 'the port belongs to another account', 'the another-account cause lost its own message: ' . $other->getMessage());
        check(strpos($refused->getMessage(), 'refused the connection') !== false, 'a bare refusal was not distinguished from a timeout: ' . $refused->getMessage());
        check($timeout->getMessage() !== $refused->getMessage() && $refused->getMessage() !== $other->getMessage(), 'two distinct causes collapsed to the same message');
        // defect this catches: a single catch-all "cannot reach deluge-web" message for every transport failure
        // would make a slow-but-alive daemon (wait) indistinguishable from another account's port (never retry).
    },
    'DelugeLocate::config does not stop brace-counting early on braces inside a quoted string, or on an escaped quote' => function () {
        $dir = testTempDir();
        file_put_contents("$dir/braces.conf", '{"opt":"a{b}c"}{"port":8112}');
        check(DelugeLocate::config("$dir/braces.conf") === ['port' => 8112], 'a brace inside a quoted string value truncated the real config: ' . json_encode(DelugeLocate::config("$dir/braces.conf")));
        file_put_contents("$dir/quote.conf", '{"opt":"a\"b"}{"port":9999}');
        check(DelugeLocate::config("$dir/quote.conf") === ['port' => 9999], 'an escaped quote ended the string early and corrupted the parse: ' . json_encode(DelugeLocate::config("$dir/quote.conf")));
        // defect this catches: counting every literal { and } without first tracking whether the scanner is
        // inside a quoted string (and whether a quote is escaped) would close the header object early on the
        // embedded "{" or "}", feeding json_decode() a truncated, invalid remainder for the real config object.
        testRmTree($dir);
    },
    'DelugeLocate::absent distinguishes "found a config but no deluged binary on PATH" from "Deluge is not installed at all"' => function () {
        $home = testTempDir();
        $withPath = testWithEnv(['PATH' => $home . '/empty-bin'], function () use ($home) {
            return DelugeLocate::absent($home, 'pmss', 'no deluge-web port found', 'web.conf')->getMessage();
        });
        $withoutFound = testWithEnv(['PATH' => $home . '/empty-bin'], function () use ($home) {
            return DelugeLocate::absent($home, 'pmss', 'no deluge-web port found')->getMessage();
        });
        check(strpos($withPath, 'found web.conf but no deluged binary') !== false, 'a config-found-but-no-binary case was not distinguished: ' . $withPath);
        check($withoutFound === 'Deluge is not installed on this machine', 'a nothing-found-at-all case gained the wrong message: ' . $withoutFound);
        check($withPath !== $withoutFound, 'the two distinct absent-Deluge causes collapsed to one message');
        // defect this catches: reporting "Deluge is not installed" whenever the binary is missing, regardless of
        // $found, would tell an operator to install Deluge when it is really just stopped, or run in a container
        // whose binary this account's PATH cannot see.
    },
    'DelugeRestart::pids matches by name and uid but ignores a same-named, same-uid process in a different mount namespace' => function () {
        $proc = testTempDir();
        mkdir("$proc/self/ns", 0700, true);
        symlink('mnt:[111]', "$proc/self/ns/mnt");
        mkdir("$proc/100/ns", 0700, true);
        file_put_contents("$proc/100/comm", "deluged\n");
        symlink('mnt:[111]', "$proc/100/ns/mnt"); // this account's own daemon
        mkdir("$proc/200/ns", 0700, true);
        file_put_contents("$proc/200/comm", "deluged\n");
        symlink('mnt:[222]', "$proc/200/ns/mnt"); // same name, same uid, a different (containerized) namespace
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        check(DelugeRestart::pids('deluged', $uid, $proc) === [100], 'a same-named process in a different mount namespace was signalled: ' . json_encode(DelugeRestart::pids('deluged', $uid, $proc)));
        // defect this catches: matching by name and uid alone (no namespace check) would have the panel's
        // restart SIGTERM/SIGKILL a Dockerized deluged owned by the same uid on the host, outside this account.
        testRmTree($proc);
    },
    'deluge add: Deluge\'s "Torrent already in session" refusal maps the id to its canonical lower case, using the fixture\'s already-seeded torrent' => function () {
        $fx = delugeFixture();
        try {
            list($code, $l, $out) = delugeRun($fx, ['add', 'magnet:?xt=urn:btih:' . str_repeat('A', 40)]);
            check($code === 0 && $l[0]['result'] === 'already in the client' && $l[0]['id'] === str_repeat('a', 40),
                'an upper-case re-submission of an already-seeded torrent was not reported as a lower-case duplicate: ' . $out);
            // defect this catches: using the regex-captured id as-is (without strtolower()) would report the id
            // in whatever case the user typed it, which may not match the id the rest of the tool treats as
            // canonical (lower case throughout, README.md "Records").
        } finally {
            delugeDone($fx);
        }
    },
];
