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
            // reword: a question with no local hit is turned into search terms by the model once (hooks/search/search-first/search-first.php).
            // llm.disabled: no hook ever calls the model. model: the hooks' model; '' = the light model decided at generate (local/llm.json).
            'hooks' => ['reword' => true, 'llm' => ['disabled' => false], 'model' => ''],
            'autoUpdate' => ['disabled' => false],  // daily `pikkumies update` from the user's crontab (ADR-0013)
            'cron' => ['disabled' => false],        // per-minute `cron-tick` from the user's crontab, runs Hermes' own due jobs (ADR-0025)
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

    /** Settings Config::set writes, with their type; the configure tool may set exactly these (ADR-0032). */
    const SETTABLE = [
        'agent.name' => 'name',
        'privacy.perfLogging' => 'bool',
        'privacy.telemetry' => 'bool',
        'sessions.retentionDays' => 'days',
    ];
    const NAME_MAX = 32;
    const DAYS_MAX = 36500;

    /** A name for the agent: 1-32 letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit. */
    public static function validName($name)
    {
        return is_string($name) && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,' . (self::NAME_MAX - 1) . '}\z/u', $name) === 1;
    }

    /**
     * Text from a command line or a prompt -> the typed value for $key, or throws with a message
     * the person can act on. on/off, yes/no, true/false and 1/0 are booleans.
     */
    public static function parse($key, $text)
    {
        if (!isset(self::SETTABLE[$key])) {
            throw new InvalidArgumentException("$key is not a setting that can be changed here");
        }
        $text = trim((string) $text);
        switch (self::SETTABLE[$key]) {
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
            default:
                if ($text !== '' && !self::validName($text)) {
                    throw new InvalidArgumentException("$key: 1-" . self::NAME_MAX . ' letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit');
                }
                return $text;
        }
    }

    /**
     * Write one setting to local/config.php (or $file): only keys in SETTABLE, only values of their
     * type. The file is rewritten from its values (comments are not kept), under a lock, through a
     * temporary file and a rename. Returns the value written.
     */
    public static function set($key, $value, $file = null)
    {
        if (!isset(self::SETTABLE[$key])) {
            throw new InvalidArgumentException("$key is not a setting that can be changed here");
        }
        $type = self::SETTABLE[$key];
        $ok = ($type === 'bool' && is_bool($value))
            || ($type === 'days' && is_int($value) && $value >= 0 && $value <= self::DAYS_MAX)
            || ($type === 'name' && is_string($value) && ($value === '' || self::validName($value)));
        if (!$ok) {
            throw new InvalidArgumentException("$key: wrong value " . var_export($value, true));
        }
        return self::write($key, $value, $file);
    }

    /** The person accepted the terms of service and privacy policy: only the TTY prompt and install --accept-terms call this. */
    public static function acceptTerms($file = null)
    {
        return self::write('terms.accepted', true, $file);
    }

    /** Set a dotted key in the local overrides file atomically, under a lock. */
    private static function write($key, $value, $file)
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
            list($section, $name) = explode('.', $key, 2);
            if (!isset($local[$section]) || !is_array($local[$section])) {
                $local[$section] = [];
            }
            $local[$section][$name] = $value;
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

    public static function load()
    {
        $file = self::localDir() . '/config.php';
        $local = is_file($file) ? require $file : [];
        $config = self::merge(self::defaults(), is_array($local) ? $local : []);
        // PIKKUMIES_CONFIG names an overlay merged last, for one run (ADR-0038): automation and the question
        // suite force the model, the light model, the host family or any other key without editing local/config.php.
        $overlay = self::overlayPath();
        if ($overlay !== '') {
            $over = require $overlay;
            $config = is_array($over) ? self::merge($config, $over) : $config;
        }
        return $config;
    }

    /**
     * The PIKKUMIES_CONFIG overlay file, or '' when it is unset, not a readable file, not owned by this
     * account or writable by others: it is loaded with require, so it runs as PHP (ADR-0038).
     */
    public static function overlayPath()
    {
        $path = (string) getenv('PIKKUMIES_CONFIG');
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
