<?php
/**
 * DelugePause: pause and resume with core.pause_torrents / core.resume_torrents (deluge/core/core.py 2.0.3
 * :673-680, 724-730). Both act on EVERY torrent when the list is empty (core.py:674-677, 725-728), so an
 * empty list never reaches them. The state is read back for up to 3 s (Deluge updates it on an alert).
 */
class DelugePause
{
    public static function run(DelugeClient $c, $method, array $natives, $pausing)
    {
        if (!$natives) {
            throw new CliError('no torrent selected', 'name at least one id');
        }
        $c->rpc()->call($method, [array_values($natives)]);
        $want = array_flip($natives);
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
