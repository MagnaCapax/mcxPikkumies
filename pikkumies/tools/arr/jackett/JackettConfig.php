<?php
/**
 * JackettConfig: where this user's Jackett keeps ServerConfig.json, and the three settings the tool reads from it:
 * Port, BasePathOverride and APIKey (Jackett.Common/Models/Config/ServerConfig.cs:35,39,45 at the commit in
 * README.md). Read with json_decode, PHP's own parser (nothing in the file is run), never written. Every other
 * setting (ProxyPassword, OmdbApiKey) is never read; JackettLogin reads only whether AdminPassword and AllowExternal are set.
 */
class JackettConfig
{
    const APP = ['id' => 'jackett', 'name' => 'Jackett'];
    const LABELS = ['base' => 'BasePathOverride', 'baseHint' => 'check BasePathOverride in ServerConfig.json', 'key' => 'APIKey',
        'portHint' => 'check Port and BasePathOverride in',
        'keyHint' => 'Jackett may run with another --DataFolder than this ServerConfig.json; check how it was started'];

    /** ['port', 'urlBase', 'key', 'bytes', 'valid'], or null when there is no regular file at $path. */
    public static function read($path)
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = (int) @filesize($path);
        $text = $bytes > 0 && $bytes <= ArrConfig::MAX ? (string) @file_get_contents($path, false, null, 0, ArrConfig::MAX) : '';
        $j = json_decode($text, true, 16);
        $valid = is_array($j) && (array_key_exists('APIKey', $j) || array_key_exists('Port', $j));
        $get = function ($k) use ($j, $valid) {
            return $valid && isset($j[$k]) && (is_string($j[$k]) || is_int($j[$k])) ? (string) $j[$k] : '';
        };
        return ['port' => ArrConfig::port($get('Port')), 'urlBase' => ArrConfig::urlBase($get('BasePathOverride')),
            'key' => ArrConfig::key($get('APIKey')), 'bytes' => $bytes, 'valid' => $valid];
    }

    /** source => ServerConfig.json path, in the order tried (README.md "Where Jackett is found"). */
    public static function candidates($home, array $override = [])
    {
        $out = [];
        if (isset($override['config']) && is_string($override['config']) && $override['config'] !== '') {
            $out['override'] = $override['config'];
        }
        $out['default'] = "$home/.config/Jackett/ServerConfig.json"; // RuntimeSettings.cs:38-41, ConfigurationService.cs:161
        $out['docker'] = "$home/docker/jackett/config/Jackett/ServerConfig.json"; // LinuxServer.io: XDG_CONFIG_HOME=/config
        return $out;
    }

    /**
     * Like ArrLocate::find: the first ServerConfig.json found, checked in the README's error order. $lenient: true (restart)
     * allows a missing key and port, 'key' (/health, which needs no key) only a missing key. $tables: Host's test seam.
     */
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
            $where = ['app' => 'jackett', 'source' => $source, 'config' => $path, 'family' => $family, 'home' => $home,
                'docker' => $sock, 'uid' => (int) $uid, 'keyQuery' => 'apikey', 'labels' => self::LABELS];
            if (!$c['valid']) {
                throw new CliError("Jackett's ServerConfig.json is empty or not Jackett's ({$c['bytes']} bytes, $path)",
                    'Jackett writes it on its first start: start Jackett once; this tool never edits ServerConfig.json');
            }
            if ($c['key'] === '' && !$lenient) {
                throw new CliError("no APIKey in $path: Jackett has never started successfully", 'start Jackett once, then try again');
            }
            $port = ArrLocate::ownPort(self::APP, $source, $c['port'], $where, $path, $lenient === true, $tables);
            if ($port === 0 && $lenient !== true) {
                throw new CliError("no Port in $path", 'Jackett writes it on start; check the file');
            }
            return $where + ['port' => $port, 'urlBase' => $c['urlBase'], 'key' => $c['key'], 'apiBase' => $c['urlBase'] . '/'];
        }
        throw new CliError('Jackett is not installed for this user (no ServerConfig.json in ' . implode(', ', $tried) . ")$skipped",
            'name its ServerConfig.json with the pikkumies-configure skill: set arr.jackett.config /path/ServerConfig.json');
    }
}
