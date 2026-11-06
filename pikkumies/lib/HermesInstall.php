<?php
/**
 * HermesInstall: vanilla Hermes Agent, a git checkout of upstream at pikkumies/hermes (ADR-0033).
 *
 * Install clones the newest stable release tag (depth 1) into pikkumies/hermes and bootstraps it the
 * way upstream's own installers do (scripts/install.sh, setup-hermes.sh): the uv pinned in pm/lock.json
 * (sha256-checked), a bootstrap Python from that uv, then upstream's package manager `pm.cli install`,
 * which commits the dependency environment and the tools under HERMES_HOME (local/hermes) and writes
 * the tree's own launcher .hermes/bin/hermes. No venv, no pip, no shell-file edits, no ~/.local/bin.
 * It runs in the background under include/.hermes-build.lock (log: local/install.log).
 * Pikkumies never edits, strips, rebuilds or updates the tree: Hermes updates itself with its own
 * updater (`pikkumies run update`, or /update in a session), never from `pikkumies update`.
 * The ready marker lives OUTSIDE the tree (include/.hermes-ready), because Hermes' updater stashes
 * untracked files. Nothing here deletes a tree: an incomplete one is moved aside and reported.
 * Because the code can change under us, every start checks a changed checkout first (verify()).
 */
require_once __DIR__ . '/PathName.php';

class HermesInstall
{
    const REPO = 'https://github.com/NousResearch/hermes-agent.git';
    // A stable release tag as upstream selects one (hermes_cli/update_channel.py STABLE_TAG_RE): vX.Y.Z
    // with a major of at most three digits, so the old date tags (v2026.9.24) and canaries never match.
    const STABLE_TAG_RE = '/^v(?:0|[1-9]\d{0,2})\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/';
    // The official repository in the URL forms upstream accepts for origin (hermes_cli/source_releases.py).
    const ORIGIN_RE = '#^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)NousResearch/hermes-agent(?:\.git)?/?$#i';
    // What the delete guard answers for the mkfs in verify() (hooks/guard/guard-destructive; rm -rf is asked, ADR-0073); any other block,
    // such as "could not be read", means the payload did not reach the guard the way a real call does.
    const GUARD_BLOCK = 'Pikkumies safety: filesystem format';
    // Optional tools upstream's installer can leave out (install.sh --skip-browser / --skip-computer-use);
    // pm records the choice, so `hermes update` keeps them out too (pm/defaults.py). Left out unless
    // hermes.lazyInstalls is on (ADR-0021).
    const OPTIONAL_TOOLS = ['agent-browser', 'cua-driver'];
    // The ddgs Python package (DuckDuckGo search, keyless), built in so `pikkumies websearch on` works at once (ADR-0082). The
    // package alone sends nothing: Hermes names it only while the person has web.search on. Its own `pm.cli install --extra`
    // call, after the full one: extras union into the installed venv (pm/install.py), so the default closure is unchanged.
    const WEB_SEARCH_EXTRA = 'ddgs';
    // curl exit codes for which upstream retries the identical bytes from its mirror (setup-hermes.sh fetch_pinned).
    const MIRROR_RETRY_CODES = [5, 6, 7, 18, 22, 28, 52, 55, 56];

    /**
     * Each config key HermesConfig writes (or HermesUserConfig edits) => [file in the Hermes tree, text that must be in it]. A key
     * whose text is gone means our setting may no longer apply (approvals, logins, web fallback), so a
     * changed checkout with a missing key is refused (verify()). tests/HermesInstallTest.php fails when
     * HermesConfig writes a top-level key that is not listed here.
     */
    const CONFIG_FACTS = [
        'model.provider' => ['hermes_cli/config_defaults.py', '"provider":'],
        'model.default' => ['hermes_cli/config_defaults.py', '"default":'],
        'providers.*.base_url' => ['hermes_cli/providers.py', 'entry.get("base_url"'],
        'providers.*.key_env' => ['hermes_cli/providers.py', 'entry.get("key_env")'],
        'providers.*.default_model' => ['hermes_cli/runtime_provider_custom.py', 'entry.get("default_model"'],
        'skills.external_dirs' => ['hermes_cli/config_defaults.py', '"external_dirs":'],
        'skills.disabled' => ['agent/skill_utils.py', 'skills_cfg.get("disabled")'],
        'skills.guard_agent_created' => ['hermes_cli/config_defaults.py', '"guard_agent_created":'],
        'updates.check' => ['hermes_cli/config_defaults.py', '"check":'],
        'web.keyless_fallback' => ['hermes_cli/config_defaults.py', '"keyless_fallback":'],
        'web.keyless_rescue' => ['hermes_cli/config_defaults.py', '"keyless_rescue":'],
        'web.search_backend' => ['hermes_cli/config_defaults.py', '"search_backend":'],
        'security.allow_lazy_installs' => ['hermes_cli/config_defaults.py', '"allow_lazy_installs":'],
        'approvals.mode' => ['tools/approval_context.py', '_VALID_MODES = ("manual", "smart", "off")'],
        'approvals.destructive_slash_confirm' => ['hermes_cli/config_defaults.py', '"destructive_slash_confirm":'],
        'approvals.mcp_reload_confirm' => ['hermes_cli/config_defaults.py', '"mcp_reload_confirm":'],
        'model.switch_context_confirm_tokens' => ['hermes_cli/model_selection_guards.py', 'model_cfg.get("switch_context_confirm_tokens")'],
        'security.protected_instruction_files' => ['tools/file_tools_write_guards.py', '"security", "protected_instruction_files"'],
        'checkpoints.enabled' => ['hermes_cli/config_defaults.py', '"checkpoints":'],
        'plugins.enabled' => ['hermes_cli/config_defaults.py', '"plugins":'],
        'auxiliary.background_review.enabled' => ['hermes_cli/config_defaults.py', '"background_review":'],
        'curator.enabled' => ['hermes_cli/config_defaults.py', '"curator":'],
        'auth.adopt_external_logins' => ['hermes_cli/config_defaults.py', '"adopt_external_logins":'],
        'cli.expose_on_path' => ['hermes_cli/_launchers.py', '"expose_on_path"'],
        'hooks_auto_accept' => ['hermes_cli/config_defaults.py', '"hooks_auto_accept":'],
        'hooks.*.fail_closed' => ['agent/shell_hooks.py', 'raw.get("fail_closed"'],
        'sessions.auto_prune' => ['hermes_cli/config_defaults.py', '"auto_prune":'],
        'sessions.retention_days' => ['hermes_cli/config_defaults.py', '"retention_days":'],
        'telemetry.shared_metrics' => ['hermes_cli/config_defaults.py', '"shared_metrics":'],
        'onboarding.profile_build' => ['agent/onboarding.py', '_onboarding_section(config).get("profile_build")'],
        'onboarding.run' => ['hermes_cli/config_defaults.py', '"run": None,'],
        'agent.disabled_toolsets' => ['hermes_cli/tools_config.py', '.get("disabled_toolsets")'],
        // The answer HermesTelemetry records; without it the dashboard and chat ask about sending metrics (ADR-0072).
        'telemetry.shared_metrics.offer_version' => ['hermes_cli/observability/shared_metrics_consent.py', 'section["offer_version"] = OFFER_VERSION'],
        // The skin the seed selects; its file is read from HERMES_HOME/skins (skin_engine.py load_skin) (ADR-0061).
        'display.skin' => ['hermes_cli/skin_engine.py', 'display.get("skin", "default")'],
        // The browser's JavaScript denylist, seeded and set once on older installs (HermesUserConfig::offerBrowse, ADR-0077).
        'browser.restrict_evaluate' => ['tools/browser_tool_eval_policy.py', '_browser_eval_flag("restrict_evaluate")'],
    ];

