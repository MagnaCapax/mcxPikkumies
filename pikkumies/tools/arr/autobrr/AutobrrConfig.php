<?php
/**
 * AutobrrConfig: where this user's autobrr keeps config.toml, the address the API answers on, and the API key
 * the user stored with `login` (autobrr keeps its keys in its database, and PMSS creates none:
 * install-media-stack.sh:1790 makes only a login user). config.toml is read with regular expressions, never a
 * TOML library and never evaluated: only top-level `port`, `baseUrl` and `baseUrlModeLegacy` before the first
 * [table]. The API sits at <baseUrl>api, or at /api in the legacy mode (internal/http/server.go:263-302;
 * defaults port 7474, baseUrl "/", legacy true: internal/config/config.go:262-270); on PMSS where the installer's
 * environment puts it (installerBase()).
 */
class AutobrrConfig
{
    const APP = ['id' => 'autobrr', 'name' => 'Autobrr'];
    const LABELS = ['base' => 'baseUrl', 'baseHint' => 'check baseUrl and baseUrlModeLegacy in config.toml',
        'key' => 'stored API key; the address comes', 'portHint' => 'check port and baseUrl in',
        'keyHint' => 'the key may have been deleted in autobrr: the user creates a new one there (Settings, API keys)'
            . ' and runs `login` again in a terminal'];

