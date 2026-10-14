<?php
/**
 * DelugeRemove: core.remove_torrent(id, remove_data) per id (deluge/core/core.py 2.0.3 :574-589). With
 * remove_data Deluge has libtorrent delete the files (torrentmanager.py 2.1.1 :738); Deluge has no trash,
 * so the disposal is "deleted". The shared-file check and --expect ran before this, and afterwards
 * lib/DeleteRun.php waits for the files to be gone (a file still there makes it "partial"). Here the
 * tool reads back that each torrent left the client's list.
 */
class DelugeRemove
{
    public static function run(DelugeClient $c, array $natives, $withData)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        $out = [];
        foreach ($natives as $n) {
            $r = ['id' => strtolower($n), 'name' => $c->name($n), 'disposal' => 'kept', 'errors' => []];
            try {
                if ($c->rpc()->call('core.remove_torrent', [$n, $withData]) !== true) {
                    $r['errors'][] = 'Deluge did not confirm the removal';
                } elseif ($withData) {
                    $r['disposal'] = 'deleted';
                }
            } catch (CliError $e) {
                $r['errors'][] = $e->getMessage();
            }
            $out[$n] = $r;
        }
        $left = array_column($c->torrents(), 'native');
        foreach ($out as $n => $r) {
            if (in_array($n, $left, true)) {
                $out[$n]['errors'][] = 'still in the client after the removal';
                $out[$n]['disposal'] = 'kept';
            }
            $out[$n]['verified'] = !$out[$n]['errors'];
        }
        return array_values($out);
    }
}
