<?php
/**
 * HermesConfig: generates Hermes' home (HERMES_HOME = local/hermes) from the tracked tree.
 *
 * - config.yaml: model provider, skills.external_dirs (the skills generated from the tool units,
 *   ADR-0015), shell hooks.
 *   A local skill must not reuse a shipped name: Hermes refuses an ambiguous name (ADR-0007).
 * - .env: provider key (0600). SOUL.md: what this machine allows (from detection), tools' notices + doctrine.
 * - scripts/pikkumies-docker-check.sh: what the scheduled Docker check runs (ADR-0034).
 * - scripts/pikkumies-performance-sample.sh: what the scheduled performance sampling runs (ADR-0035).
 * Regenerated on install, update and run; hand edits belong in local/config.php, not here.
 * Every file is written to a temporary name and renamed: a full disk leaves the old file, never an
 * empty config without the safety hooks.
 */
require_once __DIR__ . '/Links.php';

class HermesConfig
{
    public static function home()
    {
        return Config::localDir() . '/hermes';
    }

    public static function write(array $config, array $profile)
    {
        $home = self::home();
        @mkdir($home . '/skills', 0700, true);
        $prov = Provider::resolve($config);
        $prov['model'] = Provider::model($prov, $ids);
        // The light model for hooks (local/llm.json), reusing the /models answer when there was one;
        // also goes under auxiliary.goal_judge below, since /goal already uses an auxiliary judge (ADR-0047).
        $light = Provider::writeLight($prov, $config, $ids);

        self::put($home . '/config.yaml', self::renderYaml($prov, self::skillDirs(), self::hookEntries(), (array) $config['skills']['disabled'], !empty($config['hermes']['lazyInstalls']), $light) . self::sessionsYaml($config));
        // Key lives only in .env (0600); config.yaml points at it via providers.pikkumies.key_env.
        self::put($home . '/.env', "PIKKUMIES_API_KEY=" . $prov['key'] . "\n", 0600);
        self::put($home . '/SOUL.md', self::renderSoul($profile, $config));
        foreach ([self::dockerCheckScript($home), self::performanceSampleScript($home)] as $job) {
            list($script, $line) = $job;
            @mkdir(dirname($script), 0700, true);
            self::put($script, $line, 0700);
        }
        return $home;
    }

    /** Write through a temporary file and rename; throws when the write fails (the old file stays). */
    public static function put($path, $content, $mode = null)
    {
        $tmp = $path . '.tmp-' . getmypid();
        $ok = @file_put_contents($tmp, $content) === strlen($content) && ($mode === null || chmod($tmp, $mode)) && @rename($tmp, $path);
        if (!$ok) {
            @unlink($tmp);
            throw new Exception("could not write $path (disk full?)");
        }
    }

    /** The skills generated from the tool units; the user's own skills live in Hermes' home (ADR-0007). */
    public static function skillDirs()
    {
        return [Units::generatedDir()];
    }

    /**
     * Shell hooks (ADR-0030): one entry per event that has hook units, running hooks/orchestrator.php,
     * which runs the units. The pre_tool_call entry always fails closed: whether the guard does is read
     * from its hook.md, and a broken hook.md must not turn that off. Its timeout covers every unit's own
     * timeout run one after another, plus 2 seconds, within Hermes' maximum of 300. display_errors=stdout
     * (fatal errors only, 4437 = E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR|E_USER_ERROR|E_RECOVERABLE_ERROR)
     * makes an orchestrator that cannot even compile print text, which a fail-closed entry blocks on.
     * The user's local units (local/hooks/ beside hooks/, ADR-0054) count too, and an event with only local units gets
     * an entry. The timeout is also the command's last argument, so the orchestrator knows how long it has and starts
     * a local unit only while it still fits.
     * Returns [event => ['command' => ..., 'failClosed' => bool, 'timeout' => seconds]].
     */
    public static function hookEntries($hooksDir = null)
    {
        $hooksDir = $hooksDir ?: HookUnits::hooksDir();
        $shipped = HookUnits::byEvent($hooksDir);
        $local = LocalHooks::generated(dirname($hooksDir)); // the scan generate just recorded, else a new one
        $entries = [];
        foreach (HookUnits::EVENTS as $event) {
            $units = array_merge(isset($shipped[$event]) ? $shipped[$event] : [], LocalHooks::forEvent($event, $local, true));
            if (!$units) {
                continue;
            }
            $timeout = 2;
            foreach ($units as $u) {
                $timeout += $u['timeout'];
            }
            $timeout = min(300, $timeout);
            $entries[$event] = ['command' => PHP_BINARY . ' -d display_errors=stdout -d error_reporting=4437 ' . $hooksDir . '/orchestrator.php ' . $event . ' ' . $timeout,
                'failClosed' => $event === 'pre_tool_call', 'timeout' => $timeout];
        }
        ksort($entries);
        return $entries;
    }

