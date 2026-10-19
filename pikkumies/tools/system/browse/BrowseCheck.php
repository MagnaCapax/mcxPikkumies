<?php
/**
 * BrowseCheck: whether the browser may be offered on this machine, the memory gate before a start, and the doctor
 * line. Hermes advertises its browser tools only when it finds an agent-browser command and a Chromium
 * (tools/browser_tool_install.py check_browser_requirements); Pikkumies puts its wrapper first on Hermes' PATH and
 * names /usr/bin/chromium in AGENT_BROWSER_EXECUTABLE_PATH only when every check here passes, so a host without
 * Chromium, slirp4netns or user namespaces stays quiet and nobody is asked anything.
 */
class BrowseCheck
{
    /** Programs the start needs, each from PATH. */
    const TOOLS = ['unshare', 'nsenter', 'slirp4netns', 'ip', 'ss', 'choom', 'tar', 'curl'];
    /**
     * Free memory a start needs, in the account's own limit (or the host's MemAvailable when the account has none).
     * Measured on the development host (2026-10-19): with one blank page the namespace's processes (Chromium, agent-browser,
     * the guard) summed about 540 MB RSS, shared pages counted once per process, so the real use is lower; an app page
     * costs more. Not measured on a PMSS host.
     */
    const HEADROOM_BYTES = 629145600; // 600 MiB
    /**
     * What this unit relies on in the installed Hermes, as HermesInstall::CONFIG_FACTS does: [file, text]. A Hermes
     * release without one of them turns the browser off (and only the browser) until Pikkumies is changed.
     */
    const HERMES_FACTS = [
        'agent-browser found on PATH' => ['tools/browser_tool_install.py', 'shutil.which("agent-browser", path=search_path)'],
        'AGENT_BROWSER_EXECUTABLE_PATH' => ['hermes_cli/browser_runtime.py', 'os.environ.get("AGENT_BROWSER_EXECUTABLE_PATH")'],
        'browser.restrict_evaluate' => ['tools/browser_tool_eval_policy.py', '_browser_eval_flag("restrict_evaluate")'],
        'browser.allow_private_urls' => ['tools/browser_tool_cloud.py', '_browser_cfg("allow_private_urls"'],
        'browser.use_real_profile' => ['tools/browser_tool_cloud.py', '_browser_cfg("use_real_profile"'],
    ];

    /** Test seams: the Hermes source tree, the user's Hermes config.yaml, the tool lookup, /proc/sys. */
    public static $source = null;
    public static $userConfig = null;
    public static $which = null;
    public static $procSys = '/proc/sys';

    /** '' when the browser may be offered, else the first failing check, in plain words. Cheap: no process is run. */
    public static function problem()
    {
        if (!is_file(BrowsePaths::chromium()) || !is_executable(BrowsePaths::chromium())) {
            return BrowsePaths::chromium() . ' is not installed';
        }
        foreach (self::TOOLS as $tool) {
            if ((self::$which !== null ? call_user_func(self::$which, $tool) : ToolProc::which($tool)) === null) {
                return "$tool is not installed";
            }
        }
        $sys = self::$procSys;
        if (trim((string) @file_get_contents("$sys/kernel/unprivileged_userns_clone")) === '0' || trim((string) @file_get_contents("$sys/user/max_user_namespaces")) === '0') {
            return 'user namespaces are turned off on this host';
        }
        $src = self::$source !== null ? self::$source : HermesInstall::sourceDir();
        if ($src === '') {
            return 'Hermes is not installed yet';
        }
        if (BrowseBinary::currentPin() === null) {
            return 'the Hermes lock pins no agent-browser for this machine';
        }
        foreach (self::HERMES_FACTS as $what => $fact) {
            if (strpos((string) @file_get_contents("$src/{$fact[0]}"), $fact[1]) === false) {
                return "this Hermes no longer has $what ({$fact[0]})";
            }
        }
        return self::configProblem((string) @file_get_contents(self::$userConfig !== null ? self::$userConfig : HermesConfig::home() . '/config.yaml'));
    }

