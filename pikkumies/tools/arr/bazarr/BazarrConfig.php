<?php
/**
 * BazarrConfig: where this user's Bazarr keeps config.yaml, and the three settings the tool needs from it:
 * general.port, general.base_url and auth.apikey (bazarr/app/config.py:87-90,185 at the commit in README.md).
 * The file is read line by line with regular expressions, never with a YAML library and never evaluated:
 * only a direct child of the `general:` or `auth:` section counts, so sonarr.apikey, plex.apikey and the
 * other keys of the same name are never taken. Bazarr writes the file with two-space indentation.
 */
class BazarrConfig
{
    const APP = ['id' => 'bazarr', 'name' => 'Bazarr'];
    const LABELS = ['base' => 'general.base_url', 'baseHint' => 'check general.base_url in config.yaml', 'key' => 'auth.apikey',
        'portHint' => 'check general.port and general.base_url in',
        'keyHint' => 'Bazarr may run with another --config folder than this config.yaml; check how it was started'];

    /** ['port', 'urlBase', 'key', 'bytes', 'valid'], or null when there is no regular file at $path. */
    public static function read($path)
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = (int) @filesize($path);
        $text = $bytes > 0 && $bytes <= ArrConfig::MAX ? (string) @file_get_contents($path, false, null, 0, ArrConfig::MAX) : '';
        $s = self::sections($text);
        $valid = isset($s['general']) || isset($s['auth']); // Bazarr writes every section on its first start (config.py:648)
        $get = function ($sec, $key) use ($s) {
            return isset($s[$sec][$key]) ? $s[$sec][$key] : '';
        };
        return ['port' => ArrConfig::port($get('general', 'port')), 'urlBase' => ArrConfig::urlBase($get('general', 'base_url')),
            'key' => ArrConfig::key($get('auth', 'apikey')), 'bytes' => $bytes, 'valid' => $valid];
    }

    /** section => [key => scalar] for the direct children of each top-level mapping key. */
    public static function sections($text)
    {
        $out = [];
        $sec = null;
        $indent = null;
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            if (preg_match('/^([A-Za-z0-9_]+):[ \t]*$/', $line, $m)) {
                $sec = $m[1];
                $indent = null;
            } elseif ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t") {
                $sec = null; // a top-level scalar, a list or a document marker ends the section
            } elseif ($sec !== null && preg_match('/^( +)([A-Za-z0-9_]+):[ \t]*(.*?)[ \t]*$/', $line, $m)) {
                $indent = $indent === null ? $m[1] : $indent;
                if ($m[1] === $indent && !isset($out[$sec][$m[2]])) {
                    $out[$sec][$m[2]] = self::scalar($m[3]);
                }
            }
        }
        return $out;
    }

    /** A plain, 'single' or "double" quoted scalar; anything else (a block, a list, a map) is ''. */
    public static function scalar($v)
    {
        if (preg_match("/^'((?:[^']|'')*)'$/", $v, $m)) {
            return str_replace("''", "'", $m[1]);
        }
        if (preg_match('/^"([^"\\\\]*)"$/', $v, $m)) {
            return $m[1];
        }
        return preg_match('/^[^\'"|>\[\]{}&*!%@`#][^#]*$/', $v) ? rtrim($v) : '';
    }

    /** source => config.yaml path, in the order tried (README.md "Where Bazarr is found"). */
    public static function candidates($home, array $override = [])
    {
        $out = [];
        if (isset($override['config']) && is_string($override['config']) && $override['config'] !== '') {
            $out['override'] = $override['config'];
        }
        $out['default'] = "$home/bazarr/data/config/config.yaml"; // a checkout in ~/bazarr: get_args.py:30-31, config.py:517
        $out['docker'] = "$home/docker/bazarr/config/config/config.yaml"; // LinuxServer.io: --config /config
        return $out;
    }

    /** Like ArrLocate::find: the first config.yaml found, checked in the README's error order. $tables: Host's test seam. */
    public static function find($home, $family, $uid, array $override = [], $lenient = false, ?array $tables = null)
    {
        $tried = [];
        $skipped = '';
        foreach (self::candidates($home, $override) as $source => $path) {
            $sock = $source === 'docker' ? ArrShell::dockerSocket($uid) : '';
            if ($source === 'docker' && $sock === '') {
                $skipped = ArrLocate::skippedDocker($path, $home, $uid); // never read: said in "not installed"
                continue;
            }
            $tried[] = str_replace($home, '~', $path);
            $c = self::read($path);
            if ($c === null) {
                continue;
            }
            $where = ['app' => 'bazarr', 'source' => $source, 'config' => $path, 'family' => $family, 'home' => $home,
                'docker' => $sock, 'uid' => (int) $uid, 'header' => 'X-API-KEY', 'labels' => self::LABELS];
            if (!$c['valid']) {
                throw new CliError("Bazarr's config.yaml is empty or not Bazarr's ({$c['bytes']} bytes, $path)",
                    'Bazarr writes it on its first start: start Bazarr once; this tool never edits config.yaml');
            }
            if ($c['key'] === '' && !$lenient) {
                throw new CliError("no auth.apikey in $path: Bazarr has never started successfully", 'start Bazarr once, then try again');
            }
            $port = ArrLocate::ownPort(self::APP, $source, $c['port'], $where, $path, $lenient, $tables);
            if ($port === 0 && !$lenient) {
                throw new CliError("no general.port in $path", 'Bazarr writes it on start; check the file');
            }
            return $where + ['port' => $port, 'urlBase' => $c['urlBase'], 'key' => $c['key'], 'apiBase' => $c['urlBase'] . '/api/'];
        }
        throw new CliError('Bazarr is not installed for this user (no config.yaml in ' . implode(', ', $tried) . ")$skipped",
            'name its config.yaml with the pikkumies-configure skill: set arr.bazarr.config /path/config/config.yaml');
    }
}
