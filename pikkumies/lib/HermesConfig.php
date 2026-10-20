<?php
/**
 * HermesConfig: generates Hermes' config from the tracked tree, in two layers (ADR-0062).
 *
 * - local/hermes-managed/ (HERMES_MANAGED_DIR, Hermes' managed scope): config.yaml with the settings Pikkumies
 *   pins (provider and model, shell hooks, approvals, web fallback, logins, ...), which win over the user's file
 *   and which `hermes config set` refuses; .env with the provider key (0600). Rebuilt at every start.
 * - local/hermes/config.yaml (HERMES_HOME): the user's. Written once, at the first start, with every setting
 *   (skills.external_dirs: the skills generated from the tool units, ADR-0015; the feature defaults, ADR-0024);
 *   after that only Hermes and the user change it, and Pikkumies edits it only through HermesUserConfig (backed
 *   up, logged). local/hermes/.env is the user's too. A shipped skill in skills.disabled is not generated at all.
 *   A local skill must not reuse a shipped name: Hermes refuses an ambiguous name (ADR-0007).
 * - SOUL.md: what this machine allows (from detection), tools' notices + doctrine.
 * - scripts/pikkumies-docker-check.sh: what the scheduled Docker check runs (ADR-0034).
 * - scripts/pikkumies-performance-sample.sh: what the scheduled performance sampling runs (ADR-0035).
 * Every file Pikkumies rebuilds is written to a temporary name and renamed: a full disk leaves the old file, never
 * an empty config without the safety hooks.
 */
require_once __DIR__ . '/Links.php';
require_once __DIR__ . '/HermesUserConfig.php';
require_once __DIR__ . '/AskReview.php'; // AskReview::TIMEOUT in the pre_tool_call entry
require_once __DIR__ . '/LocalNotes.php';
require_once __DIR__ . '/HookInterval.php';
require_once __DIR__ . '/PathName.php';

class HermesConfig
{
    public static function home()
    {
        return Config::localDir() . '/hermes';
    }

    /** Hermes' managed scope (HERMES_MANAGED_DIR): the settings Pikkumies pins, beside $home (ADR-0062). */
    public static function managedDir($home = null)
    {
        return dirname($home !== null ? $home : self::home()) . '/hermes-managed';
    }

    /**
     * The environment every Hermes Pikkumies starts gets: its home, the managed scope beside it, its own rendezvous
     * directory, and no HERMES_RUNTIME_DIR (pm's tool store follows HERMES_HOME, as at install, ADR-0033). A null value
     * removes the variable. Without HERMES_MANAGED_DIR Hermes would run without the pinned settings and hooks (ADR-0062).
     * HERMES_GATEWAY_LOCK_DIR: Hermes keeps one backend record per OS user (gateway/status.py _get_lock_dir), and a
     * `hermes dashboard` attaches to the backend that record names; without it, `pikkumies run dashboard` could hand
     * the user a plain Hermes started on the same account, without Pikkumies' settings and hooks (ADR-0074).
     */
    public static function env($home = null)
    {
        $home = $home !== null ? $home : self::home();
        return ['HERMES_HOME' => $home, 'HERMES_MANAGED_DIR' => self::managedDir($home), 'HERMES_GATEWAY_LOCK_DIR' => $home . '/gateway-locks', 'HERMES_RUNTIME_DIR' => null];
    }

    /** env() as an `env` argv prefix (unsets first, as env(1) requires), for one child; the caller's own environment is untouched. */
    public static function envArgv($home = null)
    {
        $unset = [];
        $set = [];
        foreach (self::env($home) as $name => $value) {
            if ($value === null) {
                $unset[] = '-u';
                $unset[] = $name;
            } else {
                $set[] = $name . '=' . $value;
            }
        }
        return array_merge(['env'], $unset, $set);
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
        self::writeConfig($home, $prov, $config, $light);
        self::put($home . '/SOUL.md', self::renderSoul($profile, $config));
        foreach ([self::dockerCheckScript($home), self::performanceSampleScript($home)] as $job) {
            list($script, $line) = $job;
            @mkdir(dirname($script), 0700, true);
            self::put($script, $line, 0700);
        }
        return $home;
    }

