<?php
/**
 * Config: defaults in code, overrides in local/config.php (gitignored, never touched by updates).
 */
class Config
{
    public static function defaults()
    {
        $home = (string) getenv('HOME');
        return [
            'provider' => [
                'endpoint' => '',                 // OpenAI-compatible base URL, e.g. https://host/v1
                'model'    => '',
                'keyFile'  => $home . '/.pikkumiesKey',
                'virsilipasKeyFile' => $home . '/.virsilipasKey', // written by the platform for its proxy; read after keyFile (ADR-0004)
            ],
            'root'  => ['disabled' => false],     // UX switch only; the OS decides what root can do
            'skills' => ['disabled' => []],        // shipped skill names to hide, e.g. after copying one (ADR-0007)
            'tools' => ['disabled' => []],         // tool categories to leave out, e.g. ['seedbox'] (ADR-0015)
            'searchFirst' => ['disabled' => false], // first-turn search hint + search-first gate (ADR-0011)
            'halt' => ['disabled' => false],        // halt-snapshot/halt-guard hooks + the one-shot judging loop (ADR-0047)
            'lowBalance' => ['disabled' => false],  // the once-per-session low-credits notice before a turn (hooks/system/low-balance, ADR-0050)
            'fileopsCheck' => ['disabled' => false], // the fileops-check hook: bulk chmod/chown/mv re-check and the file list it writes first (ADR-0048)
            // reword: a question with no local hit is turned into search terms by the model once (hooks/search/search-first/search-first.php).
            // llm.disabled: no hook ever calls the model. model: the hooks' model; '' = the light model decided at generate (local/llm.json).
            'hooks' => ['reword' => true, 'llm' => ['disabled' => false], 'model' => ''],
            'autoUpdate' => ['disabled' => false],  // daily `pikkumies update` from the user's crontab (ADR-0013)
            'cron' => ['disabled' => false],        // per-minute `cron-tick` from the user's crontab, runs Hermes' own due jobs (ADR-0025)
            'update' => ['channel' => 'release'], // what `pikkumies update` follows: release (the newest date tag) or git/<branch> (ADR-0057)
            // Hermes itself is a vanilla checkout that updates on its own path (ADR-0033); no version here.
            // lazyInstalls: let Hermes download optional packages and a browser at runtime (ADR-0021).
            'hermes' => ['lazyInstalls' => false],
            // User settings (ADR-0032). name '' = not named yet; the agent calls itself Pikkumies.
            'agent' => ['name' => ''],
            // perfLogging: memory-search and lesson-save metrics kept on this machine only (ADR-0041). telemetry: a setting only, no sender exists.
            'privacy' => ['perfLogging' => true, 'telemetry' => true],
            'sessions' => ['retentionDays' => 0],   // Hermes session history; 0 = keep forever
            'terms' => ['accepted' => false],        // set only by the person: the first interactive run or install --accept-terms
        ];
    }

    /**
     * Settings Config::set writes, with their type (ADR-0032, ADR-0059). Which change of them the configure tool makes
     * freely, asks about or leaves to the person is ConfigureTiers' decision; the provider endpoint and key files, host.*
     * and the terms are never written here (the terms only by acceptTerms).
     */
    const SETTABLE = [
        'agent.name' => 'name',
        'privacy.perfLogging' => 'bool',
        'privacy.telemetry' => 'bool',
        'sessions.retentionDays' => 'days',
        'update.channel' => 'channel',
        'provider.model' => 'model',
        'hooks.model' => 'model',
        'tools.disabled' => 'names',
        'skills.disabled' => 'names',
        'root.disabled' => 'bool',
        'searchFirst.disabled' => 'bool',
        'halt.disabled' => 'bool',
        'lowBalance.disabled' => 'bool',
        'fileopsCheck.disabled' => 'bool',
        'hooks.reword' => 'bool',
        'hooks.llm.disabled' => 'bool',
        'autoUpdate.disabled' => 'bool',
        'cron.disabled' => 'bool',
        'hermes.lazyInstalls' => 'bool',
    ];
    /** Settable keys by pattern: an *arr app's config file (tools/arr/README.md), an absolute path or '' for none. */
    const SETTABLE_PATTERNS = ['/^arr\.[a-z][a-z0-9-]{0,31}\.config\z/' => 'path'];
    /** A model id as an endpoint lists it; a list entry (a tool category path or a skill name). */
    const MODEL_RE = '#^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,199}\z#';
    const NAMES_RE = '#^[a-z0-9][a-z0-9._/-]{0,99}\z#';
    const NAME_MAX = 32;
    const DAYS_MAX = 36500;

