<?php
/**
 * LocalHookCheck: whether one local hook unit may be read, and the hash of everything it can load (ADR-0054).
 *
 * A unit is refused when a directory or file name on its path from local/hooks has a character outside
 * [A-Za-z0-9._-] (its id is shown to the agent every turn, so a name must not carry a line of text), when any
 * directory on that path is a symlink or another account's, when anything inside the unit or inside a lib/ directory
 * on that path is a symlink, a file there is another account's, or when all that is more than HASH_MAX bytes (it is
 * hashed on every hook call, and a slow hash would outlast the Hermes entry).
 * The hash covers the unit's files and the lib/ directories on its path (local/hooks/lib, local/hooks/<category>/lib,
 * ...), which Tree never reads as units: shared code a unit requires from there is part of what was recorded.
 * Code a unit loads from outside local/hooks is not covered.
 */
class LocalHookCheck
{
    const HASH_MAX = 1048576; // bytes of files hashed for one unit, on every call
    const NAME = '/^[A-Za-z0-9._-]+$/';

    /** The id with every character outside the allowed set replaced by '?', safe to show on one line. */
    public static function cleanId($id)
    {
        return (string) preg_replace('#[^A-Za-z0-9._/-]#', '?', $id);
    }

    /** Why a unit's path below $hooksDir cannot be shown or read, '' when every name on it is plain. Checked first. */
    public static function badPath($hooksDir, $dir)
    {
        foreach (explode('/', ltrim(substr($dir, strlen($hooksDir)), '/')) as $seg) {
            if (!preg_match(self::NAME, $seg)) {
                return 'a directory name on its path has a character outside A-Z a-z 0-9 . _ -';
            }
        }
        return '';
    }

    /** [hash, ''] for a usable unit of HookUnits::all($hooksDir) whose path passed badPath(), or ['', why it is refused]. */
    public static function check($hooksDir, array $u, $uid)
    {
        for ($d = $u['dir']; strlen($d) > strlen($hooksDir); $d = dirname($d)) {
            if (is_link($d) || @fileowner($d) !== $uid) {
                return ['', 'a directory on its path is a symlink or not this account\'s'];
            }
        }
        $bytes = 0;
        $why = '';
        $h = 'unit:' . self::digest($u['dir'], '', $uid, $bytes, $why);
        // The lib/ directories from local/hooks down to the unit's own category: shared code it may require.
        $libs = [];
        for ($d = dirname($u['dir']); strlen($d) >= strlen($hooksDir); $d = dirname($d)) {
            $libs[] = $d;
        }
        foreach (array_reverse($libs) as $d) {
            if ($why === '' && (file_exists("$d/lib") || is_link("$d/lib"))) {
                $label = ltrim(substr("$d/lib", strlen($hooksDir)), '/');
                $h .= "\nlib:$label:" . (is_link("$d/lib") ? self::refuse($why, "$label is a symlink") : self::digest("$d/lib", '', $uid, $bytes, $why, $label));
            }
        }
        return $why === '' ? [sha1($h), ''] : ['', $why];
    }

    /** Path, size and sha1 of every file below $dir; sets $why on a symlink, another owner or too many bytes. */
    private static function digest($dir, $rel, $uid, &$bytes, &$why, $label = '')
    {
        $h = '';
        $entries = @scandir($rel === '' ? $dir : "$dir/$rel");
        foreach ($entries === false ? [] : $entries as $e) {
            $r = $rel === '' ? $e : "$rel/$e";
            $p = "$dir/$r";
            $shown = self::cleanId(ltrim("$label/$r", '/'));
            if ($e === '.' || $e === '..' || $why !== '') {
                continue;
            }
            if (is_link($p)) {
                return self::refuse($why, "$shown is a symlink");
            }
            if (@fileowner($p) !== $uid) {
                return self::refuse($why, "$shown is not this account's");
            }
            if (is_dir($p)) {
                $h .= substr_count($r, '/') < Tree::MAX_DEPTH ? self::digest($dir, $r, $uid, $bytes, $why, $label) : '';
            } elseif (is_file($p)) {
                $bytes += (int) @filesize($p);
                if ($bytes > self::HASH_MAX) {
                    return self::refuse($why, 'more than ' . self::HASH_MAX . ' bytes of files to check on every call; keep data outside local/hooks');
                }
                $h .= $r . "\0" . @sha1_file($p) . "\n";
            }
        }
        return $h;
    }

    private static function refuse(&$why, $text)
    {
        $why = $why !== '' ? $why : $text;
        return '';
    }
}
