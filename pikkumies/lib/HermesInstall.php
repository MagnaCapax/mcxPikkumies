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

    /** [ok, message]: Hermes needs Python 3.11 to 3.13. */
    public static function pythonCheck()
    {
        $out = trim((string) @shell_exec('python3 --version 2>&1'));
        if (!preg_match('/^Python (\d+\.\d+)/', $out, $m)) {
            return [false, 'python3 not found; Hermes needs Python ' . self::PY_MIN . ' to 3.13'];
        }
        $ok = version_compare($m[1], self::PY_MIN, '>=') && version_compare($m[1], self::PY_MAX, '<');
        return [$ok, $ok ? 'Python ' . $m[1] : 'Hermes needs Python ' . self::PY_MIN . ' to 3.13; this machine has ' . $m[1]];
    }

    /**
     * The background build: download, verify the checksum, unpack (without the top-level tests/,
     * website/ and apps/ the runtime does not use; nested directories of those names are kept),
     * venv + editable install, mark complete, switch the symlink.
     */
    public static function buildCommand($tag, $sha256)
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
            'rm -rf ' . $q($b),
            'mkdir -p ' . $q($b . '/src'),
            'curl -fsSL --retry 2 -o ' . $q($b . '/src.tgz') . ' ' . $q(sprintf(self::SOURCE, $tag)),
            'printf ' . $q('%s  %s\n') . ' ' . $q($sha256) . ' ' . $q($b . '/src.tgz') . ' > ' . $q($b . '/src.sha256'),
            'sha256sum -c ' . $q($b . '/src.sha256'),
            'tar -xzf ' . $q($b . '/src.tgz') . ' -C ' . $q($b . '/src') . " --strip-components=1 --anchored --no-wildcards-match-slash --exclude='*/tests' --exclude='*/website' --exclude='*/apps'",
            'rm -f ' . $q($b . '/src.tgz') . ' ' . $q($b . '/src.sha256'),
            'python3 -m venv ' . $q($b . '/venv'),
            $q($b . '/venv/bin/pip') . ' install --quiet -e ' . $q($b . '/src'),
            'touch ' . $q($b . '/.complete'),
            // An install made before ADR-0009 has a plain venv here: keep it aside, then switch.
            'if [ -d ' . $q($inc . '/hermes') . ' ] && [ ! -L ' . $q($inc . '/hermes') . ' ]; then mv ' . $q($inc . '/hermes') . ' ' . $q($inc . '/hermes-legacy') . '; fi',
            'ln -sfn ' . $q('hermes-' . $tag . '/venv') . ' ' . $q($inc . '/hermes.new'),
            'mv -T ' . $q($inc . '/hermes.new') . ' ' . $q($inc . '/hermes'),
            'echo "hermes ' . $tag . ': ready $(date -u +%FT%TZ)"',
        ];
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
        list($pyOk, $pyMsg) = self::pythonCheck();
        if (!$pyOk) {
            return 'not installed: ' . $pyMsg;
        }
        $cmd = self::buildCommand($tag, $sha);
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

    /** Remove our own builds that are neither current nor the pinned one (run by update). */
    public static function cleanup(array $config)
    {
        $keep = [self::currentTag(), (string) $config['hermes']['version']];
        $removed = [];
        foreach ((array) glob(self::includeDir() . '/hermes-*', GLOB_ONLYDIR) as $dir) {
            $tag = substr(basename($dir), strlen('hermes-'));
            if (in_array($tag, $keep, true) || is_link($dir)) {
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
