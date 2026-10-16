<?php
/**
 * Edge cases of the rtorrent unit found in review: a .torrent with no `info` key, a base32 btih's exact hex
 * value, RtorrentScgi's EACCES diagnosis, and RtorrentXmlRpc's DOCTYPE refusal and i4/i8 boundary. Self-contained:
 * requires its own dependencies directly rather than relying on another test file's load order.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Add.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/XmlRpc.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Scgi.php';
require_once __DIR__ . '/support.php';

return [
    'rtorrent add: a bencoded dict with no info key is refused as "not a valid .torrent file", never hashed as garbage' => function () {
        $msg = null;
        try {
            RtorrentAdd::infoHash('d4:name1:ze', 'x.torrent');
        } catch (CliError $e) {
            $msg = $e->getMessage();
        }
        check($msg !== null && strpos($msg, 'not a valid .torrent file') !== false, 'a dict with no info key was hashed instead of refused: ' . $msg);
        // defect this catches: hashing whatever Bencode::dict() returns for 'info' without first checking it
        // exists would silently compute a bogus id (sha1 of an empty span) instead of refusing the file.
    },
    'rtorrent add: a 32-character base32 btih converts to the exact literal upper-case hex id rtorrent itself reports' => function () {
        // Independently verified: Python base64.b32decode('CEEBWWC2JZ5UBABD64I6IOWGCZE7UTFL').hex() ==
        // '11081b585a4e7b408023f711e43ac61649fa4cab' (not re-derived from this function's own bit-packing).
        check(RtorrentAdd::base32hex('CEEBWWC2JZ5UBABD64I6IOWGCZE7UTFL') === '11081B585A4E7B408023F711E43AC61649FA4CAB',
            'base32hex did not match the independently computed value: ' . RtorrentAdd::base32hex('CEEBWWC2JZ5UBABD64I6IOWGCZE7UTFL'));
    },
    'RtorrentScgi::call reports a socket whose directory this account cannot enter as "permission denied", not "busy" or "not running"' => function () {
        $dir = testTempDir();
        mkdir("$dir/sub", 0700, true);
        chmod("$dir/sub", 0000);
        $scgi = new RtorrentScgi("unix://$dir/sub/sock", 1);
        $msg = null;
        try {
            $scgi->call('system.pid');
        } catch (CliError $e) {
            $msg = $e->getMessage();
        } finally {
            chmod("$dir/sub", 0700); // testRmTree needs to enter it again
        }
        check($msg !== null && strpos($msg, 'permission denied') !== false && strpos($msg, 'busy') === false, 'an EACCES on the socket directory was diagnosed as busy/not-running instead of permission denied: ' . $msg);
        // defect this catches: connectError() checking only stripos($errstr,'refused') and the ETIMEDOUT/EAGAIN
        // codes, without an errno===13 / 'denied' branch, would misreport an ownership mismatch as a busy rtorrent
        // the agent should "wait a minute" for, instead of the real, unfixable-by-waiting permission problem.
        testRmTree($dir);
    },
    'RtorrentXmlRpc::response refuses a reply containing <!DOCTYPE before any XML parsing is attempted' => function () {
        $msg = null;
        try {
            RtorrentXmlRpc::response('<!DOCTYPE foo><a/>', 'some.method');
        } catch (CliError $e) {
            $msg = $e->getMessage();
        }
        check($msg !== null && strpos($msg, 'not an XML-RPC reply') !== false, 'a DOCTYPE reply was not refused: ' . $msg);
        // defect this catches: relying solely on LIBXML_NONET to stop an XXE payload would still let
        // simplexml_load_string() parse (and possibly misbehave on) a malicious DOCTYPE; the literal pre-parse
        // check refuses it before simplexml ever sees the string.
    },
    'RtorrentXmlRpc::value picks i8 over i4 at the exact 32-bit signed boundary, both directions' => function () {
        check(strpos(RtorrentXmlRpc::value(2147483647), '<i4>') !== false, 'the largest i4 value was not encoded as i4');
        check(strpos(RtorrentXmlRpc::value(2147483648), '<i8>') !== false, 'one past the largest i4 value was not encoded as i8');
        check(strpos(RtorrentXmlRpc::value(-2147483648), '<i4>') !== false, 'the smallest i4 value was not encoded as i4');
        check(strpos(RtorrentXmlRpc::value(-2147483649), '<i8>') !== false, 'one past the smallest i4 value was not encoded as i8');
        // defect this catches: a boundary written as > 2147483648 or >= instead of > 2147483647 would encode the
        // largest legal i4 (2147483647) as i8, which some XML-RPC readers (rtorrent's own) may reject or mis-size.
    },
];
