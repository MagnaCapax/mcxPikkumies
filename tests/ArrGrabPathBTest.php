<?php
/**
 * Prowlarr's `grab` path B (docs/adr/0046 §2): in-process fetch-and-add to the user's own torrent
 * client. A static test seam (ArrGrabClient::$detect/$build) cannot cross the proc_open() boundary
 * arrRun() uses, so every case here calls ArrGrab::grab() directly against a real ArrClient pointed
 * at the fixture's fake server, with a fake TorrentClient behind the seam (tests/fixtures/arr/
 * grab-helpers.php): never a real rTorrent/qBittorrent/Deluge, never this install's own
 * local/secrets/qbittorrent. Path A and the handle cache itself are tests/ArrGrabTest.php.
 */
require_once __DIR__ . '/fixtures/arr/grab-helpers.php';

/** One `releases` row whose downloadUrl is pinned to $fx's own fake server. */
function arrGrabPathBRow(array $fx, array $over = [])
{
    $base = '/public-fake/prowlarr';
    return $over + ['id' => 0, 'guid' => 'g', 'title' => 'T', 'indexerId' => 1, 'indexer' => 'X', 'size' => 1, 'seeders' => 1,
        'protocol' => 'torrent', 'categories' => [], 'downloadUrl' => 'http://127.0.0.1:' . $fx['port'] . $base . '/1/download?apikey=K&link=X&file=t', 'magnetUrl' => ''];
}