    /**
     * Hermes' managed scope, which holds the settings Pikkumies pins (ADR-0062): the HERMES_MANAGED_DIR override, the
     * overlay over config.yaml, its .env (the provider key) and the refusal of `config set` on a pinned key. A changed
     * checkout without one of them is refused, as for CONFIG_FACTS; verify() also tries the refusal itself.
     */
    const LAYER_FACTS = [
        'HERMES_MANAGED_DIR' => ['hermes_cli/managed_scope.py', 'os.environ.get("HERMES_MANAGED_DIR"'],
        'managed config.yaml' => ['hermes_cli/managed_scope.py', 'def apply_managed_overlay('],
        'managed .env' => ['hermes_cli/env_loader.py', 'def _apply_managed_env('],
        'managed key refusal' => ['hermes_cli/config.py', 'def _exit_if_key_managed('],
    ];
    // What Hermes prints when it refuses a pinned key, and when the user's own config.yaml does not parse.
    const MANAGED_REFUSAL = 'managed by your administrator';
    const PARSE_ERROR = 'formatting error';

    /** Test seams: another pikkumies/ directory, and a local repository instead of GitHub. */
    public static $root = null;
    public static $repo = null;

    public static function root()
    {
        return self::$root !== null ? self::$root : Config::root();
    }

    public static function repo()
    {
        return self::$repo !== null ? self::$repo : self::REPO;
    }

    public static function includeDir()
    {
        return self::root() . '/include';
    }

    public static function localDir()
    {
        return self::root() . '/local';
    }

    /** The Hermes checkout: the unmodified upstream tree (its launchers in .hermes/bin, ignored by upstream's .gitignore). */
    public static function treeDir()
    {
        return self::root() . '/hermes';
    }

    /** Written last by a build; outside the tree, which Hermes' updater would stash. */
    public static function markerFile()
    {
        return self::includeDir() . '/.hermes-ready';
    }

    /** Every Hermes start holds it shared; `pikkumies run update` holds it exclusive. */
    public static function lockFile()
    {
        return self::localDir() . '/hermes.lock';
    }

    public static function isComplete()
    {
        return file_exists(self::treeDir() . '/.git') && is_file(self::markerFile());
    }

    /** HERMES_HOME: where pm keeps the dependency environment, the tools and its caches. */
    public static function hermesHome()
    {
        return self::localDir() . '/hermes';
    }

    /**
     * The hermes command of a complete install, '' otherwise: the launcher upstream's package manager
     * writes into the tree (hermes_cli/venv_sync.py publish_launchers); it runs pm's committed environment.
     */
    public static function bin()
    {
        $path = self::treeDir() . '/.hermes/bin/hermes';
        return self::isComplete() && is_file($path) && is_executable($path) ? $path : '';
    }

    /** The installed Hermes source, for the contract tests and the key check; '' when not installed. */
    public static function sourceDir()
    {
        return self::isComplete() ? self::treeDir() : '';
    }

    /** The pm/lock.json artifact key for this machine (setup-hermes.sh `target`), '' when upstream has no build. */
    public static function target($os = null, $machine = null)
    {
        $os = $os === null ? php_uname('s') : $os;
        $machine = $machine === null ? php_uname('m') : $machine;
        $o = ['Linux' => 'linux', 'Darwin' => 'darwin'];
        $a = ['x86_64' => 'x64', 'amd64' => 'x64', 'aarch64' => 'arm64', 'arm64' => 'arm64'];
        return isset($o[$os], $a[$machine]) ? $o[$os] . '-' . $a[$machine] : '';
    }

    /** [version, url, sha256] of the uv pinned in pm/lock.json for $target, null when there is none. */
    public static function uvPin(array $lock, $target)
    {
        $uv = isset($lock['packages']['uv']) ? $lock['packages']['uv'] : null;
        $art = isset($uv['artifacts'][$target]) ? $uv['artifacts'][$target] : null;
        if (!is_array($art) || !isset($uv['version'], $art['url'], $art['sha256']) || !preg_match('/^[0-9A-Za-z._-]+$/', (string) $uv['version'])
            || !preg_match('/^[0-9a-f]{64}$/i', (string) $art['sha256']) || strpos((string) $art['url'], 'https://') !== 0 && strpos((string) $art['url'], 'file://') !== 0) {
            return null;
        }
        return ['version' => (string) $uv['version'], 'url' => (string) $art['url'], 'sha256' => strtolower((string) $art['sha256'])];
    }

