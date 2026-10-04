<?php
/**
 * Update: fast-forward the checkout to origin/main. Never stashes, never merges, never resets:
 * if tracked files were edited, it stops and lists them. local/ and the lesson files in memory/ are
 * gitignored, so user state is never touched.
 */
class Update
{
    /** @var string|null test seam: a checkout other than this one */
    public static $root = null;

    public static function repoRoot()
    {
        return self::$root !== null ? self::$root : dirname(Config::root());
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
        // No --depth: on a shallow clone a depth-1 fetch grafts the new tip as a parentless root,
        // so the fast-forward sees unrelated histories. A plain fetch stops at the existing shallow
        // boundary and downloads only the new commits.
        $fetch = self::git(['fetch', 'origin', 'main'], $code);
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
