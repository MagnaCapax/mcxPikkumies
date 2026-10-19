<?php
/**
 * WebGuardIntent: which navigation the CLI asked for. The guard cannot see the CDP commands agent-browser sends on its
 * own connection, so before `open <url>` the wrapper writes that URL, one line, to the intent file (account-owned,
 * 0600, in the unit's state directory: pikkumies/local/browse/intent). The first main-frame GET navigation to the same
 * URL within TTL seconds counts as the CLI's and consumes the file; anything else leaves it to expire. A page cannot
 * write the file, so a page cannot make its own navigation look like the CLI's.
 */
class WebGuardIntent
{
    const TTL = 30; // seconds between the wrapper writing the file and the browser starting the navigation
    const MAX = 8192; // bytes read from the file

    /** True when $file names $url as the CLI's navigation, now or within TTL; the file is removed when it matches. */
    public static function take($file, $url, $now, $uid = null)
    {
        if (!is_string($file) || $file === '') {
            return false;
        }
        clearstatcache(true, $file);
        $st = @lstat($file);
        $uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $uid || ($st['mode'] & 022)) {
            return false; // missing, a symlink or not a file, another account's, or writable by others: never trusted
        }
        if ($st['mtime'] < $now - self::TTL || $st['mtime'] > $now + 5) {
            @unlink($file);
            return false;
        }
        $want = trim((string) @file_get_contents($file, false, null, 0, self::MAX));
        $c = self::canonical($want);
        if ($c === null || $c !== self::canonical($url)) {
            return false;
        }
        @unlink($file);
        return true;
    }

    /** scheme://host[:port]/path?query with scheme and host lowercased, the default port and the fragment dropped. */
    public static function canonical($url)
    {
        $u = parse_url(trim((string) $url));
        if (!is_array($u) || !isset($u['scheme'], $u['host'])) {
            return null;
        }
        $scheme = strtolower($u['scheme']);
        $port = isset($u['port']) && !($scheme === 'http' && $u['port'] === 80) && !($scheme === 'https' && $u['port'] === 443)
            ? ':' . $u['port'] : '';
        return $scheme . '://' . rtrim(strtolower($u['host']), '.') . $port . (isset($u['path']) && $u['path'] !== '' ? $u['path'] : '/')
            . (isset($u['query']) ? '?' . $u['query'] : '');
    }
}
