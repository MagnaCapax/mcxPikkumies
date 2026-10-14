<?php
/**
 * BazarrSearch: `search`: PATCH series|movies with the form action=search-missing (api/series/series.py:243-273,
 * api/movies/movies.py:200-232). Bazarr 1.6.2 answers 204 at once and runs the search as a job in its own queue
 * (subtitles/mass_download/movies.py:24-32, series.py:25-29 in the v1.6.2-ls368 image), so `searched` means Bazarr
 * accepted it; it downloads subtitle files and deletes nothing. A search with no usable subtitle provider finds nothing
 * (the job only logs "All providers are throttled"), so GET providers (api/providers/providers.py: one row per enabled
 * provider, status "Good" or the throttle reason) is read first and such a search is not sent.
 */
class BazarrSearch
{
    /** One PATCH per item, in order, within one wait; the first failure or timeout stops the rest (`not_sent`). */
    public static function run(ArrClient $c, array $found)
    {
        $providers = self::providers($c);
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
        return ['records' => $out, 'status' => ($providers !== null ? ['providers' => $providers] : [])
            + ['note' => '`searched`: Bazarr accepted the search and runs it in the background; `history` shows what it downloads']];
    }

    /**
     * The names of the providers Bazarr can search with now (status "Good"); null when the list cannot be read (the
     * search is sent then, as before). None usable: CliError, nothing is sent.
     */
    private static function providers(ArrClient $c)
    {
        try {
            $rows = $c->call('GET', 'providers');
        } catch (CliError $e) {
            return null;
        }
        $rows = isset($rows['data']) && is_array($rows['data']) ? array_filter($rows['data'], 'is_array') : [];
        $good = [];
        $why = [];
        foreach ($rows as $p) {
            $name = ArrRecords::s($p, 'name');
            if (ArrRecords::s($p, 'status') === 'Good') {
                $good[] = $name;
            } else {
                $why[] = "$name (" . ArrRecords::s($p, 'status') . ', until ' . ArrRecords::s($p, 'retry') . ')';
            }
        }
        if (!$good) {
            throw new CliError('nothing searched: ' . ($rows ? 'every subtitle provider Bazarr has enabled is throttled: '
                . $c->redact(implode(', ', $why)) : 'Bazarr has no subtitle provider enabled, so a search cannot find anything'),
                'the user enables a provider in Bazarr (Settings, Providers) or waits for the throttle to end; nothing was sent');
        }
        return $good;
    }
}