    public static function renderYaml(array $prov, array $skillDirs, array $hooks, array $disabledSkills = [], $lazyInstalls = false, $goalJudgeModel = '')
    {
        $y = "# Generated by pikkumies. Edit " . Config::shown(Config::localDir() . "/config.php") . " instead; this file is rewritten.\n";
        // A named provider: Hermes reads key_env for entries under providers: (hermes_cli/providers.py);
        // a bare `provider: custom` ignored model.key_env when this was first verified (ADR-0005).
        $y .= "model:\n  provider: \"custom:pikkumies\"\n  default: " . self::q($prov['model']) . "\n";
        $y .= "providers:\n  pikkumies:\n    base_url: " . self::q($prov['endpoint']) . "\n    key_env: PIKKUMIES_API_KEY\n    default_model: " . self::q($prov['model']) . "\n";
        $y .= "skills:\n  external_dirs:\n";
        foreach ($skillDirs as $d) {
            $y .= "    - " . self::q($d) . "\n";
        }
        if ($disabledSkills) {
            $y .= "  disabled:\n";
            foreach ($disabledSkills as $name) {
                $y .= "    - " . self::q($name) . "\n";
            }
        }
        // Agent-written skills get the same static scan as a hub install before they can run
        // again; Hermes' own default leaves them unscanned (ADR-0024).
        $y .= "  guard_agent_created: true\n";
        // No update check at session start: it is a call to GitHub the user did not ask for (AGENTS.md);
        // Hermes updates when the user asks, with `pikkumies run update` or /update (ADR-0033).
        $y .= "updates:\n  check: false\n";
        // Hermes' updater would otherwise write hermes, hermes-acp and hermes-agent launchers into
        // ~/.local/bin (hermes_cli/_launchers.py): a bare `hermes` there runs without this config, and
        // they are files outside pikkumies/ nobody cleans up (ADR-0033).
        $y .= "cli:\n  expose_on_path: false\n";
        // Hermes web_search/web_extract fall back to outside free search services when no backend is
        // configured (keyless_fallback, hermes_cli/config_defaults.py). AGENTS.md: nothing leaves the
        // machine unasked, so both are off; a user who wants web search configures a backend (ADR-0021).
        $y .= "web:\n  keyless_fallback: false\n  keyless_rescue: false\n";
        // Hermes would otherwise pip-install optional packages (unpinned, from PyPI) and download a browser
        // at runtime (tools/lazy_deps.py), which costs every account on a shared server the download.
        // Off unless the user sets hermes.lazyInstalls (ADR-0021), which also decides whether the install
        // declines the optional tools; the required ones (node, ffmpeg, ...) always come (ADR-0033).
        $y .= "security:\n  allow_lazy_installs: " . ($lazyInstalls ? 'true' : 'false') . "\n";
        // Hermes' default approvals.mode is "smart": a second model decides whether a command on Hermes'
        // dangerous list (rm -r, find -delete, ...) runs, and it approves "safe file operations"
        // (tools/approval_smart.py). Deletes need the user's approval (ADR-0020), so the user decides.
        // config.yaml is deep-merged over the defaults, so timeout and cron_mode: deny stay.
        $y .= "approvals:\n  mode: manual\n";
        // Per-turn working-directory snapshots (/rollback): Hermes defaults this off; the size caps
        // (max_snapshots, max_total_size_mb, max_file_size_mb) stay at Hermes' own values (ADR-0024).
        $y .= "checkpoints:\n  enabled: true\n";
        // Opt-in allow-list (ADR-0024): a static scan of file writes, no model call, no cost.
        $y .= "plugins:\n  enabled:\n    - \"security-guidance\"\n";
        // Set on explicitly so a future Hermes default change cannot silently turn off the post-turn
        // self-improvement fork; consolidate (its aux-model cost) is left at Hermes' own off (ADR-0024).
        $y .= "auxiliary:\n  background_review:\n    enabled: true\n";
        // /goal's own judge (hermes_cli/goals.py auxiliary.goal_judge.provider/.model): the light model
        // already decided for hooks, under the one named provider every call here uses (ADR-0047).
        // goals.max_turns stays at Hermes' own default; nothing here was measured to pick a number.
        if ($goalJudgeModel !== '') {
            $y .= "  goal_judge:\n    provider: \"pikkumies\"\n    model: " . self::q($goalJudgeModel) . "\n";
        }
        // Set on explicitly (already Hermes' default): background pruning/archiving of agent-created skills;
        // consolidate stays off, left at Hermes' own default (ADR-0024).
        $y .= "curator:\n  enabled: true\n";
        // Hermes would otherwise borrow a codex/other CLI login from this account; two programs
        // sharing one login rotate its refresh token and log each other out (ADR-0026).
        $y .= "auth:\n  adopt_external_logins: false\n";
        if ($hooks) {
            // Hermes registers a shell hook only after first-use consent; a non-interactive run
            // silently skips unapproved hooks. The entries run the shipped orchestrator, so the
            // installer gives the consent (hooks_auto_accept, agent/shell_hooks.py). The user's
            // local units (local/hooks/) run under that consent too: they are the user's own
            // files, read only when local/ is theirs alone, and never block by failing (ADR-0054).
            $y .= "hooks_auto_accept: true\n";
            $y .= "hooks:\n";
            foreach ($hooks as $event => $h) {
                // One orchestrator entry per event (ADR-0030). Hooks are safety prompts, not security
                // (AGENTS.md): only pre_tool_call fails closed, because that is where the delete guard
                // runs (ADR-0020); every other event stays at the Hermes default (a hook that errors or
                // times out lets the call run), whatever any of its own units declare.
                $y .= "  " . $event . ":\n    - command: " . self::q($h['command']) . "\n      timeout: " . (int) $h['timeout'] . "\n"
                    . (!empty($h['failClosed']) ? "      fail_closed: true\n" : '');
            }
        }
        return $y;
    }

