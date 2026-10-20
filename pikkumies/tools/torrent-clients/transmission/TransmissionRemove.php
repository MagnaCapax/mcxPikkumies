<?php
/**
 * TransmissionRemove: `torrent-remove` with `ids` and `delete-local-data` (rpc-spec.md on the 4.0.6
 * tag, section 3.5). Transmission has no trash, so a delete with data is always "deleted", never
 * "trashed". The call's response carries no per-torrent confirmation, so the list is read back the
 * way Deluge's unit does.
 */
class TransmissionRemove
{
    public static function run(TransmissionClient $c, array $natives, $withData)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        $names = [];
        foreach ($natives as $n) {
            $names[$n] = $c->name($n);
        }
        $out = [];
        try {
            $c->rpc()->call('torrent-remove', ['ids' => array_values($natives), 'delete-local-data' => (bool) $withData]);
        } catch (CliError $e) {
            foreach ($natives as $n) {
                $out[$n] = ['id' => strtolower($n), 'name' => $names[$n], 'disposal' => 'kept', 'errors' => [$e->getMessage()], 'verified' => false];
            }
            return array_values($out);
        }
        $left = array_column($c->torrents(), 'native');
        foreach ($natives as $n) {
            $gone = !in_array($n, $left, true);
            $out[$n] = ['id' => strtolower($n), 'name' => $names[$n],
                'disposal' => $gone && $withData ? 'deleted' : 'kept',
                'errors' => $gone ? [] : ['still in the client after the removal']];
            $out[$n]['verified'] = !$out[$n]['errors'];
        }
        return array_values($out);
    }
}
