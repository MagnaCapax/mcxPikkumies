<?php
/**
 * AutobrrVerbs: autobrr's read verbs (tools/arr/README.md Table 1). Routes and JSON fields: internal/http/*.go and
 * internal/domain/*.go at the commit in README.md. Every record is built from a list of allowed fields, never
 * by passing an API object through: filters, indexers, IRC networks and releases carry tracker settings, IRC
 * passwords, proxy logins and download links (domain/indexer.go Settings, domain/irc.go Pass and Auth,
 * domain/release.go download_url), and none of those may reach the output.
 */
class AutobrrVerbs
{
    /** A filter as `list` prints it (domain/filter.go Filter). */
    public static function filter(array $f)
    {
        return ['id' => (int) ArrRecords::s($f, 'id'), 'name' => ArrRecords::s($f, 'name'), 'enabled' => !empty($f['enabled']),
            'priority' => (int) ArrRecords::s($f, 'priority'), 'actions' => (int) ArrRecords::s($f, 'actions_count'),
            'actions_enabled' => (int) ArrRecords::s($f, 'actions_enabled_count'), 'indexers' => self::names($f, 'indexers'),
            'updated_at' => ArrRecords::time($f, 'updated_at')];
    }

    /** `show`: the list record plus the matching rules and the actions' names, types and states. */
    public static function filterFull(array $f)
    {
        $r = self::filter($f) + ['created_at' => ArrRecords::time($f, 'created_at')];
        foreach (['min_size', 'max_size', 'delay', 'max_downloads', 'max_downloads_unit', 'match_releases', 'except_releases', 'use_regex',
            'match_release_groups', 'except_release_groups', 'shows', 'seasons', 'episodes', 'years', 'freeleech', 'match_categories',
            'except_categories', 'smart_episode'] as $k) {
            if (isset($f[$k]) && is_scalar($f[$k])) {
                $r[$k] = $f[$k];
            }
        }
        foreach (['resolutions', 'sources', 'codecs', 'containers', 'match_hdr', 'except_hdr', 'announce_types'] as $k) {
            if (isset($f[$k]) && is_array($f[$k])) {
                $r[$k] = array_values(array_filter($f[$k], 'is_string'));
            }
        }
        $r['action_list'] = array_map(function ($a) {
            return ['name' => ArrRecords::s($a, 'name'), 'type' => ArrRecords::s($a, 'type'), 'enabled' => !empty($a['enabled'])];
        }, array_values(array_filter(isset($f['actions']) ? (array) $f['actions'] : [], 'is_array')));
        return $r;
    }

    /** A release with what autobrr did with it (domain/release.go Release, ReleaseActionStatus). */
    public static function release(array $r)
    {
        return ['id' => (int) ArrRecords::s($r, 'id'), 'event_at' => ArrRecords::time($r, 'timestamp'),
            'title' => ArrRecords::s($r, 'name'),
            'indexer' => isset($r['indexer']) && is_array($r['indexer']) ? ArrRecords::s($r['indexer'], 'name') : '',
            'filter' => ArrRecords::s($r, 'filter'), 'status' => ArrRecords::s($r, 'filter_status'),
            'size' => (int) ArrRecords::s($r, 'size'),
            'protocol' => ArrRecords::s($r, 'protocol'),
            'actions' => array_map(function ($a) {
                return ['action' => ArrRecords::s($a, 'action'), 'type' => ArrRecords::s($a, 'type'),
                    'client' => ArrRecords::s($a, 'client'),
                    'status' => ArrRecords::s($a, 'status'), 'rejections' => array_values(array_filter(isset($a['rejections'])
                        ? (array) $a['rejections'] : [], 'is_string'))];
            }, array_values(array_filter(isset($r['action_status']) ? (array) $r['action_status'] : [], 'is_array')))];
    }

    /** IRC networks as health records (domain/irc.go IrcNetworkWithHealth): name, connected, healthy, errors. */
    public static function networks(ArrClient $c)
    {
        return array_map(function ($n) {
            $errors = array_values(array_filter(isset($n['connection_errors']) ? (array) $n['connection_errors'] : [], 'is_string'));
            $type = empty($n['enabled']) ? 'notice' : (!empty($n['healthy']) ? 'ok' : 'warning');
            $state = empty($n['enabled']) ? 'disabled' : (!empty($n['connected']) ? 'connected' : 'not connected');
            return ['source' => 'irc ' . ArrRecords::s($n, 'name'), 'type' => $type,
                'message' => $state . ($errors ? ': ' . implode('; ', $errors) : ''), 'wiki' => ''];
        }, array_values(array_filter((array) $c->call('GET', 'irc'), 'is_array')));
    }

    /** `health`: the database check (healthz/readiness answers OK or 500, health.go:29-56) and every IRC network. */
    public static function health(ArrClient $c)
    {
        try {
            $ready = trim((string) $c->call('GET', 'healthz/readiness', [], null, ['text' => true]));
        } catch (CliError $e) {
            $ready = $e->getMessage();
        }
        $db = ['source' => 'database', 'type' => $ready === 'OK' ? 'ok' : 'error', 'message' => $ready, 'wiki' => ''];
        return array_merge([$db], self::networks($c));
    }

    /** `stats`: version, where it was found, process state, filter, release and IRC counts. */
    public static function stats(ArrClient $c)
    {
        $cfg = (array) $c->call('GET', 'config');
        $w = $c->where();
        $filters = array_values(array_filter((array) $c->call('GET', 'filters'), 'is_array'));
        $rel = (array) $c->call('GET', 'release/stats');
        $health = self::health($c);
        $r = ['app' => 'autobrr', 'version' => ArrRecords::s($cfg, 'version'), 'found' => $w['source'],
            'config' => str_replace($w['home'], '~', $w['config']), 'port' => $w['port'], 'url_base' => $w['urlBase'],
            'url_base_from' => isset($w['baseFrom']) ? $w['baseFrom'] : 'config.toml',
            'process' => $w['source'] === 'pmss' ? 'tmux ' . ArrShell::tmuxState('autobrr') : 'answering', 'filters' => count($filters),
            'filters_enabled' => count(array_filter(array_column($filters, 'enabled'))), 'irc_networks' => count($health) - 1,
            'health_warnings' => count(array_filter($health, function ($h) {
                return in_array($h['type'], ['warning', 'error'], true);
            }))];
        foreach (['total', 'filtered', 'filter_rejected', 'push_approved', 'push_rejected', 'push_error'] as $k) {
            $r["releases_$k"] = (int) ArrRecords::s($rel, $k . '_count'); // ReleaseStats, domain/release.go:393-400
        }
        return isset($w['baseNote']) ? $r + ['url_base_note' => $w['baseNote']] : $r;
    }

    private static function names(array $a, $k)
    {
        return array_values(array_filter(array_map(function ($i) {
            return is_array($i) ? ArrRecords::s($i, 'name') : '';
        }, isset($a[$k]) ? (array) $a[$k] : []), 'strlen'));
    }
}
