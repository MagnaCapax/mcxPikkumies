<?php
/**
 * BrowseBinary: the agent-browser CLI, at exactly the version and checksum the installed Hermes pins in its lock
 * (pikkumies/hermes/pm/lock.json, packages.agent-browser), fetched as the account into local/browse/bin/<version>/.
 * Hermes' own install declines agent-browser while lazy installs are off (ADR-0021, ADR-0033), so this is the only
 * copy, and the pin follows the lock: a Hermes update that moves the pin fetches and verifies the new version.
 * Upstream ships one npm tarball for every platform ("any") holding bin/agent-browser-<target> per target
 * (pm/packages.py AgentBrowser._rel and stage); only this machine's binary is unpacked.
 * A checksum mismatch refuses: nothing is unpacked and the download is removed.
 */
class BrowseBinary
{
    /** Test seams: another lock file; a fetcher function ($url, $dest) returning a curl-like exit code. */
    public static $lockFile = null;
    public static $fetch = null;

    public static function lockFile()
    {
        return self::$lockFile !== null ? self::$lockFile : HermesInstall::treeDir() . '/pm/lock.json';
    }

    /**
     * [version, url, sha256] of agent-browser in $lock for $target (its own artifact, else "any"), null when the lock
     * pins none or the entry is malformed (an https or file URL, a 64-hex checksum, a plain version), as uvPin checks.
     */
    public static function pin(array $lock, $target)
    {
        $pkg = isset($lock['packages']['agent-browser']) ? $lock['packages']['agent-browser'] : null;
        if (!is_array($pkg) || !isset($pkg['version'], $pkg['artifacts']) || !is_array($pkg['artifacts'])) {
            return null;
        }
        $art = isset($pkg['artifacts'][$target]) ? $pkg['artifacts'][$target] : (isset($pkg['artifacts']['any']) ? $pkg['artifacts']['any'] : null);
        $url = is_array($art) && isset($art['url']) ? (string) $art['url'] : '';
        $sha = is_array($art) && isset($art['sha256']) ? strtolower((string) $art['sha256']) : '';
        if (!preg_match('/^[0-9A-Za-z._+-]+$/', (string) $pkg['version']) || !preg_match('/^[0-9a-f]{64}$/', $sha)
            || (strpos($url, 'https://') !== 0 && strpos($url, 'file://') !== 0)) {
            return null;
        }
        return ['version' => (string) $pkg['version'], 'url' => $url, 'sha256' => $sha];
    }

    /** The pin for this machine from the installed Hermes, or null (no Hermes, no pin, no build for this machine). */
    public static function currentPin()
    {
        $lock = json_decode((string) @file_get_contents(self::lockFile()), true);
        $target = HermesInstall::target();
        return is_array($lock) && $target !== '' ? self::pin($lock, $target) : null;
    }

    /** The tarball member holding this machine's binary (npm tarballs wrap everything in package/). */
    public static function member($target)
    {
        return 'package/bin/agent-browser-' . $target;
    }

    public static function path($version)
    {
        return BrowsePaths::dir() . "/bin/$version/agent-browser";
    }

    /** Recorded at install: the tarball's pinned sha256 and the unpacked binary's own sha256. */
    public static function recordFile($version)
    {
        return BrowsePaths::dir() . "/bin/$version/installed.json";
    }

    /**
     * '' when the pinned version is installed and the binary still hashes to what was recorded when it was unpacked
     * from a verified tarball; else the problem. $hash false skips re-hashing (the readiness check on every start).
     */
    public static function verify(array $pin, $hash = true)
    {
        $bin = self::path($pin['version']);
        $rec = json_decode((string) @file_get_contents(self::recordFile($pin['version'])), true);
        if (!is_file($bin) || !is_array($rec) || !isset($rec['tarball'], $rec['binary'])) {
            return "agent-browser {$pin['version']} is not fetched yet";
        }
        if ($rec['tarball'] !== $pin['sha256']) {
            return "agent-browser {$pin['version']} was installed from another tarball than the lock pins";
        }
        if ($hash && hash_file('sha256', $bin) !== $rec['binary']) {
            return 'agent-browser ' . $pin['version'] . ' changed on disk since it was verified (' . Config::shown($bin) . '); remove that directory to fetch it again';
        }
        return '';
    }

    /** Fetch, check and unpack the pinned binary when it is missing. Returns '' or the reason it is not installed. */
    public static function ensure(array $pin, $target)
    {
        $problem = self::verify($pin);
        if ($problem === '' || strpos($problem, 'is not fetched yet') === false) {
            return $problem; // installed, or tampered: a changed binary is never silently replaced
        }
        $binDir = BrowsePaths::dir() . '/bin';
        $tmp = "$binDir/.fetch-" . getmypid() . '-' . mt_rand();
        if (BrowsePaths::checkDir($binDir) !== '' || !@mkdir($tmp, 0700)) {
            return 'cannot write ' . Config::shown($binDir);
        }
        try {
            $archive = "$tmp/agent-browser.tgz";
            $code = call_user_func(self::$fetch !== null ? self::$fetch : [self::class, 'curl'], $pin['url'], $archive);
            if ($code !== 0 || !is_file($archive)) {
                return "could not download agent-browser {$pin['version']} (curl exit $code)";
            }
            $got = (string) hash_file('sha256', $archive);
            if ($got !== $pin['sha256']) {
                return "agent-browser {$pin['version']}: sha256 mismatch (got $got, the Hermes lock pins {$pin['sha256']}); not installed";
            }
            $member = self::member($target);
            $r = ToolProc::run(['tar', '-xzf', $archive, '-C', $tmp, '--no-same-owner', '--no-same-permissions', $member]);
            if ($r['code'] !== 0 || !is_file("$tmp/$member")) {
                return "the agent-browser {$pin['version']} tarball has no $member";
            }
            $dest = dirname(self::path($pin['version']));
            if (!@mkdir($dest, 0700) || !@chmod("$tmp/$member", 0700) || !@rename("$tmp/$member", self::path($pin['version']))) {
                return 'cannot install agent-browser into ' . Config::shown($dest);
            }
            HermesConfig::put(self::recordFile($pin['version']), json_encode(['tarball' => $pin['sha256'], 'binary' => hash_file('sha256', self::path($pin['version'])),
                'url' => $pin['url'], 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES) . "\n", 0600);
            return '';
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp)); // only the fetch directory this call made
        }
    }

    /** Download $url to $dest as this account; curl's exit code. */
    public static function curl($url, $dest)
    {
        return ToolProc::run(['curl', '-fsSL', '--max-time', '120', '-o', $dest, $url])['code'];
    }

    /** "0.26.0" from `agent-browser --version` output ("agent-browser 0.26.0"), '' when it does not say. */
    public static function versionOf($out)
    {
        return preg_match('/agent-browser\s+v?([0-9][0-9A-Za-z.+-]*)/', (string) $out, $m) ? $m[1] : '';
    }
}
