<?php
/**
 * BazarrVerbs: the category verbs (tools/arr/README.md Table 1) plus `wanted` and `search` for Bazarr's own
 * API (Flask-RESTX under <base_url>/api, answers {data, total}; routes and fields: bazarr/api/** at the commit
 * in README.md). Ids name the Sonarr series or Radarr movie Bazarr tracks: series:<n>, movie:<n>.
 */
class BazarrVerbs
{
    const KINDS = ['series' => ['path' => 'series', 'id' => 'sonarrSeriesId', 'param' => 'seriesid'],
        'movie' => ['path' => 'movies', 'id' => 'radarrId', 'param' => 'radarrid']];

    public static function all(ArrClient $c, $kind, $length = -1, array $ids = [])
    {
        $k = self::KINDS[$kind];
        $q = ['start' => 0, 'length' => $length] + ($ids ? [$k['param'] . '[]' => $ids] : []);
        $page = $c->call('GET', $k['path'], $q, null, ['max' => ArrClient::LIST_MAX]);
        return ['rows' => array_values(array_filter(isset($page['data']) ? (array) $page['data'] : [], 'is_array')),
            'total' => isset($page['total']) ? (int) $page['total'] : 0];
    }

    /** A series or movie as `list` prints it. */
    public static function record($kind, array $i)
    {
        $k = self::KINDS[$kind];
        $missing = self::languages(isset($i['missing_subtitles']) ? $i['missing_subtitles'] : []);
        $r = ['id' => $kind . ':' . (isset($i[$k['id']]) ? (int) $i[$k['id']] : 0), 'kind' => $kind, 'title' => ArrRecords::s($i, 'title'),
            'year' => (int) ArrRecords::s($i, 'year'), 'monitored' => !empty($i['monitored']), 'path' => ArrRecords::s($i, 'path'),
            'profile_id' => isset($i['profileId']) ? (int) $i['profileId'] : 0];
        if ($kind === 'series') {
            return $r + ['missing' => isset($i['episodeMissingCount']) ? (int) $i['episodeMissingCount'] : 0,
                'episodes_with_files' => isset($i['episodeFileCount']) ? (int) $i['episodeFileCount'] : 0];
        }
        return $r + ['missing' => count($missing), 'missing_languages' => $missing,
            'subtitles' => count(isset($i['subtitles']) ? (array) $i['subtitles'] : [])];
    }

    /** ['series' => [n...], 'movie' => [n...]] from series:<n> / movie:<n>; one malformed id fails the call. */
    public static function ids(array $args)
    {
        $hint = 'ids are the `id` values `list` prints: series:<n> or movie:<n>';
        if (!$args) {
            throw new CliError('name at least one id', $hint);
        }
        $out = ['series' => [], 'movie' => []];
        foreach (array_unique($args) as $a) {
            if (!preg_match('/^(series|movie):([1-9][0-9]{0,9})$/', (string) $a, $m)) {
                throw new CliError("nothing done: $a is not an id", $hint);
            }
            $out[$m[1]][] = (int) $m[2];
        }
        return $out;
    }

    /** The items the ids name, each checked against Bazarr; one unknown id fails the whole call. */
    public static function resolve(ArrClient $c, array $args)
    {
        $found = [];
        $bad = [];
        foreach (self::ids($args) as $kind => $nums) {
            $rows = $nums ? self::all($c, $kind, -1, $nums)['rows'] : [];
            $byId = array_column($rows, null, self::KINDS[$kind]['id']);
            foreach ($nums as $n) {
                if (isset($byId[$n])) {
                    $found[] = [$kind, $byId[$n]];
                } else {
                    $bad[] = "$kind:$n: Bazarr has no such $kind";
                }
            }
        }
        if ($bad) {
            throw new CliError('nothing done: ' . implode('; ', $bad), 'find the id with `list --search <title>`');
        }
        return $found;
    }

    /** Wanted episodes and movies: the items with missing subtitles. */
    public static function wanted(ArrClient $c, array $opt)
    {
        list($ep, $epNote, $epTotal) = ArrPages::get($c, 'episodes/wanted', ['start', 'length', 'data', 'total'], [], $opt);
        list($mv, $mvNote, $mvTotal) = ArrPages::get($c, 'movies/wanted', ['start', 'length', 'data', 'total'], [], $opt);
        $out = [];
        foreach ($ep as $e) {
            $out[] = ['kind' => 'episode', 'item' => 'series:' . (int) ArrRecords::s($e, 'sonarrSeriesId'),
                'episode_id' => (int) ArrRecords::s($e, 'sonarrEpisodeId'), 'title' => ArrRecords::s($e, 'seriesTitle'),
                'episode' => ArrRecords::s($e, 'episode_number'), 'episode_title' => ArrRecords::s($e, 'episodeTitle'),
                'missing_languages' => self::languages(isset($e['missing_subtitles']) ? $e['missing_subtitles'] : [])];
        }
        foreach ($mv as $m) {
            $out[] = ['kind' => 'movie', 'item' => 'movie:' . (int) ArrRecords::s($m, 'radarrId'), 'title' => ArrRecords::s($m, 'title'),
                'missing_languages' => self::languages(isset($m['missing_subtitles']) ? $m['missing_subtitles'] : [])];
        }
        return ['records' => $out, 'status' => ['episodes_wanted' => $epTotal, 'movies_wanted' => $mvTotal] + $epNote + $mvNote];
    }

    /** Language names, with ":forced" / ":hi" when the subtitle is that kind. */
    public static function languages($list)
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $l) {
            if (is_array($l)) {
                $out[] = ArrRecords::s($l, 'name') . (!empty($l['forced']) ? ':forced' : '') . (!empty($l['hi']) ? ':hi' : '');
            }
        }
        return $out;
    }
}
