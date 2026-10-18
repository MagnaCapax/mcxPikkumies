<?php
/**
 * Tree: the one walker for the unit trees, tools/ and hooks/, at any depth (ADR-0055).
 *
 * Depth-1 directories are always categories (tools/<category>/, hooks/<category>/). Below that a directory
 * is a unit when it holds the marker file (skill.md, hook.md); with $leafIsUnit (the hooks rule, ADR-0030)
 * also when it holds <name>.php or has no subdirectory, so a hook unit with a lost hook.md stays a broken
 * unit instead of disappearing. Any other directory is a category, named by its path (email/imap). The walk
 * never descends into a unit and skips dot-directories and lib/. A symlinked directory, a directory that cannot
 * be read, or a category at MAX_DEPTH that still has subdirectories comes back as one unit with a 'problem',
 * never silently dropped and never walked further.
 */
class Tree
{
    const MAX_DEPTH = 8;
    const UNREADABLE = 'the directory cannot be read (check its permissions); nothing below it is loaded (ADR-0055)';

    /**
     * ['units' => [['category', 'name', 'dir', 'problem'], ...] by (category, name), 'categories' => [path => dir]].
     * The root itself is not a category.
     */
    public static function scan($root, $marker, $leafIsUnit = false)
    {
        $out = ['units' => [], 'categories' => []];
        $root = rtrim($root, '/');
        if (is_dir($root) && self::subdirs($root) === null) {
            $out['units'][] = ['category' => '', 'name' => basename($root), 'dir' => $root, 'problem' => self::UNREADABLE];
            return $out;
        }
        self::walk($root, '', 1, $marker, $leafIsUnit, $out);
        usort($out['units'], ['Tree', 'compare']);
        return $out;
    }

    /** The units of scan(). */
    public static function units($root, $marker, $leafIsUnit = false)
    {
        return self::scan($root, $marker, $leafIsUnit)['units'];
    }

    /**
     * Category, then name, by strcmp. A category is compared with a trailing slash, so all units of one category
     * stay together (email, email/imap, never email/a, email/imap/b, email/z) and categories keep the order the old
     * path glob gave them (media-servers before media). Within a category, names are in plain strcmp order: pk
     * comes before pk-x, where the old glob of pk/skill.md put it after.
     */
    public static function compare(array $a, array $b)
    {
        return strcmp($a['category'] . '/', $b['category'] . '/') ?: strcmp($a['name'], $b['name']);
    }

    /** True when $category is $path or lies below it, by whole path segments (media is not media-servers). */
    public static function within($category, $path)
    {
        return $category === $path || strpos($category, $path . '/') === 0;
    }

    /** [category => units] in first-seen order: an index prints one heading per category path. */
    public static function byCategory(array $units)
    {
        $out = [];
        foreach ($units as $u) {
            $out[(string) $u['category']][] = $u;
        }
        return $out;
    }

    /** $dir's subdirectory names, sorted, without dot-directories and lib/; null when $dir cannot be read. */
    public static function subdirs($dir)
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return null;
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry[0] !== '.' && $entry !== 'lib' && is_dir("$dir/$entry")) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** $depth is the depth of $dir's children (1 = directly under the root). */
    private static function walk($dir, $rel, $depth, $marker, $leafIsUnit, array &$out)
    {
        foreach ((array) self::subdirs($dir) as $entry) {
            $path = "$dir/$entry";
            $unit = ['category' => $rel, 'name' => $entry, 'dir' => $path, 'problem' => ''];
            if (is_link($path)) {
                $unit['problem'] = 'a symlinked directory is not followed; put the unit itself here (ADR-0055)';
            } elseif (($subdirs = self::subdirs($path)) === null) {
                $unit['problem'] = self::UNREADABLE;
            } elseif ($depth < 2 || !self::isUnit($path, $marker, $leafIsUnit, $subdirs)) {
                $childRel = $rel === '' ? $entry : "$rel/$entry";
                if ($depth < self::MAX_DEPTH || !$subdirs) {
                    $out['categories'][$childRel] = $path;
                    self::walk($path, $childRel, $depth + 1, $marker, $leafIsUnit, $out);
                    continue;
                }
                $unit['problem'] = 'deeper than ' . self::MAX_DEPTH . ' levels; units below here are not read (ADR-0055)';
            }
            $out['units'][] = $unit;
        }
    }

    private static function isUnit($dir, $marker, $leafIsUnit, array $subdirs)
    {
        return is_file("$dir/$marker")
            || ($leafIsUnit && (is_file("$dir/" . basename($dir) . '.php') || !$subdirs));
    }
}