    /** The two config layers (ADR-0062) and the Pikkumies skin (ADR-0061); throws when the managed files cannot be written. */
    public static function writeConfig($home, array $prov, array $config, $light = '')
    {
        $managed = self::managedDir($home);
        @mkdir($managed, 0700, true);
        $hooks = self::hookEntries();
        $lazy = !empty($config['hermes']['lazyInstalls']);
        $web = !empty($config['web']['search']);
        // The pins, at every start: a key or setting changed after install reaches Hermes (ADR-0005), and a run
        // never starts without the hooks, since a failed write throws (ADR-0062).
        self::put($managed . '/config.yaml', self::renderYaml($prov, [], $hooks, $lazy, $light, true, $web) . self::sessionsYaml($config, true));
        // Key lives only in the managed .env (0600); config.yaml points at it via providers.pikkumies.key_env.
        self::put($managed . '/.env', "PIKKUMIES_API_KEY=" . $prov['key'] . "\n", 0600);
        // The skin display.skin names (StatusTheme), rebuilt like the managed files, before the seed can name it.
        @mkdir($home . '/skins', 0700, true);
        self::put($home . '/skins/' . StatusTheme::SKIN . '.yaml', StatusTheme::skinYaml());
        // The user's config.yaml: seeded once with everything, then only careful, backed-up, logged edits.
        HermesUserConfig::apply($home, self::renderYaml($prov, self::skillDirs(), $hooks, $lazy, $light) . self::sessionsYaml($config),
            Units::generatedDir(), self::hiddenShipped(isset($config['skills']['disabled']) ? (array) $config['skills']['disabled'] : []));
    }

