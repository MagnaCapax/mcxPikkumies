<?php
/**
 * TreeLitter: what belongs in the Pikkumies tree (ADR-0093). The tree is the clone (~/.mcxPikkumies; ~/pikkumies links to
 * its pikkumies/ directory). Everything in it is Pikkumies itself (tracked by git) or what Pikkumies keeps out of git
 * (.gitignore: local/, memory/, the Hermes checkout, the generated indexes). The agent creates files and folders only in
 * local/ (state, and what Pikkumies fetches for its tools) and memory/ (lessons); everything else is written by the
 * installer and the updater, which run as programs, not as the agent's tool calls.
 * - resolve() and guarded(): where a write lands and whether it may create something there (litter-check hook, layer 1).
 * - stray(): what git sees as neither tracked nor ignored (scratch-dir hook and `pikkumies doctor`, layer 3). Never deletes.
 */
class TreeLitter
{
    const ALLOWED = ['pikkumies/local', 'pikkumies/memory'];
    const SHOWN = 5;

    /** The real path of the clone that holds the runtime directory $root (pikkumies/), or ''. */
    public static function repo($root)
    {
        $repo = realpath(dirname(rtrim((string) $root, '/')));
        return $repo === false || $repo === '/' ? '' : $repo;
    }

    /**
     * [real path, exists] for $path written from $base, as the kernel would reach it: every existing part through
     * realpath, so a symlink anywhere on the way is followed; a dangling symlink is followed to where it points (a write
     * creates that); the first missing part and the rest are appended with . and .. folded. null when it cannot be read
     * (a relative path with no absolute base, or a symlink loop).
     */
    public static function resolve($path, $base, $depth = 0)
    {
        if (!is_string($path) || $path === '' || $depth > 8) {
            return null;
        }
        if ($path[0] !== '/') {
            if (!is_string($base) || $base === '' || $base[0] !== '/') {
                return null;
            }
            $path = rtrim($base, '/') . '/' . $path;
        }
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        $at = '';
        foreach ($parts as $i => $p) {
            if ($p === '.') {
                continue;
            }
            if ($p === '..') {
                $at = dirname($at === '' ? '/' : $at);
                $at = $at === '/' ? '' : $at;
                continue;
            }
            $next = $at . '/' . $p;
            $real = realpath($next);
            if ($real === false && is_link($next)) {
                $rest = implode('/', array_slice($parts, $i + 1));
                return self::resolve((string) readlink($next) . ($rest !== '' ? '/' . $rest : ''), $at === '' ? '/' : $at, $depth + 1);
            }
            $at = $real !== false ? rtrim($real, '/') : $next;
        }
        $at = $at === '' ? '/' : $at;
        return [$at, file_exists($at) || is_link($at)];
    }

    /** True when the real path $real is inside the clone $repo but outside the places the agent may create entries in. */
    public static function guarded($real, $repo)
    {
        if ($repo === '' || !self::inside($real, $repo)) {
            return false;
        }
        foreach (self::ALLOWED as $allowed) {
            $dir = realpath("$repo/$allowed");
            if (self::inside($real, "$repo/$allowed") || ($dir !== false && self::inside($real, $dir))) {
                return false;
            }
        }
        return true;
    }

    public static function inside($real, $dir)
    {
        return $real === $dir || strpos($real . '/', rtrim($dir, '/') . '/') === 0;
    }

    /**
     * Entries in the clone that git sees as neither tracked nor ignored, relative to the clone (an untracked folder is one
     * entry, ending in /); null when it cannot tell (no git, not a checkout, git failed or took longer than $seconds).
     * ls-files, not status: the agent can write .git/config, and git status would run its core.fsmonitor command and the
     * clean filter of every stat-dirty tracked file. ls-files --others runs neither; fsmonitor is off and no optional lock
     * is taken besides, as on every git call on the tree (ADR-0033, lib/Update.php).
     */
    public static function stray($root, $seconds = 2)
    {
        $repo = self::repo($root);
        if ($repo === '' || !file_exists("$repo/.git") || ToolProc::which('git') === null) {
            return null;
        }
        $argv = array_merge(ToolProc::which('timeout') !== null ? ['timeout', (string) (int) $seconds] : [],
            ['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', $repo, 'ls-files', '-z', '--others', '--exclude-standard', '--directory']);
        try {
            $r = ToolProc::run($argv, ['LC_ALL' => 'C']);
        } catch (CliError $e) {
            return null;
        }
        if ($r['code'] !== 0) {
            return null;
        }
        return array_values(array_filter(explode("\0", $r['out']), 'strlen'));
    }

    /** One line naming at most SHOWN stray entries, or '' when there are none. */
    public static function line(array $stray, $repo)
    {
        if (!$stray) {
            return '';
        }
        $more = count($stray) - self::SHOWN;
        return count($stray) . ' stray ' . (count($stray) === 1 ? 'entry' : 'entries') . ' in the Pikkumies tree ' . Config::shown($repo)
            . ' (not part of Pikkumies, not its state): ' . implode(', ', array_slice($stray, 0, self::SHOWN)) . ($more > 0 ? " and $more more" : '');
    }

    /** The doctor's line (layer 3 on demand). */
    public static function doctorLine($root)
    {
        $stray = self::stray($root);
        if ($stray === null) {
            return 'tidy:     not checked (the Pikkumies tree is not a git checkout, or git did not answer)';
        }
        return 'tidy:     ' . ($stray ? self::line($stray, self::repo($root)) . '; move them out (never delete what you did not make)'
            : 'no stray files in the Pikkumies tree');
    }
}
