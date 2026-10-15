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
            ],
            'root'  => ['disabled' => false],     // UX switch only; the OS decides what root can do
            'skills' => ['disabled' => []],        // shipped skill names to hide, e.g. after copying one (ADR-0007)
            'tools' => ['disabled' => []],         // tool categories to leave out, e.g. ['seedbox'] (ADR-0015)
            'searchFirst' => ['disabled' => false], // first-turn search hint + search-first gate (ADR-0011)
            // reword: a question with no local hit is turned into search terms by the model once (hooks/pre_llm_call/search-first.php).
            // llm.disabled: no hook ever calls the model. model: the hooks' model; '' = the light model decided at generate (local/llm.json).
            'hooks' => ['reword' => true, 'llm' => ['disabled' => false], 'model' => ''],
            'autoUpdate' => ['disabled' => false],  // daily `pikkumies update` from the user's crontab (ADR-0013)
            'cron' => ['disabled' => false],        // per-minute `cron-tick` from the user's crontab, runs Hermes' own due jobs (ADR-0025)
            // The Hermes release the wiring was verified against, and its tarball checksum (ADR-0009).
            // lazyInstalls: let Hermes download optional packages and a browser at runtime (ADR-0021).
            'hermes' => ['version' => 'v2026.9.24', 'sha256' => '15b15ce4e6ec8ea424a081823709d1e17f0943e7b42b59597d24ebb94cbd1742', 'lazyInstalls' => false],
        ];
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
        return self::merge(self::defaults(), is_array($local) ? $local : []);
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

    /** Write local/config.php only if absent: local edits always win. */
    public static function writeDefaultsIfMissing()
    {
        $file = self::localDir() . '/config.php';
        if (is_file($file)) {
            return false;
        }
        @mkdir(self::localDir(), 0700, true);
        $body = "<?php\n// Local overrides. Updates never touch this file.\nreturn " . var_export(['provider' => ['endpoint' => '', 'model' => '']], true) . ";\n";
        file_put_contents($file, $body);
        chmod($file, 0600);
        return true;
    }
}