    /**
     * The three browser keys Hermes reads from the user's own config.yaml only (read_raw_config, no managed overlay, so
     * they cannot be pinned, ADR-0062): JavaScript evaluation restricted, no private URLs, and no real-profile mode
     * (Hermes would start a Chrome of its own with a CDP port in the shared network namespace,
     * tools/browser_tool_real_profile.py). The seed sets the first; a changed value turns the browser off, never back.
     */
    public static function configProblem($yaml)
    {
        $truthy = '\s*:\s*["\']?(true|yes|on|1)["\']?\s*(#.*)?$';
        if (!preg_match('/^\s+restrict_evaluate' . $truthy . '/mi', $yaml)) {
            return 'browser.restrict_evaluate is not true in ' . Config::shown(HermesConfig::home() . '/config.yaml');
        }
        foreach (['allow_private_urls', 'use_real_profile'] as $key) {
            if (preg_match('/^\s+' . $key . $truthy . '/mi', $yaml)) {
                return "$key is on in " . Config::shown(HermesConfig::home() . '/config.yaml');
            }
        }
        return '';
    }

    /**
     * '' when a start fits, else one plain line. $usage: Cgroup::usageAtLimit(); $available: MemAvailable in bytes,
     * used when the account's own level sets no limit or its usage is shared with every account.
     */
    public static function headroomProblem(array $usage, $available, $need = self::HEADROOM_BYTES)
    {
        $own = $usage['memory_max'] !== null && empty($usage['memory_shared']) && $usage['memory_current'] !== null;
        $free = $own ? $usage['memory_max'] - $usage['memory_current'] : $available;
        if ($free === null || $free >= $need) {
            return ''; // nothing readable to judge by: Chrome's oom_score_adj 1000 still makes it the first to go
        }
        return sprintf('not starting the browser: %d MB of memory is free %s and it needs %d MB; it starts once other programs of this account use less',
            (int) ($free / 1048576), $own ? "in this account's limit" : 'on this server', (int) ($need / 1048576));
    }

    public static function memAvailable($meminfo = '/proc/meminfo')
    {
        return preg_match('/^MemAvailable:\s+(\d+) kB/m', (string) @file_get_contents($meminfo), $m) ? (float) $m[1] * 1024 : null;
    }

    /** The variables `pikkumies run` adds to Hermes' environment: none unless problem() is ''. */
    public static function hermesEnv()
    {
        if (self::problem() !== '' || BrowsePaths::ensureDirs() !== '') {
            return [];
        }
        try {
            BrowsePaths::writeShim();
        } catch (Exception $x) {
            return [];
        }
        return ['PATH' => dirname(BrowsePaths::file('shim')) . ':' . (string) getenv('PATH'), 'AGENT_BROWSER_EXECUTABLE_PATH' => BrowsePaths::chromium()];
    }

    /** `browse: ready (...)` or `browse: off (<the failing check>)`. $state: the running state or null. */
    public static function doctorLine($state = null, $chromiumVersion = null)
    {
        $problem = self::problem();
        if ($problem !== '') {
            return "browse:   off ($problem)";
        }
        $pin = BrowseBinary::currentPin();
        $chromiumVersion = $chromiumVersion !== null ? $chromiumVersion : self::chromiumVersion();
        $versions = 'chromium ' . ($chromiumVersion !== '' ? $chromiumVersion : '?') . ', agent-browser ' . $pin['version'];
        if ($state !== null) {
            return "browse:   ready ($versions, isolated, guard up)";
        }
        $bin = BrowseBinary::verify($pin, false);
        return "browse:   ready ($versions" . ($bin !== '' ? ' (fetched on first use)' : '') . '; stopped: starts on first use, the isolation check runs at every start)';
    }

    /** "141.0.7390.54" from `chromium --version`, '' when it does not say. */
    public static function chromiumVersion()
    {
        $r = ToolProc::run(['timeout', '10', BrowsePaths::chromium(), '--version']);
        return preg_match('/(\d+\.\d+\.\d+\.\d+)/', $r['out'], $m) ? $m[1] : '';
    }
}
