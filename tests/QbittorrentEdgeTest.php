<?php
/**
 * Edge cases of the qbittorrent unit found in review: the v2-hash check's exact boundary, pagination stopping
 * and duplicate-hash dedup over torrents/info, refusing a torrent with no folder info at all, refusing a login
 * attempt with no configured username before any network call, QbitSecret's reject() idempotency, and the
 * mixed-batch hash guard. Self-contained: requires its own dependencies directly (the same files qbittorrent.php
 * itself requires) rather than relying on another test file's load order.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/lib/CliHuman.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Quota.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Host.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Http.php';
require_once dirname(__DIR__) . '/pikkumies/lib/SecretFile.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Bencode.php';
foreach (['QbitSecret', 'QbitEndpoint', 'QbitApi', 'QbitProc', 'QbitRestart', 'QbitStats', 'QbitAdd', 'QbitChange', 'QbitDetails',
    'QbitExtras', 'QbittorrentClient'] as $qbitEdgeClass) {
    require_once dirname(__DIR__) . "/pikkumies/tools/torrent-clients/qbittorrent/$qbitEdgeClass.php";
}
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/CliTest.php';
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

/** [hash, name] for a small torrent whose info dict holds `meta version` and `name`, matching QbitAdd::torrentFile. */
function qbitEdgeTorrentFile($metaVersion)
{
    return 'd4:info' . "d12:meta versioni{$metaVersion}e4:name1:ze" . 'e';
}

/** A list-row for the qbittorrent fixture, built like tests/fixtures/qbittorrent/harness.php's own $t(). */
function qbitEdgeRow(array $over)
{
    return $over + ['name' => 'T', 'state' => 'uploading', 'progress' => 1, 'size' => 10, 'completed' => 10, 'ratio' => 1,
        'dlspeed' => 0, 'upspeed' => 0, 'added_on' => 1, 'category' => '', 'content_path' => '/x', 'save_path' => '/x', 'files' => [], 'props' => []];
}

