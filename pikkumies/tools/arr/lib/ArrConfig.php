<?php
/**
 * ArrConfig: Port, UrlBase and ApiKey from a Servarr config.xml, read again on every call.
 * Parsed with regular expressions the way PMSS reads it (scripts/lib/mediaStackPorts.php:19), never
 * with an XML parser, so there is no entity or external-reference surface. A file is valid when it is
 * non-empty and contains </Config>, the installer's own test (etc/skel/install-media-stack.sh:642).
 */
class ArrConfig
{
    const MAX = 1048576; // PMSS reads at most 1 MiB of it (mediaStackPorts.php:48)
    /** How ArrClient's errors name config.xml and its settings; Bazarr and Autobrr pass their own. */
    const LABELS = ['base' => 'UrlBase', 'baseHint' => 'check <UrlBase> in config.xml', 'portHint' => 'check <Port> and <UrlBase> in',
        'key' => 'ApiKey', 'keyHint' => 'the app may run from another data folder than this config.xml; check which one it was started with'];

    /**
     * ['port' => int (0 = none), 'urlBase' => '' or '/a/b', 'key' => string, 'bytes' => int, 'valid' => bool],
     * or null when there is no regular file at $path.
     */
    public static function read($path)
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = (int) @filesize($path);
        $text = $bytes > 0 && $bytes <= self::MAX ? (string) @file_get_contents($path, false, null, 0, self::MAX) : '';
        $valid = $text !== '' && strpos($text, '</Config>') !== false;
        return [
            'port' => $valid ? self::port(self::tag($text, 'Port')) : 0,
            'urlBase' => $valid ? self::urlBase(self::tag($text, 'UrlBase')) : '',
            'key' => $valid ? self::key(self::tag($text, 'ApiKey')) : '',
            'bytes' => $bytes,
            'valid' => $valid,
        ];
    }

    /** The text of the first <Tag>...</Tag>, trimmed; '' when absent. */
    public static function tag($text, $tag)
    {
        return preg_match('#<' . $tag . '>[\t\r\n ]*([^<]*?)[\t\r\n ]*</' . $tag . '>#i', $text, $m) ? $m[1] : '';
    }

    public static function port($s)
    {
        return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 65535 ? (int) $s : 0;
    }

    /** '' or '/a/b': one leading slash, no trailing slash, only URL path characters (else ''). */
    public static function urlBase($s)
    {
        $s = trim($s, '/');
        return $s !== '' && preg_match('#^[A-Za-z0-9._~/-]+$#', $s) && strpos($s, '..') === false ? '/' . $s : '';
    }

    /**
     * The apps generate 32 hex characters (PMSS accepts only that, install-media-stack.sh:1595-1598); 20 to 64
     * letters and digits are taken here so a hand-set key works too. Anything else (markup, spaces) is ignored.
     */
    public static function key($s)
    {
        return preg_match('/^[A-Za-z0-9]{20,64}$/', $s) ? $s : '';
    }
}