    /**
     * Appended to config.yaml (ADR-0032). sessions: Hermes prunes ended sessions older than
     * retention_days at start (auto_prune, hermes_cli/config_defaults.py "sessions"); our
     * sessions.retentionDays 0 means keep them forever, so pruning is off. telemetry: Hermes'
     * own shared metrics stay off (already its default), set explicitly so a new release cannot turn them on.
     */
    public static function sessionsYaml(array $config)
    {
        $days = isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0;
        $y = $days > 0 ? "sessions:\n  auto_prune: true\n  retention_days: $days\n" : "sessions:\n  auto_prune: false\n";
        return $y . "telemetry:\n  shared_metrics:\n    enabled: false\n    send: false\n";
    }

    /**
     * SOUL.md: what this machine allows, the static doctrine (soul, then the name line when the user
     * named the agent, ADR-0032; voice, doctrine, safety), then "## Tool doctrine" with the DOCTRINE.md
     * of every enabled tool category (ADR-0029). $toolDoctrine false gives the core alone, whose
     * budget is 8000 bytes. $toolsDir: a test tree instead of the shipped tools (as Units::doctrines).
     */
    public static function renderSoul(array $profile, array $config, $toolDoctrine = true, $toolsDir = null)
    {
        $parts = [self::capabilities($profile, $config)];
        foreach (['soul', 'voice', 'doctrine', 'safety'] as $f) {
            $p = Config::root() . '/doctrine/' . $f . '.md';
            if (is_file($p)) {
                $parts[] = trim((string) file_get_contents($p)) . ($f === 'soul' ? self::nameLine($config) : '');
            }
        }
        $rules = $toolDoctrine ? Units::doctrines(Generate::disabledCategories($config), $toolsDir) : [];
        if ($rules) {
            $section = "## Tool doctrine\n\nRules for each tool category; follow them whenever you use its tools.";
            foreach ($rules as $category => $text) {
                $section .= "\n\n### $category\n\n" . $text;
            }
            $parts[] = $section;
        }
        // ADR links in the doctrine are relative to the repository; in SOUL.md (in HERMES_HOME) they point nowhere (ADR-0060).
        return Links::stripAdrLinks(implode("\n\n---\n\n", $parts)) . "\n";
    }

