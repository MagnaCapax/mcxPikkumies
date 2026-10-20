<?php
/**
 * UpstreamCache: the user's own cache of upstream GitHub answers (ADR-0092), in local/upstream-cache/ (directory 0700,
 * files 0600, written through a temporary file and a rename). Per user, never shared: a cache in /tmp would show other
 * accounts which projects and errors this one looked up, and could be poisoned. The cache is the only thing that saves
 * the shared unauthenticated allowance: a conditional request still counts (measured 2026-10-20). An expired entry is
 * kept and served, marked stale, when GitHub or the allowance says no. At most MAX bytes; the oldest entries go first.
 */
require_once __DIR__ . '/PathName.php';

class UpstreamCache
{
    const MAX = 20971520;

    /** The entry for $key: ['data' => ..., 'fetched_at' => int, 'fresh' => bool], or null. */
    public static function get($dir, $key, $now)
    {
        $f = self::file($dir, $key);
        $e = is_file($f) && !is_link($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($e) || !isset($e['fetched_at'], $e['ttl']) || !array_key_exists('data', $e)) {
            return null;
        }
        return ['data' => $e['data'], 'fetched_at' => (int) $e['fetched_at'], 'fresh' => (int) $e['fetched_at'] + (int) $e['ttl'] > $now];
    }

    public static function put($dir, $key, $data, $ttl, $now)
    {
        self::writeFile(self::file($dir, $key), (string) json_encode(['fetched_at' => $now, 'ttl' => (int) $ttl, 'data' => $data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        self::trim($dir);
    }

    /** One cache file per request: sha1 of method and URL. */
    public static function file($dir, $key)
    {
        return rtrim($dir, '/') . '/' . sha1((string) $key) . '.json';
    }

    /** Write $text to $file 0600 through a temporary file and a rename, its directory created 0700; false on failure. */
    public static function writeFile($file, $text)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        @chmod($dir, 0700);
        $tmp = $file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $text) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Remove the oldest cache entries until the directory holds at most $max bytes (ratelimit.json is kept). */
    public static function trim($dir, $max = self::MAX)
    {
        $files = [];
        $total = 0;
        foreach ((array) glob(rtrim($dir, '/') . '/*.json') as $f) {
            if (PathName::base($f) === 'ratelimit.json' || is_link($f)) {
                continue;
            }
            $files[$f] = (int) @filemtime($f);
            $total += (int) @filesize($f);
        }
        asort($files);
        foreach ($files as $f => $mtime) {
            if ($total <= $max) {
                break;
            }
            $total -= (int) @filesize($f);
            @unlink($f);
        }
    }
}