return [
    'grab path B: --client bypasses detection and adds through the fake client; the handle is forgotten after' => function () {
        $fx = arrFixture('prowlarr');
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx, ['title' => 'Debian netinst', 'size' => 900000000, 'seeders' => 20, 'leechers' => 1])]]);
            list(, $l) = arrRun($fx, ['releases', 'debian']);
            $handle = $l[0]['handle'];
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            $r = ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$handle], ['client' => 'qbittorrent']);
            check($r['result'] === 'added' && $r['path'] === 'qbittorrent' && $r['title'] === 'Debian netinst', 'result: ' . json_encode($r));
            check(count($fake->added) === 1 && strpos($fake->added[0]['items'][0], '.torrent') !== false, 'nothing was added to the fake client');
            check(is_file($fake->added[0]['items'][0]) === false, 'the temp .torrent file was not removed after success');
            try {
                ArrReleases::take($handle, 'prowlarr');
                check(false, 'the handle was not forgotten after a successful grab');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'no such result') !== false, (string) $e->getMessage());
            }
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: more than one client present is refused naming the choices; none present is refused too' => function () {
        $fx = arrFixture('prowlarr');
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx)]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $c = arrGrabClientFor($fx);
            $account = arrGrabAccount($fx);
            ArrGrabClient::$detect = function () {
                return ['rtorrent', 'qbittorrent'];
            };
            list(, $l) = arrRun($fx, ['releases', 't']);
            try {
                ArrGrab::grab($c, $account, [$l[0]['handle']], []);
                check(false, 'ambiguous clients did not refuse');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'more than one') !== false && strpos($e->hint, 'rtorrent') !== false && strpos($e->hint, 'qbittorrent') !== false, (string) $e->getMessage());
            }
            ArrGrabClient::$detect = function () {
                return [];
            };
            list(, $l) = arrRun($fx, ['releases', 't']);
            try {
                ArrGrab::grab($c, $account, [$l[0]['handle']], []);
                check(false, 'no clients did not refuse');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'no supported torrent client') !== false, (string) $e->getMessage());
            }
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: a download link that is not this account\'s own Prowlarr is refused; nothing is added' => function () {
        $fx = arrFixture('prowlarr');
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx, ['downloadUrl' => 'http://127.0.0.1:9/evil/1/download?apikey=K&link=X&file=t'])]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            list(, $l) = arrRun($fx, ['releases', 't']);
            try {
                ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => 'qbittorrent']);
                check(false, 'a foreign host/port was fetched');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), "not this account's own Prowlarr") !== false, (string) $e->getMessage());
            }
            check(!$fake->added, 'the fake client received an add() despite the pin refusal');
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: a 301 to a magnet link is handed to the client as a magnet, never fetched further' => function () {
        $fx = arrFixture('prowlarr');
        $magnet = 'magnet:?xt=urn:btih:ABCDEF0123456789&dn=Test';
        try {
            arrSet($fx, ['downloadProxy' => ['code' => 301, 'location' => $magnet], 'releases' => [arrGrabPathBRow($fx, ['title' => 'Magnet release'])]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            list(, $l) = arrRun($fx, ['releases', 'magnet']);
            $r = ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => 'qbittorrent']);
            check($r['result'] === 'added', 'result: ' . json_encode($r));
            check($fake->added[0]['items'][0] === $magnet, 'the magnet was not handed to add() unchanged: ' . json_encode($fake->added));
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: a stored magnetUrl that is a direct magnet: goes straight to add, with no fetch at all' => function () {
        $fx = arrFixture('prowlarr');
        $magnet = 'magnet:?xt=urn:btih:FEDCBA9876543210&dn=Direct';
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx, ['title' => 'Direct magnet', 'downloadUrl' => '', 'magnetUrl' => $magnet])]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            list(, $l) = arrRun($fx, ['releases', 'direct']);
            $before = count(arrState($fx)['calls']);
            $r = ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => 'qbittorrent']);
            check($r['result'] === 'added' && $fake->added[0]['items'][0] === $magnet, 'direct magnet: ' . json_encode($r));
            check(count(arrState($fx)['calls']) === $before, 'a direct magnetUrl should need no further HTTP call to Prowlarr');
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: a magnet-only release (magnetUrl is Prowlarr\'s own proxy link) is fetched through the pin; an odd-form magnet is never handed on' => function () {
        $fx = arrFixture('prowlarr');
        $magnet = 'magnet:?xt=urn:btih:0011223344556677&dn=Proxy';
        $proxy = 'http://127.0.0.1:' . $fx['port'] . '/public-fake/prowlarr/1/download?apikey=K&link=X&file=m';
        try {
            arrSet($fx, ['downloadProxy' => ['code' => 301, 'location' => $magnet], 'releases' => [
                arrGrabPathBRow($fx, ['title' => 'Proxy magnet', 'downloadUrl' => '', 'magnetUrl' => $proxy]),
                arrGrabPathBRow($fx, ['title' => 'Odd magnet', 'guid' => 'g2', 'downloadUrl' => '', 'magnetUrl' => 'MAGNET:?xt=urn:btih:00&tr=http://t/passkey'])]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            list(, $l) = arrRun($fx, ['releases', 'magnet']);
            $byTitle = [];
            foreach ($l as $row) {
                $byTitle[$row['title']] = $row['handle'];
            }
            $r = ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$byTitle['Proxy magnet']], ['client' => 'qbittorrent']);
            check($r['result'] === 'added' && $fake->added[0]['items'][0] === $magnet, 'proxy-link magnet: ' . json_encode([$r, $fake->added]));
            try {
                ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$byTitle['Odd magnet']], ['client' => 'qbittorrent']);
                check(false, 'an odd-form magnet was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'passkey') === false, 'the odd magnet leaked into the error: ' . $e->getMessage());
            }
            check(count($fake->added) === 1, 'the odd-form magnet reached the client: ' . json_encode($fake->added));
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab: a bare --client with no name is a usage error, never a silent auto-pick' => function () {
        $fx = arrFixture('prowlarr');
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx)]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            list(, $l) = arrRun($fx, ['releases', 't']);
            try {
                ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => true]);
                check(false, 'a bare --client was accepted');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), '--client needs a name') !== false, (string) $e->getMessage());
            }
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: a non-bencode body and an oversize body are refused; the temp file never survives either' => function () {
        $fx = arrFixture('prowlarr');
        $tmp = $fx['dir'] . '/arr-tmp';
        try {
            foreach (['bad' => 'not a torrent at all', 'big' => str_repeat('x', 10485761)] as $case => $body) {
                arrSet($fx, ['downloadProxy' => ['code' => 200, 'body' => $body], 'releases' => [arrGrabPathBRow($fx, ['title' => "Case $case"])]]);
                ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
                $fake = new ArrGrabFakeClient();
                ArrGrabClient::$build = function () use ($fake) {
                    return $fake;
                };
                list(, $l) = arrRun($fx, ['releases', 'case']);
                try {
                    ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => 'qbittorrent']);
                    check(false, "$case was accepted");
                } catch (CliError $e) {
                    check($e->getMessage() !== '', "$case: " . $e->getMessage());
                }
                check(!$fake->added, "$case: the fake client received an add()");
                check(!is_dir($tmp) || !array_diff(scandir($tmp), ['.', '..']), "$case: a temp file was left behind in $tmp");
            }
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
    'grab path B: the torrent client itself refusing the release is reported, not silently dropped' => function () {
        $fx = arrFixture('prowlarr');
        $magnet = 'magnet:?xt=urn:btih:0000000000000000000000000000000000000000';
        try {
            arrSet($fx, ['releases' => [arrGrabPathBRow($fx, ['downloadUrl' => '', 'magnetUrl' => $magnet])]]);
            ArrReleases::$file = $fx['dir'] . '/secrets/releases.json';
            $fake = new ArrGrabFakeClient();
            $fake->failResult = 'error: not a magnet link, an http(s) URL or a readable .torrent file';
            ArrGrabClient::$build = function () use ($fake) {
                return $fake;
            };
            list(, $l) = arrRun($fx, ['releases', 't']);
            try {
                ArrGrab::grab(arrGrabClientFor($fx), arrGrabAccount($fx), [$l[0]['handle']], ['client' => 'qbittorrent']);
                check(false, 'the client error was swallowed');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), 'the torrent client refused the release') !== false, (string) $e->getMessage());
            }
        } finally {
            arrGrabResetSeams();
            arrDone($fx);
        }
    },
];