    /** ['port', 'apiBase', 'urlBase', 'bytes', 'valid'], or null when there is no regular file at $path. */
    public static function read($path)
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = (int) @filesize($path);
        $text = $bytes > 0 && $bytes <= ArrConfig::MAX ? (string) @file_get_contents($path, false, null, 0, ArrConfig::MAX) : '';
        $top = preg_split('/^[ \t]*\[/m', $text)[0]; // keys before the first [table] only
        $get = function ($key) use ($top) {
            $re = '/^[ \t]*' . $key . '[ \t]*=[ \t]*(?:"([^"\\\\\n]*)"|\'([^\'\n]*)\'|([A-Za-z0-9._-]+))[ \t]*(?:#.*)?$/mi';
            return preg_match($re, $top, $m) ? $m[1] . (isset($m[2]) ? $m[2] : '') . (isset($m[3]) ? $m[3] : '') : null;
        };
        $port = $get('port');
        $base = $get('baseUrl');
        $legacy = $get('baseUrlModeLegacy');
        $base = $base === null ? '/' : $base;
        $okBase = (bool) preg_match('#^/[A-Za-z0-9._~/-]*$#', $base) && strpos($base, '..') === false;
        $legacy = $legacy === null || strtolower($legacy) === 'true';
        return ['port' => $port === null ? 7474 : ArrConfig::port($port), 'apiBase' => $legacy ? '/api/' : ($okBase ? $base . 'api/' : ''),
            'urlBase' => $legacy ? '' : rtrim($base, '/'), 'bytes' => $bytes, 'valid' => $text !== ''];
    }

    /**
     * PMSS starts autobrr with AUTOBRR__BASE_URL=/public-<user>/autobrr/ and AUTOBRR__BASE_URL_MODE_LEGACY=false
     * (install-media-stack.sh:104,1987, and the panel's alias :2562), and autobrr lets those override config.toml
     * (internal/config/config.go:254-255 load then loadFromEnv, :308-314). So on PMSS the API is where the installer
     * puts it, whatever config.toml says; a config.toml that says otherwise is named in `baseNote`.
     */
    public static function installerBase(array $c, $uid)
    {
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid((int) $uid) : false;
        $user = is_array($pw) && isset($pw['name']) ? (string) $pw['name'] : '';
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $user) || strpos($user, '..') !== false) {
            return $c + ['baseFrom' => 'config.toml', 'baseNote' => 'the user name is unknown: the installer\'s base path was not used'];
        }
        $api = "/public-$user/autobrr/api/";
        $note = $c['apiBase'] === $api ? [] : ['baseNote' => 'config.toml puts the API at '
            . ($c['apiBase'] === '' ? '(not usable)' : $c['apiBase']) . ", but the PMSS installer starts autobrr with it at $api"
            . ' (its environment overrides config.toml); used the installer\'s'];
        return array_merge($c, ['apiBase' => $api, 'urlBase' => "/public-$user/autobrr", 'baseFrom' => 'installer environment'], $note);
    }

    /** source => config.toml path, in the order tried (README.md "Where autobrr is found"). */
    public static function candidates($home, $family, array $override = [])
    {
        $out = [];
        if (isset($override['config']) && is_string($override['config']) && $override['config'] !== '') {
            $out['override'] = $override['config'];
        }
        $out[$family === 'pmss' ? 'pmss' : 'default'] = "$home/.config/autobrr/config.toml"; // install-media-stack.sh:1987; config.go:508
        $out['dotdir'] = "$home/.autobrr/config.toml"; // config.go:509
        $out['docker'] = "$home/docker/autobrr/config/config.toml"; // the image runs --config /config (Dockerfile:61)
        return $out;
    }

    /**
     * The first config.toml found plus the stored key, checked in the README's error order. $lenient: true (restart)
     * lets the key and a Docker port be missing, 'key' (login) only the key. $tables: Host's test seam.
     */
    public static function find($home, $family, $uid, AutobrrSecret $secret, array $override = [], $lenient = false, ?array $tables = null)
    {
        $tried = [];
        $skipped = '';
        foreach (self::candidates($home, $family, $override) as $source => $path) {
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
            $c = $source === 'pmss' ? self::installerBase($c, $uid) : $c + ['baseFrom' => 'config.toml'];
            $where = ['app' => 'autobrr', 'source' => $source, 'config' => $path, 'family' => $family, 'home' => $home,
                'docker' => $sock, 'uid' => (int) $uid, 'header' => 'X-API-Token', 'labels' => self::LABELS,
                'baseFrom' => $c['baseFrom']] + (isset($c['baseNote']) ? ['baseNote' => $c['baseNote']] : []);
            if (!$c['valid'] || $c['port'] === 0 || $c['apiBase'] === '') {
                throw new CliError("autobrr's config.toml is empty or its port or baseUrl is not usable ({$c['bytes']} bytes, $path)",
                    $family === 'pmss' ? 'the user reruns `bash ~/install-media-stack.sh`; this tool never edits config.toml'
                        : 'check the file; this tool never edits config.toml');
            }
            try {
                $key = $secret->load();
            } catch (CliError $e) { // a secrets file this tool will not trust: login (and restart) go on without it
                if ($lenient === false) {
                    throw $e;
                }
                $key = '';
            }
            if ($key === '' && $lenient === false) {
                throw new CliError('no autobrr API key is stored for this tool',
                    'the user creates an API key in autobrr (Settings, API keys)'
                    . ' and runs `php ' . dirname(__FILE__) . '/autobrr.php login` in a terminal (never in the chat)');
            }
            if ($source === 'pmss' && (file_exists("$home/.autobrrDisable") || is_link("$home/.autobrrDisable"))) {
                throw new CliError('autobrr is turned off (~/.autobrrDisable exists)',
                    'the user turned it off in the PMSS panel; only the user turns it back on there');
            }
            $port = ArrLocate::ownPort(self::APP, $source, $c['port'], $where, $path, $lenient === true, $tables);
            return $where + ['port' => $port, 'urlBase' => $c['urlBase'], 'apiBase' => $c['apiBase'], 'key' => $key];
        }
        $why = 'autobrr is not installed for this user (no config.toml in ' . implode(', ', $tried) . ")$skipped";
        throw new CliError($why, $family === 'pmss'
            ? 'the user installs it with `bash ~/install-media-stack.sh`'
            : 'install it the way the provider documents, or name its config.toml in pikkumies/local/config.php:'
                . " 'arr' => ['autobrr' => ['config' => '/path/config.toml']]");
    }
}
