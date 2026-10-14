<?php
/**
 * BazarrSearch: `search`: PATCH series|movies with the form action=search-missing (api/series/series.py:243-273,
 * api/movies/movies.py:200-232). Bazarr searches inside the request, so every item of one call shares the
 * ArrCommand::WAIT budget; it downloads subtitle files and deletes nothing.
 */
class BazarrSearch
{
    /** One PATCH per item, in order, within one wait; the first failure or timeout stops the rest (`not_sent`). */
    public static function run(ArrClient $c, array $found)
    {
        $wait = (int) getenv('PIKKUMIES_ARR_WAIT');
        $deadline = microtime(true) + ($wait > 0 && $wait < ArrCommand::WAIT ? $wait : ArrCommand::WAIT);
        $out = [];
        $stop = '';
        foreach ($found as $f) {
            list($kind, $i) = $f;
            $k = BazarrVerbs::KINDS[$kind];
            $r = ['id' => $kind . ':' . (int) $i[$k['id']], 'title' => ArrRecords::s($i, 'title'), 'state' => 'not_sent'];
            $left = (int) floor($deadline - microtime(true));
            if ($stop === '' && $left >= 1) {
                try {
                    $c->call('PATCH', $k['path'], [], null, ['form' => [$k['param'] => (int) $i[$k['id']], 'action' => 'search-missing'],
                        'timeout' => $left]);
                    $r['state'] = 'searched';
                } catch (CliError $e) {
                    $busy = strpos($e->getMessage(), 'no answer from port') === 0;
                    $r['state'] = $busy ? 'running' : 'failed';
                    $r['message'] = $busy ? 'still searching when the wait ran out; Bazarr goes on (see `history` later)'
                        : $e->getMessage();
                    $stop = $r['state'];
                }
            }
            $out[] = $r;
        }
        $notSent = array_filter($out, function ($r) {
            return $r['state'] === 'not_sent' || $r['state'] === 'failed';
        });
        if ($notSent) {
            throw new CliError('not done as asked: ' . implode(' | ', array_map(function ($r) {
                return "{$r['id']} ({$r['title']}): {$r['state']}" . (isset($r['message']) ? ', ' . $r['message'] : '');
            }, $out)), 'tell the user exactly this, item by item; search the rest again later');
        }
        return ['records' => $out, 'status' => ['note' => 'Bazarr downloads the subtitles it finds; `history` shows them']];
    }
}
