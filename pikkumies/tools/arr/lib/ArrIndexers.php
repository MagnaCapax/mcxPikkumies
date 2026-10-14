<?php
/**
 * ArrIndexers: Prowlarr's verbs (tools/arr/README.md Table 3): releases, test, sync, and `show` for an
 * indexer. Indexer settings carry tracker keys in `fields`: ArrRecords::scrub drops every value whose
 * privacy is not "normal" before anything is printed. Releases lose downloadUrl, magnetUrl and guid.
 */
class ArrIndexers
{
    public static function verbs(array $app, callable $c)
    {
        return [
            'releases' => function ($args, $opt) use ($app, $c) {
                $query = trim(implode(' ', $args));
                if ($query === '') {
                    throw new CliError('releases needs a search text', 'releases "ubuntu 24.04" [--indexer 3,5]');
                }
                $q = ['query' => $query, 'type' => 'search', 'limit' => Arr::limit($opt), 'offset' => 0];
                if (isset($opt['indexer'])) {
                    $ids = array_map('trim', explode(',', is_string($opt['indexer']) ? $opt['indexer'] : ''));
                    if (!$ids || array_filter($ids, function ($i) {
                        return !ctype_digit($i);
                    })) {
                        throw new CliError('--indexer takes indexer ids from `list`, e.g. --indexer 3,5', 'nothing was searched');
                    }
                    Arr::resolve($app, Arr::all($c(), $app), $ids, true); // unknown ids fail before anything is searched
                    $q['indexerIds'] = array_map('intval', $ids);
                }
                $rows = array_values(array_filter((array) $c()->call('GET', 'search', $q, null, ['timeout' => 60]), 'is_array'));
                $out = array_map(function ($r) {
                    return ['title' => ArrRecords::s($r, 'title'), 'indexer' => ArrRecords::s($r, 'indexer'), 'indexer_id' => isset($r['indexerId']) ? (int) $r['indexerId'] : 0,
                        'size' => isset($r['size']) ? (int) $r['size'] : 0, 'age_hours' => isset($r['ageHours']) ? round((float) $r['ageHours'], 1) : null,
                        'seeders' => isset($r['seeders']) ? (int) $r['seeders'] : null, 'leechers' => isset($r['leechers']) ? (int) $r['leechers'] : null,
                        'protocol' => ArrRecords::s($r, 'protocol'), 'published_at' => ArrRecords::time($r, 'publishDate'), 'info_url' => ArrRecords::s($r, 'infoUrl'),
                        'categories' => array_values(array_filter(array_map(function ($k) {
                            return is_array($k) ? ArrRecords::s($k, 'name') : '';
                        }, isset($r['categories']) ? (array) $r['categories'] : []), 'strlen'))];
                }, $rows);
                // SearchController.cs:181-186: an unexpected failure inside Prowlarr is logged and answered with an empty list.
                return ['records' => $out, 'status' => $out ? [] : ['note' => 'no releases; an empty answer can also mean the search failed inside Prowlarr (see its log, System, Logs)']];
            },
            'test' => function ($args, $opt) use ($app, $c) {
                $all = Arr::all($c(), $app);
                $names = array_column($all, 'name', 'id');
                if (!$args) {
                    $r = $c()->call('POST', 'indexer/testall', [], null, ['accept' => [400], 'timeout' => 60]); // 400 = some failed (ProviderControllerBase.cs:219-238)
                    $rows = isset($r['data']) ? (array) $r['data'] : (array) $r;
                    return array_map(function ($t) use ($names) {
                        return ['id' => (int) $t['id'], 'name' => isset($names[$t['id']]) ? (string) $names[$t['id']] : '', 'valid' => !empty($t['isValid']), 'errors' => self::failures(isset($t['validationFailures']) ? $t['validationFailures'] : [])];
                    }, array_values(array_filter($rows, 'is_array')));
                }
                $out = [];
                foreach (Arr::resolve($app, $all, $args, true) as $t) {
                    $full = $c()->call('GET', "indexer/{$t['id']}", [], null, ['raw' => true, 'missing' => "no indexer {$t['id']}"]); // sent back as is, never printed
                    $r = $c()->call('POST', 'indexer/test', ['forceTest' => false], $full, ['accept' => [400], 'timeout' => 60]);
                    $code = isset($r['code']) ? $r['code'] : 200;
                    $out[] = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'valid' => $code === 200, 'errors' => $code === 200 ? [] : self::failures(isset($r['data']) ? $r['data'] : [])];
                }
                return $out;
            },
            'sync' => function ($args, $opt) use ($app, $c) {
                return ArrCommand::run($c(), $app, [[$app['sync']['name'], $app['sync']['body']]]);
            },
        ];
    }

    /** `show <id>`: the indexer record plus its settings, secrets hidden (ArrRecords::scrub ran on the answer). */
    public static function show(ArrClient $c, array $targets)
    {
        return array_map(function ($t) use ($c) {
            $i = (array) $c->call('GET', "indexer/{$t['id']}", [], null, ['missing' => "no indexer {$t['id']}"]);
            return ArrRecords::indexer($i) + ['urls' => isset($i['indexerUrls']) ? array_values((array) $i['indexerUrls']) : [], 'fields' => isset($i['fields']) ? $i['fields'] : []];
        }, $targets);
    }

    private static function failures($list)
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $f) {
            if (is_array($f) && isset($f['errorMessage'])) {
                $out[] = ArrErrorText::urls((isset($f['propertyName']) && $f['propertyName'] !== '' ? $f['propertyName'] . ': ' : '')
                    . $f['errorMessage']); // the message can hold the tracker's request URL
            }
        }
        return $out;
    }
}
