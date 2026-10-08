<?php
/**
 * HermesInstall: Hermes Agent from a pinned release tarball, checked by sha256 (ADR-0009).
 *
 * Each release builds into include/hermes-<tag>/ (src/ + venv/) and include/hermes is a symlink
 * to the current venv, switched atomically when a build completes. There is no .git in the
 * source tree, so `hermes update` refuses to run and the pin holds. Builds run in the
 * background under a lock; the log is local/install.log.
 */
class HermesInstall
{
    const SOURCE = 'https://github.com/NousResearch/hermes-agent/archive/refs/tags/%s.tar.gz';
    const TAG_RE = '/^v?[0-9][0-9A-Za-z._-]*$/';
    const PY_MIN = '3.11';
    const PY_MAX = '3.14'; // exclusive (pyproject requires-python >=3.11,<3.14)
    // When the system python3 is older (Debian 10/11 ship 3.7/3.9): a pinned standalone CPython, checked
    // by sha256 like the Hermes tarball (ADR-0014). Built for glibc 2.17+, x86_64 only.
    const PY_STANDALONE = 'python-3.13.16';
    const PY_STANDALONE_URL = 'https://github.com/astral-sh/python-build-standalone/releases/download/20261003/cpython-3.13.16%2B20261003-x86_64-unknown-linux-gnu-install_only.tar.gz';
    const PY_STANDALONE_SHA256 = '0a0272910b10417c659a9312fb3f2d7a6d774da7bd510999be7a3ba83273dc1f';

    public static function includeDir()
    {
        return Config::root() . '/include';
    }

    public static function buildDir($tag)
    {
        return self::includeDir() . '/hermes-' . $tag;
    }

    public static function isComplete($tag)
    {
        return is_file(self::buildDir($tag) . '/.complete');
    }

    /** Tag the include/hermes symlink points at, '' for none, 'legacy' for an old plain venv. */
    public static function currentTag()
    {
        $link = self::includeDir() . '/hermes';
        if (is_link($link)) {
            return preg_match('#^hermes-(.+)/venv$#', (string) readlink($link), $m) ? $m[1] : '';
        }
        return is_dir($link) ? 'legacy' : '';
    }

    /**
     * [python command, message]: the system python3 when it is 3.11 to 3.13, else the pinned standalone
     * build on x86_64, else '' (Hermes cannot be installed here).
     */
    public static function python($machine = null)
    {
        $out = trim((string) @shell_exec('python3 --version 2>&1'));
        $ver = preg_match('/^Python (\d+\.\d+)/', $out, $m) ? $m[1] : 'none';
        if (version_compare($ver, self::PY_MIN, '>=') && version_compare($ver, self::PY_MAX, '<')) {
            return ['python3', 'Python ' . $ver];
        }
        if (($machine === null ? php_uname('m') : $machine) === 'x86_64') {
            return [self::includeDir() . '/' . self::PY_STANDALONE . '/bin/python3', 'standalone ' . self::PY_STANDALONE . ' (system python3: ' . $ver . ')'];
        }
        return ['', 'Hermes needs Python ' . self::PY_MIN . ' to 3.13; this machine has ' . $ver];
    }

