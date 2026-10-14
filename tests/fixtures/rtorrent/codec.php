<?php
/**
 * XML-RPC values and torrent fields for the fake rTorrent (server.php): decode a request value, encode a
 * reply value, find a torrent by its exact hash, read one d.* field the way rTorrent 0.9.8 answers it.
 */
function fakeDecode($v)
{
    $c = $v->children();
    if (!count($c)) {
        return (string) $v;
    }
    $n = $c[0]->getName();
    if ($n === 'base64') {
        return ['base64' => base64_decode((string) $c[0])];
    }
    return in_array($n, ['i4', 'i8', 'int'], true) ? (int) (string) $c[0] : (string) $c[0];
}

function fakeEncode($v)
{
    if (is_array($v)) {
        return '<value><array><data>' . implode('', array_map('fakeEncode', $v)) . '</data></array></value>';
    }
    return is_int($v) ? "<value><i8>$v</i8></value>" : '<value><string>' . htmlspecialchars((string) $v, ENT_XML1) . '</string></value>';
}

function &fakeFind(array &$state, $hash)
{
    foreach ($state['torrents'] as $i => $t) {
        if ($t['hash'] === $hash) { // exact string only: rtorrent wants the hash as d.hash returns it
            return $state['torrents'][$i];
        }
    }
    throw new Exception('Could not find info-hash.');
}

function fakeGet(array $t, $cmd)
{
    $map = ['d.hash' => 'hash', 'd.name' => 'name', 'd.size_bytes' => 'size', 'd.completed_bytes' => 'done', 'd.complete' => 'complete', 'd.state' => 'state',
        'd.is_open' => 'open', 'd.is_active' => 'active', 'd.hashing' => 'hashing', 'd.is_hash_checking' => 'hashing', 'd.ratio' => 'ratio',
        'd.down.rate' => 'down_rate', 'd.up.rate' => 'up_rate', 'd.custom=addtime' => 'addtime', 'd.load_date' => 'load_date', 'd.custom1' => 'label',
        'd.directory' => 'dir', 'd.base_path' => 'base', 'd.is_multi_file' => 'multi', 'd.message' => 'message', 'd.peers_connected' => 'peers',
        'd.creation_date' => 'created', 'd.timestamp.started' => 'started', 'd.timestamp.finished' => 'finished', 'd.down.total' => 'down_total',
        'd.up.total' => 'up_total', 'd.peers_complete' => 'seeds', 'd.size_files' => 'nfiles', 'd.is_private' => 'private', 'd.priority' => 'priority',
        'd.custom5' => 'custom5'];
    $key = rtrim($cmd, '=');
    if (!isset($map[$key])) {
        throw new Exception("Command \"$key\" does not exist.");
    }
    if (isset($t[$map[$key]])) {
        return $t[$map[$key]];
    }
    return in_array($map[$key], ['name', 'label', 'dir', 'base', 'message', 'custom5'], true) ? '' : 0; // rtorrent answers "" for an unset string
}
