<?php
/**
 * ArrAdd: `add` for the library apps (tools/arr/README.md Table 2): by prefixed metadata id only,
 * looked up with that exact term, with the one quality profile and root folder there is (or the ones
 * --profile/--root name), monitored, and the search choice sent explicitly.
 */
class ArrAdd
{
    public static function add(ArrClient $c, array $app, array $args, array $opt)
    {
        $forms = implode(', ', array_map(function ($p) {
            return "$p:<n>";
        }, array_keys($app['prefixes'])));
        if (!$args) {
            throw new CliError("add needs ids from `lookup` ($forms)", 'lookup "<title>" first');
        }
        $ids = [];
        foreach (array_unique($args) as $a) {
            $m = ArrApps::parseMetaId($app, $a);
            if ($m === null) {
                throw new CliError("nothing added: $a is not an id {$app['name']} adds by ($forms)",
                    'take the meta_id from `lookup`; plain numbers are library ids');
            }
            $ids[] = $m;
        }
        $want = function ($k) use ($opt) {
            return isset($opt[$k]) ? $opt[$k] : null;
        };
        $profile = self::pick((array) $c->call('GET', 'qualityprofile'), $want('profile'), 'name', 'quality profile', '--profile');
        $root = self::pick((array) $c->call('GET', 'rootfolder'), $want('root'), 'path', 'root folder', '--root');
        $have = array_column(Arr::all($c, $app), 'id', $app['exists']);
        $search = empty($opt['no-search']);
        $out = [];
        foreach ($ids as $m) {
            list($prefix, $value) = $m;
            $field = $app['prefixes'][$prefix];
            $r = ['meta_id' => "$prefix:$value", 'result' => 'failed', 'id' => 0, 'title' => '', 'search' => false];
            $found = null;
            $hits = (array) $c->call('GET', $app['item'] . '/lookup', ['term' => "$prefix:$value"], null, ['timeout' => 60, 'raw' => true]);
            foreach ($hits as $i) {
                if (is_array($i) && isset($i[$field]) && strtolower((string) $i[$field]) === $value) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                $out[] = $r + ['error' => 'not found in the metadata database'];
                continue;
            }
            $r['title'] = ArrRecords::s($found, 'title');
            if (!empty($found[$app['exists']]) && isset($have[$found[$app['exists']]])) {
                $out[] = array_merge($r, ['result' => 'exists', 'id' => (int) $have[$found[$app['exists']]]]);
                continue;
            }
            $body = array_merge($found, $app['addFields'], ['qualityProfileId' => (int) $profile['id'], 'rootFolderPath' => $root['path'],
                'addOptions' => $app['addOptions'] + [$app['searchOption'] => $search]]);
            unset($body['id']);
            try {
                $made = $c->call('POST', $app['item'], [], $body);
                $out[] = array_merge($r, ['result' => 'added', 'id' => isset($made['id']) ? (int) $made['id'] : 0, 'search' => $search]);
            } catch (CliError $e) {
                $out[] = $r + ['error' => $e->getMessage()];
            }
        }
        if (!array_filter($out, function ($r) {
            return $r['result'] !== 'failed';
        })) {
            throw new CliError('nothing added: ' . implode('; ', array_map(function ($r) {
                return $r['meta_id'] . ': ' . $r['error'];
            }, $out)), 'check the ids with `lookup`');
        }
        $note = $search ? 'started for added items (may download; check `stats` free disk)' : 'not started (--no-search)';
        return ['records' => $out, 'status' => ['search' => $note]];
    }

    /** The one profile or root folder to use: named by --profile/--root (id, name or path), or the only one there is. */
    private static function pick(array $rows, $want, $key, $what, $flag)
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        $names = implode(', ', array_map(function ($r) use ($key) {
            return $r['id'] . ' ' . (isset($r[$key]) ? $r[$key] : '');
        }, $rows));
        if (!$rows) {
            throw new CliError("nothing added: the app has no $what",
                "the user adds one in the app's settings; this tool does not create them");
        }
        if ($want === null || $want === true) {
            if (count($rows) === 1) {
                return $rows[0];
            }
            throw new CliError("nothing added: choose a $what with $flag", "{$what}s: $names");
        }
        $norm = function ($s) {
            return rtrim(strtolower((string) $s), '/');
        };
        foreach ($rows as $r) {
            if ((string) $r['id'] === (string) $want || (isset($r[$key]) && $norm($r[$key]) === $norm($want))) {
                return $r;
            }
        }
        throw new CliError("nothing added: no $what \"$want\"", "{$what}s: $names");
    }
}