    /**
     * The background build: download, verify the checksum, unpack (without the top-level tests/,
     * website/ and apps/ the runtime does not use; nested directories of those names are kept),
     * venv + editable install, mark complete, switch the symlink.
     */
    public static function buildCommand($tag, $sha256, $python = 'python3')
    {
        if (!preg_match(self::TAG_RE, $tag) || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return '';
        }
        $inc = self::includeDir();
        $b = self::buildDir($tag);
        $q = 'escapeshellarg';
        $steps = [
            'set -e',
            'echo "hermes ' . $tag . ': build started $(date -u +%FT%TZ)"',
        ];
        if ($python !== 'python3') {
            // Standalone Python: download, verify, unpack aside, then move into place (kept across Hermes builds).
            $py = $inc . '/' . self::PY_STANDALONE;
            $steps[] = 'if [ ! -x ' . $q($python) . ' ]; then rm -rf ' . $q($py . '.tmp') . ' && mkdir -p ' . $q($py . '.tmp')
                . ' && curl -fsSL --retry 2 -o ' . $q($py . '.tgz') . ' ' . $q(self::PY_STANDALONE_URL)
                . ' && printf ' . $q('%s  %s\n') . ' ' . $q(self::PY_STANDALONE_SHA256) . ' ' . $q($py . '.tgz') . ' > ' . $q($py . '.sha256')
                . ' && sha256sum -c ' . $q($py . '.sha256')
                . ' && tar -xzf ' . $q($py . '.tgz') . ' -C ' . $q($py . '.tmp') . ' --strip-components=1'
                . ' && rm -f ' . $q($py . '.tgz') . ' ' . $q($py . '.sha256') . ' && rm -rf ' . $q($py) . ' && mv ' . $q($py . '.tmp') . ' ' . $q($py) . '; fi';
        }
        $steps = array_merge($steps, [
            'rm -rf ' . $q($b),
            'mkdir -p ' . $q($b . '/src'),
            'curl -fsSL --retry 2 -o ' . $q($b . '/src.tgz') . ' ' . $q(sprintf(self::SOURCE, $tag)),
            'printf ' . $q('%s  %s\n') . ' ' . $q($sha256) . ' ' . $q($b . '/src.tgz') . ' > ' . $q($b . '/src.sha256'),
            'sha256sum -c ' . $q($b . '/src.sha256'),
            'tar -xzf ' . $q($b . '/src.tgz') . ' -C ' . $q($b . '/src') . " --strip-components=1 --anchored --no-wildcards-match-slash --exclude='*/tests' --exclude='*/website' --exclude='*/apps'",
            'rm -f ' . $q($b . '/src.tgz') . ' ' . $q($b . '/src.sha256'),
            $q($python) . ' -m venv ' . $q($b . '/venv'),
            $q($b . '/venv/bin/pip') . ' install --quiet -e ' . $q($b . '/src'),
            'touch ' . $q($b . '/.complete'),
            // An install made before ADR-0009 has a plain venv here: keep it aside, then switch.
            'if [ -d ' . $q($inc . '/hermes') . ' ] && [ ! -L ' . $q($inc . '/hermes') . ' ]; then mv ' . $q($inc . '/hermes') . ' ' . $q($inc . '/hermes-legacy') . '; fi',
            'ln -sfn ' . $q('hermes-' . $tag . '/venv') . ' ' . $q($inc . '/hermes.new'),
            'mv -T ' . $q($inc . '/hermes.new') . ' ' . $q($inc . '/hermes'),
            'echo "hermes ' . $tag . ': ready $(date -u +%FT%TZ)"',
        ]);
        return implode("\n", $steps);
    }

    /** Start a build of the pinned release unless it is current; returns a status line. */
    public static function ensure(array $config)
    {
        $tag = (string) $config['hermes']['version'];
        $sha = isset($config['hermes']['sha256']) ? (string) $config['hermes']['sha256'] : '';
        if (self::currentTag() === $tag && self::isComplete($tag)) {
            return 'installed ' . $tag;
        }
        if (getenv('PIKKUMIES_SKIP_HERMES') === '1') {
            return 'skipped (PIKKUMIES_SKIP_HERMES=1)';
        }
        list($python, $pyMsg) = self::python();
        if ($python === '') {
            return 'not installed: ' . $pyMsg;
        }
        $cmd = self::buildCommand($tag, $sha, $python);
        if ($cmd === '') {
            return 'not installed: hermes.version must be a release tag and hermes.sha256 its tarball checksum';
        }
        @mkdir(self::includeDir(), 0700, true);
        $lock = self::includeDir() . '/.hermes-build.lock';
        $log = Config::localDir() . '/install.log';
        // flock -n: if a build already runs, this one exits at once and leaves it alone.
        exec('nohup flock -n ' . escapeshellarg($lock) . ' sh -c ' . escapeshellarg($cmd) . ' > ' . escapeshellarg($log) . ' 2>&1 &');
        return 'building ' . $tag . ' in the background (log: local/install.log)';
    }

    /**
     * True when one of this user's processes runs from $dir: a session started before a switch
     * keeps using its build (the agent can run update from inside a session), so it must stay.
     */
    public static function inUse($dir)
    {
        $dir = rtrim($dir, '/') . '/';
        foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $p) {
            $exe = (string) @readlink($p . '/exe');
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($p . '/cmdline'));
            if (strpos($exe, $dir) === 0 || strpos($cmd, $dir) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Remove our own builds that are neither current nor the pinned one (run by update). */
    public static function cleanup(array $config)
    {
        $keep = [self::currentTag(), (string) $config['hermes']['version']];
        $removed = [];
        foreach ((array) glob(self::includeDir() . '/hermes-*', GLOB_ONLYDIR) as $dir) {
            $tag = substr(basename($dir), strlen('hermes-'));
            if (in_array($tag, $keep, true) || is_link($dir) || self::inUse($dir)) {
                continue;
            }
            exec('rm -rf ' . escapeshellarg($dir), $o, $code);
            if ($code === 0) {
                $removed[] = basename($dir);
            }
        }
        return $removed;
    }
}
