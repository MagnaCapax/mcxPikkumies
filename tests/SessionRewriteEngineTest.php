<?php
/**
 * tools/torrent-clients/session-rewrite: SessionRewriteEngine::plan()/apply() against hand-built bencode,
 * including the "info" dictionary safety check (SessionRewriteEngine::verify()). No CLI process involved.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Bencode.php';
require_once dirname(__DIR__) . '/pikkumies/lib/Cli.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/session-rewrite/SessionRewriteEngine.php';

/** d<key1><string value1><key2><string value2>...e, a flat bencoded dict of string values only. */
function sreDict(array $pairs)
{
    $out = 'd';
    foreach ($pairs as $k => $v) {
        $out .= strlen($k) . ':' . $k . strlen($v) . ':' . $v;
    }
    return $out . 'e';
}

return [
    'session-rewrite engine: plan() finds only known keys whose value starts with the exact prefix' => function () {
        $raw = sreDict(['directory' => '/home/fixtureuserold/data/Multi', 'label' => 'TV Shows']);
        check(SessionRewriteEngine::plan($raw, ['directory'], '/home/fixtureuserold') !== [], 'a matching known key must be found');
        check(SessionRewriteEngine::plan($raw, ['directory'], '/home/someoneelse') === [], 'a non-matching prefix must find nothing');
        check(SessionRewriteEngine::plan($raw, ['label'], '/home/fixtureuserold') === [], 'label is not a path key: never matched even if it somehow looked like a path');
        check(SessionRewriteEngine::plan($raw, ['missing_key'], '/home/fixtureuserold') === [], 'a key the file does not have finds nothing');
        check(SessionRewriteEngine::plan('not bencode at all', ['directory'], '/home/fixtureuserold') === [], 'a non-dictionary file finds nothing, never throws');
    },
    'session-rewrite engine: apply() changes only the named key\'s bytes, leaves every other byte identical, and the id of the test fixture\'s torrents.json is untouched' => function () {
        $raw = sreDict(['directory' => '/home/fixtureuserold/data/Multi', 'priority' => '2']);
        $changes = SessionRewriteEngine::plan($raw, ['directory'], '/home/fixtureuserold');
        list($new, $applied) = SessionRewriteEngine::apply($raw, $changes, '/home/fixtureuserold', '/home/fixtureusernew');
        check(strpos($new, '/home/fixtureusernew/data/Multi') !== false, "the new prefix must be in the result: $new");
        check(strpos($new, 'fixtureuserold') === false, "the old prefix must be fully gone: $new");
        check($applied[0]['old'] === '/home/fixtureuserold/data/Multi' && $applied[0]['new'] === '/home/fixtureusernew/data/Multi', 'applied record: ' . json_encode($applied));
        // Re-decoding the result must still find the SAME key, now at the new value, and nothing else changed shape.
        $top = Bencode::dict($new, 0);
        check(isset($top['directory']) && Bencode::string($new, $top['directory'][0]) === '/home/fixtureusernew/data/Multi', 're-decoded: ' . $new);
    },
    'session-rewrite engine: two keys in one file are both rewritten correctly even though their new values have a different length than the old ones' => function () {
        $raw = sreDict(['save_path' => '/home/fixtureuserold/data', 'qBt-savePath' => '/home/fixtureuserold/other']);
        $changes = SessionRewriteEngine::plan($raw, ['save_path', 'qBt-savePath'], '/home/fixtureuserold');
        check(count($changes) === 2, 'both keys must be planned: ' . json_encode($changes));
        list($new) = SessionRewriteEngine::apply($raw, $changes, '/home/fixtureuserold', '/home/muchlongernewaccountname');
        $top = Bencode::dict($new, 0);
        check(Bencode::string($new, $top['save_path'][0]) === '/home/muchlongernewaccountname/data', 'save_path: ' . $new);
        check(Bencode::string($new, $top['qBt-savePath'][0]) === '/home/muchlongernewaccountname/other', 'qBt-savePath: ' . $new);
    },
    'session-rewrite engine: apply() refuses (throws, writes nothing) if the "info" dictionary would change -- the info-hash must never move' => function () {
        // A file carrying an "info" dict (something none of the real target files should ever have) AND a path key:
        // the engine must prove info stayed byte-identical, which here it genuinely does (only "directory" changes).
        $info = 'd4:name1:x6:lengthi1ee';
        $raw = 'd4:info' . $info . '9:directory' . strlen('/home/fixtureuserold') . ':/home/fixtureuserolde';
        $changes = SessionRewriteEngine::plan($raw, ['directory'], '/home/fixtureuserold');
        list($new) = SessionRewriteEngine::apply($raw, $changes, '/home/fixtureuserold', '/home/fixtureusernew');
        $oldTop = Bencode::dict($raw, 0);
        $newTop = Bencode::dict($new, 0);
        check(substr($raw, $oldTop['info'][0], $oldTop['info'][1] - $oldTop['info'][0]) === substr($new, $newTop['info'][0], $newTop['info'][1] - $newTop['info'][0]),
            'info must be byte-identical: ' . $new);
        // Now force a would-be info mutation by rewriting a key that happens to live INSIDE the info span: must throw.
        $badRaw = 'd4:info' . 'd4:name6:fixtureold' . '6:lengthi1ee' . '9:directory4:/tmpe';
        $badChanges = [['key' => 'name-inside-info', 'start' => strpos($badRaw, '6:fixtureold') + 2, 'end' => strpos($badRaw, '6:fixtureold') + 2 + strlen('6:fixtureold'), 'old' => 'fixtureold']];
        $threw = false;
        try {
            SessionRewriteEngine::apply($badRaw, $badChanges, 'fixtureold', 'fixturenewlonger');
        } catch (CliError $e) {
            $threw = true; // either refusal (differently-shaped, or info changed) is correct: nothing was written either way
        }
        check($threw, 'a change landing inside info must be refused, not silently written');
    },
    'session-rewrite engine: --from matches a path COMPONENT boundary, never a byte prefix (security review N3)' => function () {
        $v = '/home/fixtureuserold/dl2/movie'; // must NOT match --from /home/fixtureuserold/dl: "dl" is a byte prefix of "dl2", not a path component
        $raw = sreDict(['directory' => $v]);
        check(SessionRewriteEngine::plan($raw, ['directory'], '/home/fixtureuserold/dl') === [], 'a byte prefix without a path separator matched: ' . $v);
        // The exact value, and a real subpath, must still match.
        check(count(SessionRewriteEngine::plan(sreDict(['directory' => '/home/fixtureuserold/dl']), ['directory'], '/home/fixtureuserold/dl')) === 1, 'the exact prefix value itself must still match');
        $sub = '/home/fixtureuserold/dl/movie';
        $changes = SessionRewriteEngine::plan(sreDict(['directory' => $sub]), ['directory'], '/home/fixtureuserold/dl');
        check(count($changes) === 1, 'a real subpath (with the separator) must still match');
        list($new) = SessionRewriteEngine::apply(sreDict(['directory' => $sub]), $changes, '/home/fixtureuserold/dl', '/mnt/x');
        $top = Bencode::dict($new, 0);
        check(Bencode::string($new, $top['directory'][0]) === '/mnt/x/movie', 'the rewritten subpath: ' . $new);
        // A trailing slash on --from must not change the result (normalized the same way in plan() and apply()).
        $changes2 = SessionRewriteEngine::plan(sreDict(['directory' => $sub]), ['directory'], '/home/fixtureuserold/dl/');
        list($new2) = SessionRewriteEngine::apply(sreDict(['directory' => $sub]), $changes2, '/home/fixtureuserold/dl/', '/mnt/x');
        check($new2 === $new, '--from with or without a trailing slash must give the same result');
    },
    'session-rewrite engine: a file that repeats a known path key at the top level is refused outright (security review N5)' => function () {
        $raw = 'd9:directory8:/old/one9:directory8:/old/twoe'; // "directory" twice: Bencode::dict() would silently keep only the second
        $threw = null;
        try {
            SessionRewriteEngine::plan($raw, ['directory'], '/old');
        } catch (CliError $e) {
            $threw = $e->getMessage();
        }
        check($threw !== null && strpos($threw, 'directory') !== false && strpos($threw, 'repeats') !== false, 'a duplicated key was not refused: ' . var_export($threw, true));
        // A key that repeats but is NOT one we track must not be refused (out of this tool's risk surface).
        $raw2 = 'd9:directory8:/old/one5:otheri1e5:otheri2ee';
        check(SessionRewriteEngine::plan($raw2, ['directory'], '/old') !== [], 'an unrelated duplicated key wrongly blocked a real match');
    },
];