    /** The bootstrap Python minor version pinned in pm/lock.json (3.14.7+20260901 -> 3.14), '' when there is none. */
    public static function pythonPin(array $lock)
    {
        $v = isset($lock['packages']['python']['version']) ? (string) $lock['packages']['python']['version'] : '';
        return preg_match('/^(\d+\.\d+)(\.|\+|$)/', $v, $m) ? $m[1] : '';
    }

    /** The installed tree's pm/lock.json, [] when it cannot be read. */
    public static function lockData($tree = null)
    {
        $lock = json_decode((string) @file_get_contents(($tree !== null ? $tree : self::treeDir()) . '/pm/lock.json'), true);
        return is_array($lock) ? $lock : [];
    }

    // The newest Hermes release this Pikkumies has checked its settings against. v0.22.6 (2026-11-02) removed the built-in
    // browser tools and with them browser.restrict_evaluate (hermes_cli/config_defaults.py REMOVAL NOTE), which the browse
    // guard of ADR-0077 sets, so CONFIG_FACTS refuses to start it; installs stay on v0.22.5 until that guard is moved to the
    // new browser (ADR-0033 amendment 2026-11-03).
    const NEWEST_CHECKED = '0.22.5';

    /**
     * The release tag of a checkout Pikkumies installed and can never start, '' for any other tree: an unmodified checkout of the
     * official repository at a stable tag newer than NEWEST_CHECKED, missing a key of CONFIG_FACTS, that never passed verify()
     * (no hermes-verified.json). A tree with the person's own changes, another origin or a verified start is left alone.
     */
    public static function refusedNewerRelease()
    {
        if (file_exists(self::localDir() . '/hermes-verified.json') || !self::missingConfigKeys(self::treeDir())) {
            return '';
        }
        // Files first, so a complete install that is not this case runs no git (a session start reads this).
        $tag = self::packedTagAtHead(self::treeDir() . '/.git');
        if ($tag === '' || !preg_match(self::STABLE_TAG_RE, $tag) || version_compare(substr($tag, 1), self::NEWEST_CHECKED, '<=')) {
            return '';
        }
        $s = self::status();
        return $s['clean'] && $s['official'] && $s['rev'] === $tag ? $tag : '';
    }

    // What a Pikkumies build or a Hermes start adds inside a release checkout, all ignored by upstream and all rebuilt by the
    // next build: pm's launcher and state, the web UI build, Python bytecode and the version file (HermesInstall, HermesWebUi).
    // Top-level directories, directories at any depth, exact files, and suffixes.
    const BUILD_OUTPUT_DIRS = ['.hermes/', 'hermes_cli/web_dist/'];
    const BUILD_OUTPUT_ANY_DEPTH = ['node_modules/', '__pycache__/'];
    const BUILD_OUTPUT_FILES = ['.hermes-bootstrap-complete', 'hermes_cli/_version.py'];
    const BUILD_OUTPUT_SUFFIXES = ['.pyc'];
    // Everything the bootstrap (pm install) puts in the tree's own .hermes/, as found in Pikkumies-built trees (2026-11-03).
    // Upstream also loads project plugins from <cwd>/.hermes/plugins, so anything else there may be the person's.
    const DOT_HERMES_BUILT = ['bin' => 'dir', 'bin/hermes' => 'file', 'bin/hermes-acp' => 'file'];

    /**
     * True when the checkout at $dir is the official release $tag with nothing of anyone's added: clean, `describe` is the tag,
     * and every ignored path is build output (BUILD_OUTPUT_*, git status --ignored). Then the public tag is a full copy of it.
     */
    public static function onlyReleaseAndBuildOutput($dir, $tag)
    {
        if (is_link($dir) || $tag === '' || self::git(['describe', '--tags', '--exact-match', 'HEAD'], $dir)[1] !== $tag
            || !self::originOfficial(self::git(['config', '--get', 'remote.origin.url'], $dir)[1])) {
            return false;
        }
        list($code, $out) = self::git(['status', '--porcelain', '--ignored'], $dir);
        if ($code !== 0) {
            return false;
        }
        foreach ($out === '' ? [] : preg_split('/\r?\n/', $out) as $line) {
            if (strncmp($line, '!! ', 3) !== 0 || !self::isBuildOutput(substr($line, 3))
                || (strpos(substr($line, 3), '.hermes/') === 0 && !self::dotHermesOnlyBuilt($dir . '/.hermes'))) {
                return false;
            }
        }
        return true;
    }

    /** A path git lists as ignored that is build output (BUILD_OUTPUT_*). */
    public static function isBuildOutput($path)
    {
        foreach (self::BUILD_OUTPUT_DIRS as $d) {
            if (strpos($path, $d) === 0) {
                return true;
            }
        }
        foreach (self::BUILD_OUTPUT_ANY_DEPTH as $d) {
            if (strpos($path, $d) === 0 || strpos($path, '/' . $d) !== false) {
                return true;
            }
        }
        foreach (self::BUILD_OUTPUT_SUFFIXES as $x) {
            if (strlen($path) > strlen($x) && substr($path, -strlen($x)) === $x) {
                return true;
            }
        }
        return in_array($path, self::BUILD_OUTPUT_FILES, true);
    }