    /** "\n\nYour name is ..." when the user named the agent something other than Pikkumies, else ''. */
    public static function nameLine(array $config)
    {
        $name = self::agentName($config);
        return $name === 'Pikkumies' ? '' : "\n\nYour name is $name; the user chose it. Pikkumies is the software you run as.";
    }

    /** The agent's name: agent.name from the config when it is a valid name (Config::validName), else "Pikkumies". */
    public static function agentName(array $config)
    {
        $name = isset($config['agent']['name']) && is_string($config['agent']['name']) ? trim($config['agent']['name']) : '';
        return $name !== '' && Config::validName($name) ? $name : 'Pikkumies';
    }

    /**
     * What this machine allows, from detection. Every tool's skill is always listed (ADR-0015), so
     * this tells the model which ones apply here, and where the tool index is.
     */
    public static function capabilities(array $profile, array $config)
    {
        if (!empty($config['root']['disabled'])) {
            $root = 'turned off in config; do not use root or the server-admin skill';
        } elseif (!empty($profile['root']['available'])) {
            $root = 'available (via ' . $profile['root']['via'] . ')';
        } else {
            $root = 'not available; manage only this user\'s files and processes';
        }
        // Only what decides which skills apply. SOUL.md goes to the model on every turn, so the
        // rest of the profile stays local; the system-status skill reads it when needed.
        // On PMSS the host family is PMSS too; elsewhere the family says which host it is.
        return "# This machine\n\n- Root: " . $root
            . (!empty($profile['pmss']) ? "\n- PMSS seedbox platform: yes; this account's disk, quota, memory, traffic and limits, live in one call: system-status account" : '')
            . (!empty($profile['pmss']) && isset($profile['family']) && $profile['family'] === 'pmss' ? '' : "\n- Host: " . (isset($profile['family']) ? $profile['family'] : 'generic'))
            // Without root the Root line already limits the agent to this user's own files.
            . (!empty($profile['tenancy']['shared'])
                ? "\n- Shared server: yes; other accounts live here" . (!empty($profile['root']['available']) && empty($config['root']['disabled']) ? ", so touch only this user's files and processes" : '')
                : '')
            // Hermes' cronjob tool says a job "will NOT fire until the gateway is started" whenever no
            // gateway runs (tools/cronjob_job_args.py, read in v2026.9.24); here the crontab tick fires
            // it (ADR-0025), and installing the gateway is a sudo path the tick exists to avoid.
            . (empty($config['cron']['disabled']) && !CronTick::refuses(!empty($profile['root']['available']))
                ? "\n- Cron jobs run from crontab; ignore \"gateway is not running\", never start the gateway"
                : '')
            . implode('', array_map(function ($n) {
                return "\n- Notice from " . $n['from'] . ': ' . $n['text'];
            }, self::notices()))
            . "\n\nSkills for other machines are listed too; use one only if it matches. Tool index: " . Units::toolsDir() . "/INDEX.md.";
    }

    // Hermes cuts a context file past 6% of the model's window in characters (4 per token), at least 20 000.
    // 61 440 is that cap at Hermes' fallback window of 256 000 tokens, used when the provider reports none;
    // a 1M-token window allows 240 000. A smaller reported window cuts sooner, keeping the first 70% and
    // telling the agent to read the rest (agent/prompt_builder.py, agent/model_metadata.py; ADR-0029).
    const SOUL_MAX = 61440;     // bytes of the whole SOUL.md (bytes >= characters, so this is on the safe side)
    const DOCTRINE_MAX = 16384; // bytes of one tools/<category>/DOCTRINE.md
    const NOTICES_MAX = 2;      // notice lines in SOUL.md
    const NOTICE_BYTES = 120;   // bytes of text per line
    const OWN_SLOT = 'local-changes'; // LocalHooks::NOTICE: shown first and beside the NOTICES_MAX others (ADR-0054)

