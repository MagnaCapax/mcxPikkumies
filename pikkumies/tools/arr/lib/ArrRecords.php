<?php
/**
 * ArrRecords: API objects -> the records every *ARR unit prints (tools/arr/README.md "Records").
 * scrub() runs on every API answer before anything else reads it:
 * - in a `fields` list (indexers, download clients, notifications, applications, import lists) a value
 *   is kept only when `privacy` is present and equals "normal"; every other value is dropped (fail
 *   closed: the Field schema's PrivacyLevel is normal|password|apiKey|userName, and a spec without
 *   the field, like Readarr's, keeps nothing);
 * - release links that carry tracker or Prowlarr keys are dropped: downloadUrl, magnetUrl, guid.
 */
class ArrRecords
{
    const DROP = ['downloadUrl', 'magnetUrl', 'guid'];

    public static function scrub($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::DROP, true)) {
                continue;
            }
            if ($k === 'fields' && is_array($v)) {
                $out[$k] = array_map([__CLASS__, 'field'], array_values(array_filter($v, 'is_array')));
                continue;
            }
            $out[$k] = is_array($v) ? self::scrub($v) : $v;
        }
        return $out;
    }

    /** One Field: name, label, and the value only when privacy is exactly "normal". */
    public static function field(array $f)
    {
        $r = ['name' => isset($f['name']) ? $f['name'] : '', 'label' => isset($f['label']) ? $f['label'] : ''];
        if (array_key_exists('privacy', $f) && $f['privacy'] === 'normal') {
            $r['value'] = isset($f['value']) && !is_array($f['value']) ? $f['value'] : (isset($f['value']) ? self::scrub($f['value']) : null);
        } else {
            $r['hidden'] = true;
        }
        return $r;
    }

    /** A library item (series or movie) as `list` prints it. */
    public static function item(array $app, array $i)
    {
        $st = isset($i['statistics']) && is_array($i['statistics']) ? $i['statistics'] : [];
        $r = ['id' => (int) $i['id'], 'title' => self::s($i, 'title'), 'year' => isset($i['year']) ? (int) $i['year'] : 0,
            'meta_id' => ArrApps::metaId($app, $i), 'monitored' => !empty($i['monitored']), 'status' => self::s($i, 'status'),
            'size' => (int) (isset($st['sizeOnDisk']) ? $st['sizeOnDisk'] : (isset($i['sizeOnDisk']) ? $i['sizeOnDisk'] : 0)),
            'path' => self::s($i, 'path'), 'added_at' => self::time($i, 'added')];
        if ($app['id'] === 'sonarr') {
            $r['episodes'] = isset($st['episodeFileCount']) ? (int) $st['episodeFileCount'] : 0;
            $r['episodes_total'] = isset($st['episodeCount']) ? (int) $st['episodeCount'] : 0;
        } else {
            $r['has_file'] = !empty($i['hasFile']);
        }
        return $r;
    }

    /** A Prowlarr indexer as `list` prints it. */
    public static function indexer(array $i)
    {
        return ['id' => (int) $i['id'], 'name' => self::s($i, 'name'), 'enabled' => !empty($i['enable']), 'protocol' => self::s($i, 'protocol'),
            'privacy' => self::s($i, 'privacy'), 'priority' => isset($i['priority']) ? (int) $i['priority'] : 0, 'implementation' => self::s($i, 'implementation'),
            'message' => isset($i['message']['message']) ? (string) $i['message']['message'] : ''];
    }

    public static function history(array $app, array $h)
    {
        $ref = $app['ref'];
        return ['id' => (int) $h['id'], 'event_at' => self::time($h, 'date'), 'event' => self::s($h, 'eventType'), 'item' => isset($h[$ref]) ? (int) $h[$ref] : 0,
            'title' => isset($h['sourceTitle']) ? (string) $h['sourceTitle'] : (isset($h['data']['query']) ? (string) $h['data']['query'] : ''),
            'successful' => array_key_exists('successful', $h) ? (bool) $h['successful'] : null];
    }

    public static function queue(array $app, array $q)
    {
        $msgs = [];
        foreach (isset($q['statusMessages']) && is_array($q['statusMessages']) ? $q['statusMessages'] : [] as $m) {
            foreach (isset($m['messages']) ? (array) $m['messages'] : [] as $t) {
                $msgs[] = (string) $t;
            }
        }
        $size = isset($q['size']) ? (int) $q['size'] : 0;
        return ['id' => (int) $q['id'], 'item' => isset($q[$app['ref']]) ? (int) $q[$app['ref']] : 0, 'title' => self::s($q, 'title'), 'status' => self::s($q, 'status'),
            'tracked' => self::s($q, 'trackedDownloadState'), 'size' => $size, 'left_bytes' => isset($q['sizeleft']) ? (int) $q['sizeleft'] : 0,
            'finishes_at' => self::time($q, 'estimatedCompletionTime'), 'client' => self::s($q, 'downloadClient'), 'protocol' => self::s($q, 'protocol'),
            'message' => trim(self::s($q, 'errorMessage') . ' ' . implode('; ', $msgs))];
    }

    public static function s(array $a, $k)
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? (string) $a[$k] : '';
    }

    /** $s cut to $n characters (UTF-8 aware when mbstring is there). */
    public static function cut($s, $n)
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
    }

    /** An API date-time as unix seconds, 0 when absent. */
    public static function time(array $a, $k)
    {
        return isset($a[$k]) && is_string($a[$k]) && ($t = strtotime($a[$k])) !== false ? $t : 0;
    }

    /** `stats`: version, start time, where it was found, process state, item count, health warnings, free disk. */
    public static function stats(ArrClient $c, array $app)
    {
        $s = (array) $c->call('GET', 'system/status');
        $w = $c->where();
        $health = (array) $c->call('GET', 'health');
        $r = ['app' => $app['name'], 'version' => self::s($s, 'version'), 'started_at' => self::time($s, 'startTime'), 'branch' => self::s($s, 'branch'),
            'found' => $w['source'], 'config' => str_replace($w['home'], '~', $w['config']), 'port' => $w['port'], 'url_base' => $w['urlBase'],
            'process' => $w['source'] === 'pmss' ? 'tmux ' . ArrShell::tmuxState($app['id']) : 'answering',
            $app['kind'] === 'library' ? $app['item'] : 'indexers' => count(Arr::all($c, $app)),
            'health_warnings' => count(array_filter($health, function ($h) {
                return is_array($h) && in_array(isset($h['type']) ? $h['type'] : '', ['warning', 'error'], true);
            }))];
        if ($app['kind'] === 'library') {
            $disks = array_values(array_filter((array) $c->call('GET', 'diskspace'), 'is_array'));
            $r['free_bytes'] = $disks ? min(array_map('intval', array_column($disks, 'freeSpace'))) : null;
            $r['disks'] = array_map(function ($d) {
                return ['path' => self::s($d, 'path'), 'free_bytes' => (int) $d['freeSpace'], 'total_bytes' => (int) $d['totalSpace']];
            }, $disks);
        }
        return $r;
    }
}