    /** True when $dir (a tree's .hermes) holds exactly entries of DOT_HERMES_BUILT, of their kind, and no symlink. */
    public static function dotHermesOnlyBuilt($dir)
    {
        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $entry) {
            $rel = substr($entry->getPathname(), strlen($dir) + 1);
            $kind = isset(self::DOT_HERMES_BUILT[$rel]) ? self::DOT_HERMES_BUILT[$rel] : '';
            if ($entry->isLink() || $kind === '' || ($kind === 'dir') !== $entry->isDir()) {
                return false;
            }
        }
        return true;
    }

    /** Remove $dir and everything under it, symlinks as links (never followed). True when nothing is left. */
    public static function removeTree($dir)
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $entry) {
            $p = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
        clearstatcache();
        return !file_exists($dir);
    }

    /** [entries, bytes] under $dir (directories count, as in the file quota), symlinks not followed. */
    public static function treeSize($dir)
    {
        $files = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $entry) {
            $files++;
            $bytes += $entry->isLink() ? 0 : $entry->getSize();
        }
        return [$files, $bytes];
    }

    /**
     * For doctor and a refused start: the one command that rebuilds a refused newer release when the daily update had no room
     * for it (an automatic build keeps 10% of the quota free), with what it needs. '' when this tree is not such a release.
     */
    public static function refusedFix()
    {
        $tag = self::isComplete() ? self::refusedNewerRelease() : '';
        if ($tag === '') {
            return '';
        }
        list($entries) = self::treeSize(self::treeDir());
        return 'Hermes ' . $tag . ' is newer than the release Pikkumies has checked (v' . self::NEWEST_CHECKED . '): run `pikkumies install` to rebuild Hermes at v'
            . self::NEWEST_CHECKED . '; it needs about ' . number_format((int) ceil(2 * $entries / 1000)) . ' thousand free files in your quota.';
    }

    /** The tag in packed-refs that names the detached HEAD commit (a release clone), '' when there is none. Reads files only. */
    public static function packedTagAtHead($gitDir)
    {
        $head = trim((string) @file_get_contents($gitDir . '/HEAD'));
        if (!preg_match('/^[0-9a-f]{40,64}$/', $head)) {
            return '';
        }
        $last = '';
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($gitDir . '/packed-refs')) as $line) {
            if (preg_match('#^([0-9a-f]{40,64}) refs/tags/(\S+)$#', $line, $m)) {
                if ($m[1] === $head) {
                    return $m[2];
                }
                $last = $m[2];
            } elseif ($last !== '' && $line === '^' . $head) {
                return $last; // the peeled commit of an annotated tag
            } else {
                $last = '';
            }
        }
        return '';
    }

    /** The newest stable release tag in `git ls-remote --tags` output, no newer than NEWEST_CHECKED; '' when there is none. */
    public static function latestTag($lsRemote)
    {
        $best = '';
        foreach (preg_split('/\r?\n/', (string) $lsRemote) as $line) {
            // Peeled lines (v1.2.3^{}) never match: the tag name may not contain "^".
            if (preg_match('#^[0-9a-f]{40,64}\s+refs/tags/(v[^\s^]+)$#', trim($line), $m) && preg_match(self::STABLE_TAG_RE, $m[1])
                && version_compare(substr($m[1], 1), self::NEWEST_CHECKED, '<=')
                && ($best === '' || version_compare(substr($m[1], 1), substr($best, 1), '>'))) {
                $best = $m[1];
            }
        }
        return $best;
    }

    /**
     * git with the options that keep a tree the agent can write from running code through git:
     * no fsmonitor, no hooks, no optional locks. Returns [exit code, trimmed stdout].
     */
    public static function git(array $args, $dir = null)
    {
        $argv = array_merge(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null'], $dir !== null ? ['-C', $dir] : [], $args);
        $out = [];
        exec('GIT_TERMINAL_PROMPT=0 ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null', $out, $code);
        return [$code, trim(implode("\n", $out))];
    }

    /**
     * The environment of every bootstrap step: HERMES_HOME is ours, so pm's store resolves to
     * local/hermes/tools (pm/environments.py store_root) for this build and for every later
     * `hermes update`; HERMES_RUNTIME_DIR is removed so an inherited one cannot move it. UV_NO_CONFIG as
     * upstream. uv's own Python downloads and cache stay under HERMES_HOME instead of ~/.local/share/uv
     * and ~/.cache/uv (the cache is the one pm uses itself, pm/packages.py uv_cache_dir).
     */
    public static function bootstrapEnv()
    {
        $home = self::hermesHome();
        return ['HERMES_HOME' => $home, 'UV_NO_CONFIG' => '1', 'UV_PYTHON_INSTALL_DIR' => $home . '/bootstrap-python', 'UV_CACHE_DIR' => $home . '/cache/uv'];
    }

    /**
     * Join one optional Python package to a complete install's venv: `hermes pm install --extra NAME`, additive (pm unions
     * extras into the installed state), output to the install log. For a build from before WEB_SEARCH_EXTRA was built in
     * (ADR-0082); false when Hermes is not complete or the call failed.
     */
    public static function addExtra($name)
    {
        $bin = self::bin();
        if ($bin === '') {
            return false;
        }
        @mkdir(self::localDir(), 0700, true);
        ob_start();
        $code = self::run([$bin, 'pm', 'install', '--extra', $name], self::bootstrapEnv(), self::treeDir());
        @file_put_contents(self::localDir() . '/install.log', ob_get_clean(), FILE_APPEND);
        return $code === 0;
    }

    /** Run argv (with env and cwd), output to this process's stdout (the install log); returns the exit code. */
    private static function run(array $argv, array $env = [], $cwd = null, &$out = null)
    {
        $cmd = 'env -u HERMES_RUNTIME_DIR';
        foreach ($env as $k => $v) {
            $cmd .= ' ' . escapeshellarg("$k=$v");
        }
        $cmd = ($cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $cmd . ' ' . implode(' ', array_map('escapeshellarg', $argv));
        if (func_num_args() >= 4) {
            $lines = [];
            // The value is stdout; stderr goes to the install log, so a failed step says why.
            $err = (string) tempnam(sys_get_temp_dir(), 'pk-run-');
            exec($cmd . ($err !== '' ? ' 2>' . escapeshellarg($err) : ' 2>/dev/null'), $lines, $code);
            if ($err !== '') {
                echo (string) @file_get_contents($err);
                @unlink($err);
            }
            $out = trim(implode("\n", $lines));
            return $code;
        }
        passthru($cmd . ' 2>&1', $code);
        return $code;
    }

    /**
     * Stage the pinned uv into pm's store slot (<store>/uv-<version>-<target>/uv), as setup-hermes.sh
     * does: download (the mirror only on a network failure), check the sha256 before unpacking, flatten a
     * single wrapping directory, move into place. Returns '' or the problem.
     */
    private static function stageUv(array $uv, $entry, $tree)
    {
        $store = dirname($entry);
        @mkdir($store, 0700, true);
        $tmp = $store . '/.bootstrap-' . getmypid() . '-' . mt_rand();
        if (!@mkdir($tmp, 0700)) {
            return "cannot write $store";
        }
        try {
            $archive = $tmp . '/' . PathName::base((string) parse_url($uv['url'], PHP_URL_PATH));
            $code = self::run(['curl', '-fsSL', '-o', $archive, $uv['url']]);
            $mirror = json_decode((string) @file_get_contents($tree . '/pm/artifact-mirror.json'), true);
            if ($code !== 0 && in_array($code, self::MIRROR_RETRY_CODES, true) && !empty($mirror['origin']) && !empty($mirror['prefix'])) {
                $code = self::run(['curl', '-fsSL', '-o', $archive, $mirror['origin'] . '/' . $mirror['prefix'] . $uv['sha256']]);
            }
            if ($code !== 0) {
                return 'failed to download the pinned uv from ' . $uv['url'];
            }
            $got = (string) hash_file('sha256', $archive);
            if ($got !== $uv['sha256']) {
                return "sha256 mismatch for uv (got $got, pinned {$uv['sha256']})";
            }
            mkdir($tmp . '/tree', 0700);
            $unpack = substr($archive, -4) === '.zip' ? ['unzip', '-q', $archive, '-d', $tmp . '/tree'] : ['tar', '-xzf', $archive, '-C', $tmp . '/tree'];
            if (self::run($unpack) !== 0) {
                return 'cannot unpack the pinned uv';
            }
            $children = array_values(array_diff((array) scandir($tmp . '/tree'), ['.', '..']));
            $src = count($children) === 1 && is_dir($tmp . '/tree/' . $children[0]) ? $tmp . '/tree/' . $children[0] : $tmp . '/tree';
            if (file_exists($entry)) {
                exec('rm -rf ' . escapeshellarg($entry)); // a store slot without a working uv, as upstream replaces it
            }
            if (!@rename($src, $entry) || !is_executable($entry . '/uv')) {
                return 'the pinned uv archive has no uv';
            }
            return '';
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    /**
     * The background build: clone the release tag, then upstream's bootstrap (pinned uv, bootstrap
     * Python, `pm.cli install`), then the ready marker. The marker goes first, so a half-built tree never
     * looks complete. No step deletes the tree. Prints its progress (the install log); returns the exit code.
     */
    public static function build($tag, $optionalTools = false)
    {
        $tree = self::treeDir();
        echo "hermes $tag: build started " . gmdate('c') . "\n";
        @unlink(self::markerFile());
        $fail = function ($why) use ($tag) {
            echo "hermes $tag: failed: $why\n";
            return 1;
        };
        if (!preg_match(self::STABLE_TAG_RE, $tag)) {
            return $fail('not a release tag');
        }
        if (self::run(['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--quiet', '--depth', '1', '--branch', $tag, '--', self::repo(), $tree], ['GIT_TERMINAL_PROMPT' => '0']) !== 0) {
            return $fail('git clone');
        }
        $target = self::target();
        $lock = self::lockData($tree);
        $uv = self::uvPin($lock, $target);
        $py = self::pythonPin($lock);
        if ($uv === null || $py === '') {
            return $fail("pm/lock.json has no uv or python pin for $target");
        }
        $env = self::bootstrapEnv();
        @mkdir($env['HERMES_HOME'], 0700, true);
        $entry = $env['HERMES_HOME'] . "/tools/uv-{$uv['version']}-$target";
        if (!is_executable($entry . '/uv')) {
            $problem = self::stageUv($uv, $entry, $tree);
            if ($problem !== '') {
                return $fail($problem);
            }
        }
        // Both uv steps run in HERMES_HOME: `uv python find` looks for a .venv in its working directory and fails on one
        // the account cannot read (a build started through runuser from root's directory) after `uv python install` worked.
        if (self::run([$entry . '/uv', 'python', 'install', '--no-bin', '--no-registry', $py], $env, $env['HERMES_HOME']) !== 0) {
            return $fail("uv could not install the bootstrap Python $py");
        }
        if (self::run([$entry . '/uv', 'python', 'find', '--managed-python', $py], $env, $env['HERMES_HOME'], $boot) !== 0 || !is_executable($boot)) {
            return $fail("uv found no bootstrap Python $py");
        }
        $pm = [$boot, '-m', 'pm.cli', 'install'];
        foreach ($optionalTools ? [] : self::OPTIONAL_TOOLS as $name) {
            array_push($pm, '--without', $name);
        }
        if (self::run($pm, $env, $tree) !== 0) {
            return $fail('pm install');
        }
        // A second, separate call (ADR-0082): `--extra` alone installs no default closure, so it runs only after the full
        // install above has built the venv it unions into.
        if (self::run([$boot, '-m', 'pm.cli', 'install', '--extra', self::WEB_SEARCH_EXTRA], $env, $tree) !== 0) {
            return $fail('pm install --extra ' . self::WEB_SEARCH_EXTRA);
        }
        if (!is_executable($tree . '/.hermes/bin/hermes')) {
            return $fail('pm install wrote no .hermes/bin/hermes');
        }
        touch(self::markerFile());
        echo "hermes $tag: ready " . gmdate('c') . "\n";
        return 0;
    }

    /** The background command ensure() starts: build() in a fresh PHP process. */
    public static function buildProcess($tag, $optionalTools)
    {
        $code = 'require ' . var_export(__FILE__, true) . '; HermesInstall::$root = ' . var_export(self::root(), true)
            . '; HermesInstall::$repo = ' . var_export(self::repo(), true) . '; exit(HermesInstall::build(' . var_export($tag, true) . ', ' . ($optionalTools ? 'true' : 'false') . '));';
        return [PHP_BINARY, '-r', $code];
    }

    /** True while a build holds include/.hermes-build.lock. */
    public static function building()
    {
        $h = @fopen(self::includeDir() . '/.hermes-build.lock', 'r');
        if (!$h) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        fclose($h);
        return !$free;
    }

    /**
     * Start a build when there is no complete install; never touches a complete one (no git, no pm).
     * $unattended (post-update, from the daily cron) builds only where no tree exists at all, and not on
     * a root-capable host without setpriv (ADR-0025). $optionalTools: hermes.lazyInstalls (ADR-0021).
     * Returns a status line.
     */
    /** What a Hermes build puts in the account, measured on a PMSS account (2026-10-17: 60,572 files, 2.0 GB), with a margin. */
    const BUILD_FILES = 65000;
    const BUILD_BYTES = 2684354560; // 2.5 GiB

    /** Share of the disk and file quota an unattended build leaves free: nobody asked for it, so it must not fill the account. */
    const UNATTENDED_RESERVE = 0.10;

    /**
     * Why the account has no room for a Hermes build, or '' when it has (or when the quota is unknown: then the build
     * is tried). A build that runs out of files or space stops half way with EDQUOT and leaves a broken tree, so the
     * account's file quota and free space are checked first. $files: Quota::files(); $freeBytes: quota_free_bytes.
     * $unattended (a build nobody started, e.g. a preinstalled account's daily update) also keeps UNATTENDED_RESERVE of
     * both quotas free, so it never fills a customer's account; $limitBytes: quota_limit_bytes. $need: [files, bytes] the build
     * adds, default a fresh build; ensure() sizes the rebuild of a refused release from that tree (refusedNewerRelease).
     */
    public static function roomProblem($files, $freeBytes, $limitBytes = null, $unattended = false, ?array $need = null)
    {
        list($needFiles, $needBytes) = $need !== null ? $need : [self::BUILD_FILES, self::BUILD_BYTES];
        $reserve = $unattended ? self::UNATTENDED_RESERVE : 0.0;
        if (is_array($files) && $files['soft'] > 0 && $files['used'] + $needFiles > $files['soft'] * (1 - $reserve)) {
            return sprintf('Hermes needs about %s files and your account allows %s, with %s in use%s. The file quota grows with the disk quota; a larger plan has room.',
                number_format($needFiles), number_format($files['soft']), number_format($files['used']),
                $unattended ? '; an automatic build keeps 10% of it free, run `pikkumies` to build anyway' : '');
        }
        $room = $needBytes + ($unattended && $limitBytes > 0 ? $limitBytes * $reserve : 0);
        if ($freeBytes !== null && $freeBytes < $room) {
            return sprintf('Hermes needs about %.1f GB and your quota has %.1f GB free%s.', $needBytes / 1073741824, $freeBytes / 1073741824,
                $unattended ? '; an automatic build keeps 10% of the quota free, run `pikkumies` to build anyway' : '');
        }
        return '';
    }

    public static function ensure($unattended = false, $rootAvailable = false, $optionalTools = false)
    {
        // A complete checkout Pikkumies refuses to start because it is a release newer than the checked one is rebuilt at the
        // checked release, the old tree moved aside (ADR-0033 amendment 2026-11-03).
        $refused = self::isComplete() ? self::refusedNewerRelease() : '';
        if (self::isComplete() && $refused === '') {
            return 'installed (pikkumies/hermes; updates: `pikkumies run update`)';
        }
        if (getenv('PIKKUMIES_SKIP_HERMES') === '1') {
            return 'skipped (PIKKUMIES_SKIP_HERMES=1)';
        }
        if (self::building()) {
            return 'building in the background (log: ' . Config::shown(self::localDir() . '/install.log') . ')';
        }
        $tree = self::treeDir();
        $exists = file_exists($tree) || is_link($tree);
        // An incomplete tree (a build that failed or stopped) is moved aside and built again by the daily update once; when
        // an earlier aside is still there, a rebuild already ran or the user kept one, so it waits for `pikkumies install`
        // (ADR-0033 amendment 2026-11-06).
        $retried = $exists && $refused === '' && (glob($tree . '.broken-*') ?: []) !== [];
        if ($exists && $unattended && $refused === '' && $retried) {
            return 'not complete: pikkumies/hermes is left from a build that failed or stopped (see ' . Config::shown(self::localDir() . '/install.log') . '); run `pikkumies install` to move it aside and build again';
        }
        if (self::target() === '') {
            return 'not installed: Hermes has no build for this machine (' . php_uname('s') . ' ' . php_uname('m') . ')';
        }
        if ($unattended && CronTick::refuses($rootAvailable)) {
            return 'not installed: root is available and setpriv is missing, so no unattended build (ADR-0025); run `pikkumies install`';
        }
        $q = Quota::fields((string) getenv('HOME'));
        // A refused release is rebuilt over its HERMES_HOME, which stays and grows: on a QA account (2026-11-03) the v0.22.5
        // rebuild beside a 23,360-entry, 367 MB v0.22.6 tree added 35,547 files and about 715 MB (new tree plus environment),
        // so it needs twice the refused tree's entries and two and a half times its bytes.
        $quotaFiles = Quota::files((string) getenv('HOME'));
        $delete = false;
        if ($refused !== '') {
            list($treeFiles, $treeBytes) = self::treeSize($tree);
        }
        $room = self::roomProblem($quotaFiles, $q['quota_free_bytes'], $q['quota_limit_bytes'], $unattended,
            $refused !== '' ? [2 * $treeFiles, (int) (2.5 * $treeBytes)] : null);
        // No room to keep the refused tree aside: it is deleted instead when it holds nothing but the public release and
        // rebuildable build output, so the upstream tag is its copy (decision of the orchestrating agent, 2026-11-03, ADR-0033).
        if ($room !== '' && $refused !== '' && self::onlyReleaseAndBuildOutput($tree, $refused)
            && self::roomProblem($quotaFiles, $q['quota_free_bytes'], $q['quota_limit_bytes'], $unattended, [$treeFiles, (int) (1.5 * $treeBytes)]) === '') {
            $room = '';
            $delete = true;
        }
        if ($room !== '') {
            return 'not installed: ' . $room;
        }
        // From /, never the caller's directory: git refuses to list a remote's tags from a directory the account cannot read
        // (runuser from root's directory), which read as "network?" and started no build.
        list(, $tags) = self::git(['ls-remote', '--tags', '--', self::repo()], '/');
        $tag = self::latestTag($tags);
        if ($tag === '') {
            return 'not installed: could not read the Hermes releases from ' . self::repo() . ' (network?)';
        }
        $note = '';
        if ($exists) {
            $lock = self::lock(true);
            if (!$lock) {
                return 'not complete, and a Hermes process still runs from pikkumies/hermes; close it and run `pikkumies install` again';
            }
            // Never deleted, except a refused release that is byte-equal to its public tag apart from build output (above).
            $aside = $tree . ($refused !== '' ? '.refused-' . $refused . '-' : '.broken-') . gmdate('Ymd\THis\Z') . ($delete ? '.deleting' : '');
            $moved = !is_link($tree) && @rename($tree, $aside);
            $missing = $refused !== '' ? implode(', ', self::missingConfigKeys($aside)) : '';
            if ($moved && $delete) {
                $gone = self::removeTree($aside);
            }
            fclose($lock);
            if (!$moved) {
                return 'not complete, and could not move pikkumies/hermes aside; move or remove it, then run `pikkumies install`';
            }
            $note = $delete ? 'deleted the ' . $refused . ' tree Pikkumies cannot start (an unchanged copy of the public release; no room to keep it aside' . ($gone ? '' : ', and some of it could not be removed: ' . PathName::base($aside)) . '); '
                : 'moved the ' . ($refused !== '' ? $refused . ' tree Pikkumies cannot start' : 'incomplete tree') . ' to ' . PathName::base($aside) . ' (remove it when it is not needed); ';
            if ($refused !== '') {
                @unlink(self::markerFile());
                @file_put_contents(self::localDir() . '/hermes-heal.log', gmdate('c') . ' ' . ($delete ? 'deleted' : 'moved') . ' ' . $refused . ' (newer than ' . self::NEWEST_CHECKED . ', missing ' . $missing . ')'
                    . ($delete ? ', an unchanged copy of the public tag, no room to keep it aside' . ($gone ? '' : '; left: ' . PathName::base($aside)) : ' to ' . PathName::base($aside)) . ', building ' . $tag . "\n", FILE_APPEND);
            } elseif ($unattended) {
                @file_put_contents(self::localDir() . '/hermes-heal.log', gmdate('c') . ' moved an incomplete tree to ' . PathName::base($aside) . ', building ' . $tag . "\n", FILE_APPEND);
            }
        }
        @mkdir(self::includeDir(), 0700, true);
        @mkdir(self::localDir(), 0700, true);
        $lock = self::includeDir() . '/.hermes-build.lock';
        $log = self::localDir() . '/install.log';
        // No build step may gain privileges (upstream's libatomic step would try sudo), so setpriv where it exists.
        // A build nobody started (the daily update's) runs at idle I/O and lowest CPU priority, so it never competes
        // with the account's downloads or its neighbours; one the user starts keeps normal priority so it cannot stall.
        $build = self::buildProcess($tag, $optionalTools);
        $argv = array_merge(['flock', '-n', $lock], CronTick::setprivPresent() ? ['setpriv', '--no-new-privs'] : [], $unattended ? CronTick::lowPriority($build) : $build);
        // flock -n: if a build already runs, this one exits at once and leaves it alone.
        // The build starts in local/, never in the caller's directory: one the account cannot read (runuser from root's
        // directory) breaks `uv python find` and slows other steps.
        exec('cd ' . escapeshellarg(self::localDir()) . ' && nohup ' . implode(' ', array_map('escapeshellarg', $argv)) . ' > ' . escapeshellarg($log) . ' 2>&1 &');
        return $note . 'building ' . $tag . ' in the background (log: ' . Config::shown(self::localDir() . '/install.log') . ')';
    }

    /**
     * The Hermes lock, held for as long as the returned handle stays open: shared for every start
     * (sessions, cron-tick), exclusive for an update. null when it is not free right now.
     */
    public static function lock($exclusive)
    {
        @mkdir(self::localDir(), 0700, true);
        $h = @fopen(self::lockFile(), 'c');
        if (!$h) {
            return null;
        }
        if (!flock($h, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    public static function origin()
    {
        return self::git(['config', '--get', 'remote.origin.url'], self::treeDir())[1];
    }

    /** True for the official repository (or the test seam's repository). */
    public static function originOfficial($url)
    {
        return $url === self::repo() || preg_match(self::ORIGIN_RE, (string) $url) === 1;
    }

    /** The checked-out commit, '' when it cannot be read. */
    public static function head()
    {
        list($code, $sha) = self::git(['rev-parse', 'HEAD'], self::treeDir());
        return $code === 0 && preg_match('/^[0-9a-f]{40,64}$/', $sha) ? $sha : '';
    }

    /** For doctor: tag or branch, short commit, clean work tree, origin. No fetch. */
    public static function status()
    {
        $dir = self::treeDir();
        list($code, $rev) = self::git(['describe', '--tags', '--exact-match', 'HEAD'], $dir);
        if ($code !== 0 || $rev === '') {
            list($code, $rev) = self::git(['symbolic-ref', '--short', '-q', 'HEAD'], $dir);
            $rev = $code === 0 && $rev !== '' ? $rev : 'detached';
        }
        list($code, $porcelain) = self::git(['status', '--porcelain'], $dir);
        $origin = self::origin();
        return ['rev' => $rev, 'sha' => substr(self::head(), 0, 10), 'clean' => $code === 0 && $porcelain === '', 'origin' => $origin, 'official' => self::originOfficial($origin)];
    }

    /** The config keys HermesConfig writes whose text is missing from the installed source (CONFIG_FACTS, or the $facts given). */
    public static function missingConfigKeys($src = null, ?array $facts = null)
    {
        $src = $src !== null ? $src : self::sourceDir();
        $facts = $facts !== null ? $facts : self::configFacts();
        $missing = [];
        foreach ($facts as $key => $fact) {
            if (strpos((string) @file_get_contents($src . '/' . $fact[0]), $fact[1]) === false) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * Hermes launchers in ~/.local/bin that run this tree. Hermes' updater writes them unless
     * cli.expose_on_path is false; a bare `hermes` there would run without our config.
     */
    public static function pathLaunchers($home)
    {
        $found = [];
        foreach ((array) glob($home . '/.local/bin/hermes*') as $f) {
            $text = is_link($f) ? (string) @readlink($f) : (string) @file_get_contents($f, false, null, 0, 65536);
            if (strpos($text, self::treeDir()) !== false) {
                $found[] = $f;
            }
        }
        return $found;
    }

    /**
     * The checks a changed checkout must pass before Hermes starts on it: every config key we set still
     * exists, the managed settings layer is there and refuses a pinned key, and the delete guard still blocks `mkfs`
     * through Hermes' own hook runner. HermesConfig::env() must already be in the environment. $prefix: e.g. setpriv for an unattended caller.
     * Returns the problems, [] when it passed.
     */
    public static function verify(array $prefix = [])
    {
        $problems = [];
        foreach (self::missingConfigKeys() as $key) {
            $problems[] = "Hermes no longer has the config key $key";
        }
        foreach (self::missingConfigKeys(null, self::LAYER_FACTS) as $what) {
            $problems[] = "Hermes no longer has the managed settings layer ($what)";
        }
        $bin = self::bin();
        $payload = self::localDir() . '/hook-check-' . getmypid() . '.json';
        $json = json_encode(['args' => ['command' => 'mkfs.ext4 /tmp/pikkumies-hook-check']], JSON_UNESCAPED_SLASHES);
        if ($bin === '' || @file_put_contents($payload, $json) !== strlen($json)) {
            $problems[] = 'could not run the delete guard check';
            return $problems;
        }
        $argv = array_merge($prefix, [$bin, 'hooks', 'test', 'pre_tool_call', '--for-tool', 'terminal', '--payload-file', $payload]);
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1', $out, $code);
        @unlink($payload);
        $text = implode("\n", $out);
        if (strpos($text, '"action": "block"') === false || strpos($text, self::GUARD_BLOCK) === false) {
            $problems[] = 'the delete guard did not block mkfs in `hermes hooks test` (exit ' . $code . ')';
        }
        // The pins are applied: Hermes refuses to change one. A user config.yaml that does not parse is the user's
        // file to fix (doctor says where the copies are), not a reason to refuse Hermes (ADR-0062).
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', array_merge($prefix, [$bin, 'config', 'set', 'approvals.mode', 'manual']))) . ' 2>&1', $out, $code);
        $text = implode("\n", $out);
        if (strpos($text, self::MANAGED_REFUSAL) === false && strpos($text, self::PARSE_ERROR) === false) {
            $problems[] = 'Hermes did not refuse a change to a setting Pikkumies pins (`hermes config set approvals.mode`, exit ' . $code . '), so the pinned settings are not applied';
        }
        return $problems;
    }

    /**
     * The commit that last passed verify(), '' for none, and '' when the checks themselves changed since: a Pikkumies
     * update that relies on something new in Hermes (the managed scope, ADR-0062) checks an unchanged checkout once.
     */
    public static function verifiedCommit()
    {
        $data = json_decode((string) @file_get_contents(self::localDir() . '/hermes-verified.json'), true);
        return is_array($data) && isset($data['commit'], $data['checks']) && is_string($data['commit']) && $data['checks'] === self::checksId() ? $data['commit'] : '';
    }

    /** What verify() checks, as one id. */
    public static function checksId()
    {
        return substr(sha1(json_encode([self::configFacts(), self::LAYER_FACTS, self::GUARD_BLOCK, self::MANAGED_REFUSAL])), 0, 12);
    }

    public static function configFacts()
    {
        $facts = self::CONFIG_FACTS;
        if (Eternal::enabled()) {
            $facts['session.terminal_continue'] = ['hermes_cli/config_defaults.py', '"terminal_continue":'];
        }
        return $facts;
    }

    public static function markVerified($commit)
    {
        $file = self::localDir() . '/hermes-verified.json';
        $json = json_encode(['commit' => $commit, 'checks' => self::checksId(), 'at' => gmdate('c')]) . "\n";
        $tmp = $file . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * Before every start: when the checkout moved since it was last verified (an update, /update in a
     * session, a hand edit), verify() it; a pass is recorded, a fail is returned and nothing starts.
     */
    public static function checkBeforeStart(array $prefix = [])
    {
        $head = self::head();
        if ($head === '') {
            return ['cannot read the Hermes commit in pikkumies/hermes'];
        }
        if ($head === self::verifiedCommit()) {
            return [];
        }
        $problems = self::verify($prefix);
        if (!$problems) {
            self::markVerified($head);
        }
        return $problems;
    }
}