    /** Test seam: a notices directory other than local/notices. */
    public static $noticesDir = null;

    /**
     * Short notices tools leave for the next session (ADR-0034: the scheduled Docker check), from
     * local/notices/<name>.txt, at most NOTICES_MAX in name order, plus local-changes first in a slot of its own: [['from' => name, 'text' => ...]]. SOUL.md is
     * the system-prompt tier, so a notice is counts and fixed words only: a file not owned by this account, a link
     * or a name outside [a-z0-9-] is skipped, the text is cut to NOTICE_BYTES and keeps only letters, digits, spaces
     * and plain punctuation (no markdown, no backticks, no line breaks). The tool that wrote a notice removes it.
     * Like tool doctrine, notices count against SOUL.md's 16000-byte total, not the 8000-byte static core.
     */
    public static function notices($dir = null)
    {
        $dir = $dir !== null ? $dir : (self::$noticesDir !== null ? self::$noticesDir : Config::localDir() . '/notices');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $out = [];
        $files = (array) glob($dir . '/*.txt');
        // The local-changes notice (ADR-0054) comes first in a slot of its own: no other notice can crowd it out.
        $own = "$dir/" . self::OWN_SLOT . '.txt';
        $files = array_merge(in_array($own, $files, true) ? [$own] : [], array_values(array_diff($files, [$own])));
        $others = 0;
        foreach ($files as $f) {
            $name = basename($f, '.txt');
            if ($name !== self::OWN_SLOT && $others >= self::NOTICES_MAX) {
                break;
            }
            if (!preg_match('/^[a-z0-9-]{1,32}$/', $name) || is_link($f) || !is_file($f) || @fileowner($f) !== $uid) {
                continue;
            }
            $text = preg_replace('/\s+/', ' ', (string) @file_get_contents($f, false, null, 0, 4096));
            $text = preg_replace('/[^A-Za-z0-9 .,:;!?()\/_+=%@\'"-]+/', '', (string) $text);
            $text = trim(substr(trim((string) preg_replace('/ {2,}/', ' ', (string) $text)), 0, self::NOTICE_BYTES));
            if ($text !== '') {
                $out[] = ['from' => $name, 'text' => $text];
                $others += $name === self::OWN_SLOT ? 0 : 1;
            }
        }
        return $out;
    }

    /**
     * The script the scheduled Docker check runs (ADR-0034): HERMES_HOME/scripts/pikkumies-docker-check.sh. Hermes
     * runs a cron script by its extension (.sh and .bash with bash, anything else with Python; the shebang is not
     * read, cron/scheduler_script.py in v2026.9.24) and only from its scripts directory, by a relative name. One
     * line: the containers unit's scheduled form. Returns [path, content].
     */
    public static function dockerCheckScript($home = null)
    {
        $home = $home !== null ? $home : self::home();
        $line = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Units::toolsDir() . '/containers/docker/docker.php') . " diagnose --changes\n";
        return [$home . '/scripts/pikkumies-docker-check.sh', $line];
    }

    /**
     * The script the scheduled performance sampling runs (ADR-0035): HERMES_HOME/scripts/pikkumies-performance-sample.sh,
     * `check --tick` (silent, one sample). Same rules as dockerCheckScript(); it exits 0 when the unit is missing, so a
     * job left behind by an older install never fails every 15 minutes. Returns [path, content].
     */
    public static function performanceSampleScript($home = null)
    {
        $home = $home !== null ? $home : self::home();
        $php = Units::toolsDir() . '/system/performance/performance.php';
        $line = '[ -f ' . escapeshellarg($php) . " ] || exit 0\n" . 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($php) . " check --tick\n";
        return [$home . '/scripts/pikkumies-performance-sample.sh', $line];
    }

    /** YAML double-quoted scalar; newlines and other control characters are escaped too (the model id comes from the endpoint). */
    public static function q($s)
    {
        $s = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], (string) $s);
        return '"' . preg_replace('/[\x00-\x1f\x7f]/', '', $s) . '"';
    }
}
