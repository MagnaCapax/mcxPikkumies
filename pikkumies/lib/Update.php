<?php
/**
 * Update: fast-forward the checkout to origin/main. Never stashes, never merges, never resets:
 * if tracked files were edited, it stops and lists them. local/ and the lesson files in memory/ are
 * gitignored, so user state is never touched.
 */
class Update
{
    public static function repoRoot()
    {
        return dirname(Config::root());
    }

    public static function git(array $args, &$code = null)
    {
        $cmd = 'git -C ' . escapeshellarg(self::repoRoot());
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $out = [];
        exec($cmd . ' 2>&1', $out, $code);
        return $out;
    }

    /** Returns [ok, message]. */
    public static function run()
    {
        $dirty = self::git(['status', '--porcelain', '--untracked-files=no'], $code);
        if ($code !== 0) {
            return [false, "not a git checkout:\n" . implode("\n", $dirty)];
        }
        if ($dirty) {
            return [false, "tracked files were changed locally; update stopped so nothing is overwritten:\n" . implode("\n", $dirty) . "\nMove your changes to local/ (or a local skill) and run update again."];
        }
        $before = trim(implode('', self::git(['rev-parse', '--short', 'HEAD'])));
        $fetch = self::git(['fetch', '--depth', '1', 'origin', 'main'], $code);
        if ($code !== 0) {
            return [false, "fetch failed:\n" . implode("\n", $fetch)];
        }
        $merge = self::git(['merge', '--ff-only', 'FETCH_HEAD'], $code);
        if ($code !== 0) {
            return [false, "cannot fast-forward (history diverged); nothing changed:\n" . implode("\n", $merge)];
        }
        $after = trim(implode('', self::git(['rev-parse', '--short', 'HEAD'])));
        return [true, $before === $after ? "already up to date ($after)" : "updated $before -> $after"];
    }
}
