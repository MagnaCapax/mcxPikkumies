<?php
/**
 * JackettIndexers: the configured indexers and their test. Both go through the API-key routes of
 * ResultsController (Jackett.Server/Controllers/ResultsController.cs:163-167; the admin routes need a login cookie):
 * - the list: Torznab `t=indexers&configured=true` on the `all` meta indexer (:344-376), an XML document read with
 *   regular expressions (never an XML library: no entity surface), each value decoded as XML text;
 * - the test: GET results with no query (:188-326), the per-indexer `Indexers` part only. Its `Results` hold download
 *   links that carry the key (Jackett.Server/Services/ServerService.cs:60), so they are dropped before anything else.
 */
class JackettIndexers
{
    const TORZNAB = 'api/v2.0/indexers/all/results/torznab/api';
    const RESULTS = 'api/v2.0/indexers/all/results';

    /** Every configured indexer, full records (list prints the short form). */
    public static function all(ArrClient $c)
    {
        $xml = (string) $c->call('GET', self::TORZNAB, ['t' => 'indexers', 'configured' => 'true'], null,
            ['text' => true, 'max' => ArrClient::LIST_MAX]);
        if (preg_match('#<error\s+code="([0-9]*)"\s+description="([^"]*)"#', $xml, $e)) {
            $w = $c->where();
            if ($e[1] === '100') { // a wrong key: Torznab answers it with HTTP 200 and code 100 (ResultsController.cs:45-47,525-535)
                throw new CliError("Jackett rejected the APIKey from {$w['config']} (Torznab error 100)", JackettConfig::LABELS['keyHint']);
            }
            throw new CliError('Jackett answered with an error: ' . $c->redact(self::text($e[2])) . " (code {$e[1]})",
                'see Jackett\'s own log (View logs in its web page)');
        }
        if (!preg_match('#<indexers\s*(/>|>)#', $xml)) {
            $w = $c->where();
            throw new CliError("port {$w['port']} answered, but not with Jackett's indexer list",
                JackettConfig::LABELS['portHint'] . " {$w['config']}");
        }
        preg_match_all('#<indexer\s+id="([^"]*)"\s+configured="([^"]*)"\s*>(.*?)</indexer>#s', $xml, $m, PREG_SET_ORDER);
        return array_map(function ($x) {
            return self::record(self::text($x[1]), $x[2] === 'true', $x[3]);
        }, $m);
    }

    public static function short(array $r)
    {
        return array_intersect_key($r, array_flip(['id', 'name', 'enabled', 'privacy', 'language', 'site']));
    }

    /** The indexers $args name, each checked against the live list; one unknown id fails the call. */
    public static function resolve(array $all, array $args)
    {
        $hint = 'ids are the `id` values `list` prints (find one with `list --search <text>`)';
        if (!$args) {
            throw new CliError('name at least one indexer id', $hint);
        }
        $byId = array_column($all, null, 'id');
        $bad = [];
        foreach (array_unique($args) as $a) {
            if (!isset($byId[$a])) {
                $bad[] = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $a) ? "$a: no such configured indexer" : "$a: not an id";
            }
        }
        if ($bad) {
            throw new CliError('not found: ' . implode('; ', $bad), $hint);
        }
        return array_map(function ($a) use ($byId) {
            return $byId[$a];
        }, array_values(array_unique($args)));
    }

    /** Runs each named indexer's search (all configured ones without $ids): one record per indexer. */
    public static function test(ArrClient $c, array $all, array $ids)
    {
        $names = array_column($all, 'name', 'id');
        $want = $ids ? array_column(self::resolve($all, $ids), 'id') : array_keys($names);
        $q = $ids ? ['Tracker[]' => implode(',', $want)] : []; // ResultsController.cs:200-203: comma-separated ids
        $r = $c->call('GET', self::RESULTS, $q, null, ['timeout' => 60, 'max' => ArrClient::LIST_MAX, 'raw' => true]);
        $rows = is_array($r) && isset($r['Indexers']) && is_array($r['Indexers']) ? $r['Indexers'] : [];
        unset($r); // the Results part: download links with the key in them, never read
        $byId = [];
        foreach (array_filter($rows, 'is_array') as $t) {
            $byId[ArrRecords::s($t, 'ID')] = $t;
        }
        return array_map(function ($id) use ($byId, $names, $c) {
            $out = ['id' => $id, 'name' => $names[$id], 'valid' => false, 'results' => 0, 'elapsed_ms' => 0, 'error' => ''];
            if (!isset($byId[$id])) {
                return array_merge($out, ['error' => 'not tested: Jackett did not run this indexer for an empty search']);
            }
            $t = $byId[$id];
            $ok = isset($t['Status']) && ($t['Status'] === 2 || $t['Status'] === 'OK'); // ManualSearchResult.cs:7: OK = 2
            $n = isset($t['Results']) ? (int) $t['Results'] : 0;
            $err = strtok(ArrRecords::s($t, 'Error'), "\r\n"); // the exception text; its stack trace is left out
            $err = $ok ? ($n > 0 ? '' : 'answered with no results; Jackett\'s own test counts that as a failure')
                : ($err !== false && $err !== '' ? $err : 'failed without a message');
            return array_merge($out, ['valid' => $ok && $n > 0, 'results' => $n,
                'elapsed_ms' => isset($t['ElapsedTime']) ? (int) $t['ElapsedTime'] : 0, 'error' => $c->redact(ArrRecords::cut($err, Cli::TEXT_MAX))]);
        }, $want);
    }

    /** One <indexer> element: the list fields, its search modes, limits and top-level categories. */
    private static function record($id, $configured, $body)
    {
        $tag = function ($t) use ($body) {
            return preg_match('#<' . $t . '>(.*?)</' . $t . '>#s', $body, $x) ? self::text($x[1]) : '';
        };
        $modes = [];
        preg_match_all('#<((?:[a-z]+-)?search)\s+available="(yes|no)"\s+supportedParams="([^"]*)"#', $body, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $modes[$x[1]] = $x[2] === 'yes' ? array_values(array_filter(explode(',', self::text($x[3])), 'strlen')) : false;
        }
        preg_match_all('#<category\s+id="([0-9]+)"\s+name="([^"]*)"#', $body, $cats);
        $l = preg_match('#<limits(?:\s+default="([0-9]+)")?(?:\s+max="([0-9]+)")?#', $body, $l) ? $l : [];
        return ['id' => $id, 'name' => $tag('title'), 'enabled' => $configured, 'privacy' => $tag('type'), 'language' => $tag('language'),
            'site' => ArrErrorText::urls($tag('link')), 'description' => ArrRecords::cut($tag('description'), 500),
            'limit_default' => isset($l[1]) && $l[1] !== '' ? (int) $l[1] : null, 'limit_max' => isset($l[2]) ? (int) $l[2] : null,
            'search_modes' => $modes, 'categories' => array_map([__CLASS__, 'text'], $cats[2])];
    }

    public static function text($s)
    {
        return html_entity_decode((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