    /**
     * The names in skills.disabled that are shipped skills: Generate leaves those out, so they leave Hermes' own list.
     * Any other name (a skill Hermes ships, say) stays in Hermes' list, where the user's choice already is (ADR-0062).
     */
    public static function hiddenShipped(array $disabled)
    {
        return $disabled ? array_values(array_intersect($disabled, array_map(function ($u) {
            return $u['name'];
        }, Units::all()))) : [];
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
     * timeout run one after another, plus 2 seconds (and AskReview::TIMEOUT on pre_tool_call, ADR-0073), within Hermes' maximum of 300. display_errors=stdout
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
            $timeout = 2 + ($event === 'pre_tool_call' ? AskReview::TIMEOUT : 0); // the ask review's time (ADR-0073)
            foreach ($units as $u) {
                $timeout += $u['timeout'];
            }
            $timeout = min(300, $timeout);
            $entries[$event] = self::hookEntry($hooksDir, $event, $timeout);
        }
        // Interval units (ADR-0067) never get an entry of their own (Hermes refuses an unknown event); while one is on, the
        // orchestrator must see each turn: the user's message, the tool calls and, once per turn, post_llm_call.
        if (HookInterval::on(Config::load(), $hooksDir)) {
            foreach (['pre_llm_call', 'post_tool_call', 'post_llm_call'] as $event) {
                $entries[$event] = isset($entries[$event]) ? $entries[$event] : self::hookEntry($hooksDir, $event, 5);
            }
        }
        ksort($entries);
        return $entries;
    }

    private static function hookEntry($hooksDir, $event, $timeout)
    {
        return ['command' => PHP_BINARY . ' -d display_errors=stdout -d error_reporting=4437 ' . $hooksDir . '/orchestrator.php ' . $event . ' ' . $timeout,
            'failClosed' => $event === 'pre_tool_call', 'timeout' => $timeout];
    }

    /**
     * The Hermes config. $pinsOnly false: the user's config.yaml as first seeded (every setting). $pinsOnly true:
     * the managed config.yaml, only the settings Pikkumies pins (ADR-0062): no skills.external_dirs (Hermes' skills
     * loader reads it from the user's file only), and no feature defaults the user may change (checkpoints,
     * plugins, background review, curator; pinning plugins.enabled would also refuse every other plugin).
     */
    public static function renderYaml(array $prov, array $skillDirs, array $hooks, $lazyInstalls = false, $goalJudgeModel = '', $pinsOnly = false, $webSearch = false)
    {
        $y = $pinsOnly ? self::managedHeader() : self::seedHeader();
        // A named provider: Hermes reads key_env for entries under providers: (hermes_cli/providers.py);
        // a bare `provider: custom` ignored model.key_env when this was first verified (ADR-0005).
        // switch_context_confirm_tokens 0: no question before a model switch in a long session (model_selection_guards.py).
        $y .= "model:\n  provider: \"custom:pikkumies\"\n  default: " . self::q($prov['model']) . "\n  switch_context_confirm_tokens: 0\n";
        $y .= "providers:\n  pikkumies:\n    base_url: " . self::q($prov['endpoint']) . "\n    key_env: PIKKUMIES_API_KEY\n    default_model: " . self::q($prov['model']) . "\n";
        $y .= "skills:\n";
        if (!$pinsOnly) {
            $y .= "  external_dirs:\n";
            foreach ($skillDirs as $d) {
                $y .= "    - " . self::q($d) . "\n";
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
        // web.search on (the person's `pikkumies websearch on`, ADR-0082): ddgs, Hermes' one keyless provider (DuckDuckGo),
        // pinned in the managed layer only, so it leaves again at the next start after `off`. Off: no backend is named,
        // and a backend the user set up in Hermes themselves stays theirs (ADR-0062). The seed never names one.
        $y .= "web:\n  keyless_fallback: false\n  keyless_rescue: false\n" . ($pinsOnly && $webSearch ? "  search_backend: \"ddgs\"\n" : '');
        // Hermes would otherwise pip-install optional packages (unpinned, from PyPI) and download a browser
        // at runtime (tools/lazy_deps.py), which costs every account on a shared server the download.
        // Off unless the user sets hermes.lazyInstalls (ADR-0021), which also decides whether the install
        // declines the optional tools; the required ones (node, ffmpeg, ...) always come (ADR-0033).
        // protected_instruction_files off: Hermes would ask the person before every write_file or patch of an agent
        // instruction file (AGENTS.md, SOUL.md and the like) or a project .hermes file, even with approvals off
        // (tools/file_tools_write_guards.py). Such a write can be undone and its terminal form was never asked; Pikkumies'
        // own SOUL.md is rebuilt at every start and its hooks and settings have their own checks (ADR-0073 amendment).
        $y .= "security:\n  allow_lazy_installs: " . ($lazyInstalls ? 'true' : 'false') . "\n  protected_instruction_files: false\n";
        // approvals.mode "off": Hermes never shows its approval prompt; its hardline floor (rm -rf /, mkfs on a disk, ...)
        // and the user's approvals.deny still block (tools/approval.py, hermes_cli/approvals_test.py). "off" also approves
        // every hook "approve" answer without asking (_run_approval_gate), so Pikkumies never sends one: the orchestrator's
        // ask policy drops each question or blocks the call until the user says yes in the chat (lib/AskPolicy.php).
        // Quoted: YAML reads a bare off as false (Hermes maps that to "off" as well). No question before the person's own
        // /new, /clear, /reset, /undo or /reload-mcp either (cli_modal_mixin.py, cli_info_mixin.py). ADR-0073.
        $y .= "approvals:\n  mode: \"off\"\n  destructive_slash_confirm: false\n  mcp_reload_confirm: false\n";
        // No first-contact offer: on the first message Hermes otherwise tells the agent to offer a profile and to ask
        // consent before every lookup (agent/onboarding.py), and run starts the desktop's onboarding chat (ADR-0073).
        $y .= "onboarding:\n  profile_build: \"off\"\n  run: false\n";
        // Per-turn working-directory snapshots (/rollback): Hermes defaults this off; the size caps
        // (max_snapshots, max_total_size_mb, max_file_size_mb) stay at Hermes' own values (ADR-0024).
        $y .= $pinsOnly ? '' : "checkpoints:\n  enabled: true\n";
        // Opt-in allow-list (ADR-0024): a static scan of file writes, no model call, no cost.
        $y .= $pinsOnly ? '' : "plugins:\n  enabled:\n    - \"security-guidance\"\n";
        // Set on explicitly so a future Hermes default change cannot silently turn off the post-turn
        // self-improvement fork; consolidate (its aux-model cost) is left at Hermes' own off (ADR-0024).
        $y .= $pinsOnly ? ($goalJudgeModel !== '' ? "auxiliary:\n" : '') : "auxiliary:\n  background_review:\n    enabled: true\n";
        // /goal's own judge (hermes_cli/goals.py auxiliary.goal_judge.provider/.model): the light model
        // already decided for hooks, under the one named provider every call here uses (ADR-0047).
        // goals.max_turns stays at Hermes' own default; nothing here was measured to pick a number.
        if ($goalJudgeModel !== '') {
            $y .= "  goal_judge:\n    provider: \"pikkumies\"\n    model: " . self::q($goalJudgeModel) . "\n";        }
        // The seed only, so the line stays the user's: clarify is how Hermes lets the agent stop to ask; the tool hook
        // hooks/tools/clarify/decide-yourself blocks it on every install, this keeps it out of the model's list (ADR-0073).
        $y .= $pinsOnly ? '' : "agent:\n  disabled_toolsets:\n    - \"clarify\"\n";
        // The seed only, so the look stays the user's (/skin): the Pikkumies skin, written beside this file by writeConfig;
        // an install from before gets it once through HermesUserConfig (ADR-0061, 2026-10-19 section).
        $y .= $pinsOnly ? '' : "display:\n  skin: " . self::q(StatusTheme::SKIN) . "\n" . (Eternal::enabled() ? "  busy_input_mode: queue\n" : '');
        // The seed only (Hermes reads browser.* from the user's own file, read_raw_config, so it cannot be pinned): the
        // denylist for sensitive JavaScript in the browser (tools/browser_tool_eval_policy.py). The browse unit offers the
        // browser only while this is true and allow_private_urls and use_real_profile are not on (ADR-0077).
        $y .= $pinsOnly ? '' : "browser:\n  restrict_evaluate: true\n";
        // Set on explicitly (already Hermes' default): background pruning/archiving of agent-created skills;
        // consolidate stays off, left at Hermes' own default (ADR-0024).
        $y .= $pinsOnly ? '' : "curator:\n  enabled: true\n";
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

    /** Line 1 of the user's config.yaml as seeded; HermesUserConfig replaces the old "rewritten" header with it. */
    public static function seedHeader()
    {
        return "# Written by pikkumies at the first start; yours from then on, Hermes' own edits stay. Settings in "
            . Config::shown(self::managedDir() . '/config.yaml') . " win over this file; change those in " . Config::shown(Config::localDir() . '/config.php') . ".\n";
    }

    public static function managedHeader()
    {
        return "# Generated by pikkumies at every start: the settings it pins, over " . Config::shown(self::home() . '/config.yaml')
            . ". Edit " . Config::shown(Config::localDir() . '/config.php') . " instead; this file is rewritten.\n";
    }

    /**
     * Appended to config.yaml (ADR-0032). sessions: Hermes prunes ended sessions older than
     * retention_days at start (auto_prune, hermes_cli/config_defaults.py "sessions"); our
     * sessions.retentionDays 0 means keep them forever, so pruning is off. telemetry: Hermes' own shared metrics stay
     * off; the user's file gets Hermes' own "No thanks" answer, which is what Hermes reads, and the managed file pins
     * only send (HermesTelemetry, ADR-0072).
     */
    public static function sessionsYaml(array $config, $pinsOnly = false)
    {
        $days = isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0;
        $y = $days > 0 ? "sessions:\n  auto_prune: true\n  retention_days: $days\n" : "sessions:\n  auto_prune: false\n";
        return $y . ($pinsOnly ? (Eternal::enabled() ? "session:\n  terminal_continue: true\n" : '') . HermesTelemetry::managedYaml()
            : HermesTelemetry::seedYaml(HermesTelemetry::offerVersion()));
    }

    /**
     * SOUL.md: what this machine allows, the static doctrine (soul, then the name line when the user
     * named the agent, ADR-0032; voice, doctrine, safety), then "## Tool doctrine" with the DOCTRINE.md
     * of every enabled tool category (ADR-0029), then the user's local notes for a category as recorded at the last
     * start (LocalNotes, ADR-0076), after all shipped text; when SOUL.md would pass SOUL_MAX, local notes are left out
     * from the last one, and a line says how many. $toolDoctrine false gives the core alone, whose budget is 8000 bytes.
     * $toolsDir: a test tree instead of the shipped tools (as Units::doctrines); local/ is read beside it.
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
        $disabled = $toolDoctrine ? Generate::disabledCategories($config) : [];
        $rules = $toolDoctrine ? Units::doctrines($disabled, $toolsDir) : [];
        $local = [];
        if ($toolDoctrine) {
            $root = dirname($toolsDir ?: Units::toolsDir());
            $local = LocalNotes::visible(LocalNotes::scan($root, $toolsDir), LocalHooks::record($root), $disabled, $toolsDir ?: Units::toolsDir())['categories'];
        }
        for ($left = 0;; $left++) {
            $section = self::toolDoctrine($rules, array_slice($local, 0, count($local) - $left, true), $left);
            // ADR links in the doctrine are relative to the repository; in SOUL.md (in HERMES_HOME) they point nowhere (ADR-0060).
            $soul = Links::stripAdrLinks(implode("\n\n---\n\n", $section === '' ? $parts : array_merge($parts, [$section]))) . "\n";
            if (strlen($soul) <= self::SOUL_MAX || $left >= count($local)) {
                return $soul;
            }
        }
    }

    /** "## Tool doctrine": the shipped category rules, then the local notes; $left local note files did not fit. '' for none. */
    private static function toolDoctrine(array $rules, array $local, $left)
    {
        if (!$rules && !$local && !$left) {
            return '';
        }
        $section = "## Tool doctrine\n\nRules for each tool category; follow them whenever you use its tools.";
        foreach ($rules as $category => $text) {
            $section .= "\n\n### $category\n\n" . $text;
        }
        if ($local || $left) {
            $section .= "\n\n### Local notes\n\n" . LocalNotes::INTRO;
            foreach ($local as $category => $text) {
                $section .= "\n\n#### $category\n\n" . $text;
            }
            if ($left) {
                $section .= "\n\n($left local note file" . ($left > 1 ? 's' : '') . ' left out: SOUL.md would pass its size limit. `pikkumies-configure notes` lists every note.)';
            }
        }
        return $section;
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
            // Opt-in (ADR-0082): off, the one question is the person's own command; the agent never turns it on.
            . (!empty($config['web']['search'])
                ? "\n- Web search: on; each web_search sends its query to DuckDuckGo"
                : "\n- Web search: off. Asked to search the web, say it is off and that the user's command `pikkumies websearch on` turns it on; never turn it on yourself")
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
            $name = PathName::base($f, '.txt');
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