return [
    'QbitAdd::torrentFile hashes with sha1 when meta version is present but not exactly 2, and with sha256 (truncated) only when it is' => function () {
        // Independently verified (not re-derived from torrentFile's own hash() call): Python
        // hashlib.sha1(b'd12:meta versioni1e4:name1:ze').hexdigest() == '890618389c1b559f216be0f5182861828fc10a77'.
        check(QbitAdd::torrentFile(qbitEdgeTorrentFile(1)) === ['890618389c1b559f216be0f5182861828fc10a77', 'z'],
            'meta version i1e (present, not 2) was not hashed with sha1: ' . json_encode(QbitAdd::torrentFile(qbitEdgeTorrentFile(1))));
        list($v2id) = QbitAdd::torrentFile(qbitEdgeTorrentFile(2));
        check($v2id !== '890618389c1b559f216be0f5182861828fc10a77' && strlen($v2id) === 40, 'meta version i2e was not hashed differently (sha256, truncated) from i1e');
        // defect this catches: a check like isset($info['meta version']) alone (any version number, not just "2")
        // would also sha256 the i1e case above, producing an id that does not match the real v1 torrent's hash.
    },
    'qbittorrent rows: pagination stops once a page returns fewer rows than the page size, with no trailing empty-page call' => function () {
        $torrents = [];
        for ($i = 0; $i < 7; $i++) {
            $torrents[] = qbitEdgeRow(['hash' => str_pad(dechex($i), 2, '0', STR_PAD_LEFT) . str_repeat('1', 38), 'name' => "T$i"]);
        }
        qbitWith(['torrents' => $torrents], function (array $fx) {
            $fx['page'] = 3;
            list($code, $out) = qbitRun($fx, ['list', '--limit', '100']);
            $calls = array_values(array_filter(qbitCalls($fx), function ($c) {
                return strpos($c, 'torrents/info') !== false;
            }));
            check($code === 0 && count($calls) === 3, '7 torrents at page size 3 did not make exactly 3 calls (3+3+1): ' . count($calls));
            // defect this catches: stopping only on an EMPTY page (never on a short one) would make a 4th,
            // always-empty call every single `list`, one wasted request per invocation forever.
        });
    },
    'qbittorrent rows: a hash answered on two different pages (an inconsistent paginated client) is counted once, not twice' => function () {
        $dupHash = '02' . str_repeat('1', 38);
        $torrents = [qbitEdgeRow(['hash' => '01' . str_repeat('1', 38), 'name' => 'A']), qbitEdgeRow(['hash' => $dupHash, 'name' => 'B']),
            qbitEdgeRow(['hash' => $dupHash, 'name' => 'B-again']), qbitEdgeRow(['hash' => '03' . str_repeat('1', 38), 'name' => 'C']),
            qbitEdgeRow(['hash' => '04' . str_repeat('1', 38), 'name' => 'D'])];
        qbitWith(['torrents' => $torrents], function (array $fx) {
            $fx['page'] = 3;
            list($code, $out) = qbitRun($fx, ['list', '--limit', '100']);
            $ids = array_column(array_filter(cliLines($out), function ($r) {
                return isset($r['id']);
            }), 'id');
            check($code === 0 && count($ids) === 4 && count(array_unique($ids)) === 4, 'a hash repeated across two pages produced a doubled/ghost torrent: ' . json_encode($ids));
            // defect this catches: building rows() by appending to a plain list instead of keying by hash would
            // give 5 rows for this fixture's 5 physical entries, one of them a ghost duplicate of "B".
        });
    },
    'QbitDetails::files refuses a torrent whose row gives neither content_path nor save_path, rather than guessing a folder' => function () {
        $hash = str_repeat('9', 40);
        $torrents = [qbitEdgeRow(['hash' => $hash, 'name' => 'NoFolder', 'content_path' => '', 'save_path' => '',
            'files' => [['index' => 0, 'name' => 'a/b.bin', 'size' => 10]]])];
        qbitWith(['torrents' => $torrents], function (array $fx) use ($hash) {
            list($code, $out) = qbitRun($fx, ['show', substr($hash, 0, 8), '--files']);
            check($code === 1 && strpos($out, 'gives no folder') !== false && strpos($out, 'cannot be listed safely') !== false,
                'a torrent with no folder info at all was not refused: ' . $out);
            // defect this catches: falling back to content_path ?: save_path ?: '' and joining it with the file
            // name would silently build a path like "/b.bin" at the filesystem root for --with-data to act on.
        });
    },
    'QbitApi::attempt refuses a login with no configured WebUI username, before any network call is made' => function () {
        $ep = new QbitEndpoint(testTempDir(), 'pmss');
        $ep->username = '';
        $secret = new QbitSecret(testTempDir());
        $api = new QbitApi($ep, $secret);
        $msg = null;
        try {
            $api->attempt('whatever');
        } catch (CliError $e) {
            $msg = $e->getMessage();
        }
        check($msg !== null && strpos($msg, 'WebUI\\Username') !== false, 'a blank username was not refused before attempting a login: ' . $msg);
    },
    'QbitSecret::reject() is idempotent: calling it twice on an already-rejected secret changes nothing further' => function () {
        $dir = testTempDir();
        $s = new QbitSecret($dir);
        $s->save('pw');
        $s->reject();
        $s->reject();
        check($s->load() === ['password' => 'pw', 'rejected' => true], 'a second reject() changed the stored secret: ' . json_encode($s->load()));
        testRmTree($dir);
    },
    'QbittorrentClient::hashes refuses a batch mixing one well-formed and one malformed id, never a silent partial send' => function () {
        $msg = null;
        try {
            QbittorrentClient::hashes([str_repeat('a', 40), 'not-a-hash']);
        } catch (CliError $e) {
            $msg = $e->getMessage();
        }
        check($msg !== null, 'a batch with one malformed id among well-formed ones was not refused');
        // defect this catches: filtering out bad ids instead of refusing the whole batch would silently
        // remove/pause/recheck only the good ids, leaving the user believing the whole batch was acted on.
    },
];
