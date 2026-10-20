<?php
/**
 * TransmissionPause: start and stop with `torrent-start` / `torrent-stop` (rpc-spec.md on the 4.0.6
 * tag, section 3.1). Both act on EVERY torrent when `ids` is omitted, so an empty list never reaches
 * them (Torrents::natives). Neither call's response carries a confirmation, so the state is read back
 * for up to 3 s, the same way Deluge's unit does.
 */
class TransmissionPause
{
    public static function run(TransmissionClient $c, $method, array $natives, $pausing)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        $c->rpc()->call($method, ['ids' => array_values($natives)]);
        $want = array_flip($natives);
        $now = [];
        for ($i = 0; $i < 15; $i++) {
            $now = array_filter($c->torrents(), function ($t) use ($want) {
                return isset($want[$t['native']]);
            });
            $ok = count($now) === count($want) && !array_filter($now, function ($t) use ($pausing) {
                return ($t['state'] === 'paused') !== $pausing;
            });
            if ($ok) {
                break;
            }
            usleep(200000);
        }
        return array_values(array_map(function ($t) use ($pausing) {
            return ['id' => $t['id'], 'name' => $t['name'], 'state' => $t['state'], 'verified' => ($t['state'] === 'paused') === $pausing];
        }, $now));
    }
}