    /** A name for the agent: 1-32 letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit. */
    public static function validName($name)
    {
        return is_string($name) && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,' . (self::NAME_MAX - 1) . '}\z/u', $name) === 1;
    }

    /**
     * An update channel: `release`, or `git/<branch>` where the branch starts with a letter or digit and
     * has only letters, digits and . _ / - (no "..", "//", "@{", trailing "/", "." or ".lock"), so it can
     * never be read as a git option or a revision expression (ADR-0057).
     */
    public static function validChannel($channel)
    {
        if ($channel === 'release') {
            return true;
        }
        return is_string($channel) && preg_match('#^git/[A-Za-z0-9][A-Za-z0-9._/-]{0,99}\z#', $channel) === 1
            && strpos($channel, '..') === false && strpos($channel, '//') === false
            && !preg_match('#(/|\.|\.lock)\z#', $channel) && strpos($channel, '/.') === false;
    }

    /** The type of a settable key (SETTABLE, then SETTABLE_PATTERNS), or null when Config::set never writes it. */
    public static function type($key)
    {
        if (isset(self::SETTABLE[$key])) {
            return self::SETTABLE[$key];
        }
        foreach (self::SETTABLE_PATTERNS as $re => $type) {
            if (is_string($key) && preg_match($re, $key) === 1) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Text from a command line or a prompt -> the typed value for $key, or throws with a message
     * the person can act on. on/off, yes/no, true/false and 1/0 are booleans; a list is names
     * separated by commas or spaces ('' or none: empty).
     */
    public static function parse($key, $text)
    {
        $type = self::type($key);
        if ($type === null) {
            throw new InvalidArgumentException("$key is not a setting that can be changed here");
        }
        $text = trim((string) $text);
        switch ($type) {
            case 'bool':
                $map = ['on' => true, 'yes' => true, 'true' => true, '1' => true, 'off' => false, 'no' => false, 'false' => false, '0' => false];
                if (!isset($map[strtolower($text)])) {
                    throw new InvalidArgumentException("$key takes on or off");
                }
                return $map[strtolower($text)];
            case 'days':
                if (!preg_match('/^\d{1,5}$/', $text) || (int) $text > self::DAYS_MAX) {
                    throw new InvalidArgumentException("$key takes a whole number of days, 0 to " . self::DAYS_MAX . ' (0 = keep forever)');
                }
                return (int) $text;
            case 'names':
                $names = $text === '' || strtolower($text) === 'none' ? [] : array_values(array_unique(preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY)));
                if (!self::valid('names', $names)) {
                    throw new InvalidArgumentException("$key takes names separated by commas (lowercase letters, digits and . _ / -), or none");
                }
                return $names;
            default:
                if (!self::valid($type, $text)) {
                    $what = ['channel' => 'release or git/<branch> (for example git/main)', 'model' => 'a model id as the endpoint lists it, or empty for the default',
                        'path' => 'an absolute path, or empty for none',
                        'name' => '1-' . self::NAME_MAX . ' letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit'];
                    throw new InvalidArgumentException("$key takes " . $what[$type]);
                }
                return $text;
        }
    }

    /** True when $value is of $type (SETTABLE's types). */
    public static function valid($type, $value)
    {
        switch ($type) {
            case 'bool':
                return is_bool($value);
            case 'days':
                return is_int($value) && $value >= 0 && $value <= self::DAYS_MAX;
            case 'name':
                return is_string($value) && ($value === '' || self::validName($value));
            case 'channel':
                return self::validChannel($value);
            case 'model':
                return is_string($value) && ($value === '' || preg_match(self::MODEL_RE, $value) === 1);
            case 'path':
                return is_string($value) && ($value === '' || (strlen($value) <= 4096 && $value[0] === '/' && !preg_match('/[\x00-\x1f\x7f]/', $value)));
            case 'names':
                if (!is_array($value) || !self::isList($value) || count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                    return false;
                }
                foreach ($value as $n) {
                    if (!is_string($n) || preg_match(self::NAMES_RE, $n) !== 1) {
                        return false;
                    }
                }
                return true;
        }
        return false;
    }

    /**
     * Write one setting to local/config.php (or $file): only settable keys (type()), only values of
     * their type, and never a change that is the person's own (ConfigureTiers, judged against the file's
     * value under the lock, so every caller gets the same refusal, ADR-0059). The file is rewritten from its
     * values (comments are not kept), under a lock, through a temporary file and a rename. Returns the value written.
     */
    public static function set($key, $value, $file = null)
    {
        $type = self::type($key);
        if ($type === null) {
            throw new InvalidArgumentException("$key is not a setting that can be changed here");
        }
        if (!self::valid($type, $value)) {
            throw new InvalidArgumentException("$key: wrong value " . var_export($value, true));
        }
        return self::write($key, $value, $file, true);
    }

    /** The person accepted the terms of service and privacy policy: only the TTY prompt and install --accept-terms call this. */
    public static function acceptTerms($file = null)
    {
        return self::write('terms.accepted', true, $file);
    }

    /** Set a dotted key (any depth: hooks.llm.disabled, arr.sonarr.config) in the local overrides file atomically, under a lock. */
    private static function write($key, $value, $file, $tiered = false)
    {
        $file = $file ?: self::localDir() . '/config.php';
        $dir = dirname($file);
        @mkdir($dir, 0700, true);
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException("could not lock $file");
        }
        try {
            $local = is_file($file) ? require $file : [];
            $local = is_array($local) ? $local : [];
            if ($tiered) {
                require_once __DIR__ . '/ConfigureTiers.php'; // loaded only here: scratch copies of this file that never set need not carry it
                $old = self::dotted(self::merge(self::defaults(), $local), $key);
                if (ConfigureTiers::classify($key, $old, $value) === ConfigureTiers::PERSON) {
                    throw new InvalidArgumentException("$key: changing it from " . json_encode($old) . ' to ' . json_encode($value) . " is the person's to do, by hand in " . self::shown($file));
                }
            }
            $parts = explode('.', $key);
            $last = array_pop($parts);
            $at = &$local;
            foreach ($parts as $part) {
                if (!isset($at[$part]) || !is_array($at[$part])) {
                    $at[$part] = [];
                }
                $at = &$at[$part];
            }
            $at[$last] = $value;
            unset($at);
            $body = "<?php\n// Local overrides. Updates never touch this file; `pikkumies` settings are written here too (ADR-0032).\nreturn " . var_export($local, true) . ";\n";
            $tmp = $file . '.tmp-' . getmypid();
            if (@file_put_contents($tmp, $body) !== strlen($body) || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException("could not write $file (disk full?)");
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $value;
    }

    /** Runtime root: the pikkumies/ directory this file lives under. */
    public static function root()
    {
        return dirname(__DIR__);
    }

    public static function localDir()
    {
        return self::root() . '/local';
    }

    /** A path as the user can type it from any directory: absolute, with the home directory written as ~ (GH #6). */
    public static function shown($path)
    {
        $home = rtrim((string) getenv('HOME'), '/');
        return $home !== '' && strpos($path, $home . '/') === 0 ? '~' . substr($path, strlen($home)) : $path;
    }

    /** The value of a dotted key (hooks.llm.disabled) in $config, null when it is not set. */
    public static function dotted(array $config, $key)
    {
        foreach (explode('.', (string) $key) as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return null;
            }
            $config = $config[$part];
        }
        return $config;
    }

    /** The defaults and local/config.php (or $file), without the PIKKUMIES_CONFIG overlay: what Config::set changes. */
    public static function fileConfig($file = null)
    {
        $file = $file ?: self::localDir() . '/config.php';
        $local = is_file($file) ? require $file : [];
        return self::merge(self::defaults(), is_array($local) ? $local : []);
    }

    public static function load()
    {
        // PIKKUMIES_CONFIG names an overlay merged last, for one run (ADR-0038): automation and the question
        // suite force the model, the light model, the host family or any other key without editing local/config.php.
        return self::merge(self::fileConfig(), self::overlay());
    }

    /** The PIKKUMIES_CONFIG overlay's settings, [] when there is none (overlayPath) or it returns no array. */
    public static function overlay()
    {
        $path = self::overlayPath();
        $over = $path !== '' ? require $path : [];
        return is_array($over) ? $over : [];
    }

    /**
     * The PIKKUMIES_CONFIG overlay file, or '' when it is unset, not a readable file, not owned by this
     * account or writable by others: it is loaded with require, so it runs as PHP (ADR-0038).
     */
    public static function overlayPath()
    {
        $path = (string) getenv('PIKKUMIES_CONFIG');
        clearstatcache(true, $path); // a mode changed earlier in this process must not read as before (PHP caches stat)
        $st = $path !== '' && is_file($path) && is_readable($path) ? @stat($path) : false;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        return $st && $st['uid'] === $uid && ($st['mode'] & 0022) === 0 ? $path : '';
    }

    /** Recursive merge where local scalars and lists replace defaults. */
    public static function merge(array $base, array $over)
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !self::isList($v)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    public static function isList(array $a)
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * Write local/config.php only if absent: local edits always win. Same atomic pattern as write()
     * (lock, temporary file, checked rename): returns true only when the file was actually created,
     * never true on a failed write (it used to: file_put_contents()'s return value went unchecked).
     */
    public static function writeDefaultsIfMissing()
    {
        $file = self::localDir() . '/config.php';
        if (is_file($file)) {
            return false;
        }
        @mkdir(self::localDir(), 0700, true);
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return false;
        }
        try {
            if (is_file($file)) {
                return false; // another process won the race while we waited for the lock
            }
            $body = "<?php\n// Local overrides. Updates never touch this file.\nreturn " . var_export(['provider' => ['endpoint' => '', 'model' => '']], true) . ";\n";
            $tmp = $file . '.tmp-' . getmypid();
            if (@file_put_contents($tmp, $body) !== strlen($body) || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return true;
    }
}
