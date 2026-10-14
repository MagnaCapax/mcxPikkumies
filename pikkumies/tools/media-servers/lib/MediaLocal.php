<?php
/**
 * MediaLocal: small readers of this machine's own state for the media units: port marker files,
 * one tag of an XML config, the address a port listens on (/proc/net/tcp), the end of a log file,
 * and the redaction every log line and record goes through before it reaches the model.
 */
class MediaLocal
{
    /** A file holding one port number (PMSS ~/.media-stack-port-<app>), or 0. */
    public static function portFile($path)
    {
        if (!is_file($path) || is_link($path)) {
            return 0; // PMSS reads the marker only when it is a plain file (install-media-stack.sh:1436-1437)
        }
        $v = trim((string) @file_get_contents($path));
        return ctype_digit($v) && (int) $v > 0 && (int) $v < 65536 ? (int) $v : 0;
    }

    /** The text of the first <$tag> in an XML file ('' when absent). */
    public static function xmlTag($path, $tag)
    {
        $x = is_file($path) ? (string) @file_get_contents($path, false, null, 0, 1048576) : '';
        $t = preg_quote($tag, '#');
        return preg_match("#<$t>([^<]*)</$t>#", $x, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)) : '';
    }

    /** "address:port" of the listener on $port as /proc/net shows it ('' when not visible). $tables: test seam. */
    public static function listen($port, ?array $tables = null)
    {
        $tables = $tables !== null ? $tables : [@file_get_contents('/proc/net/tcp'), @file_get_contents('/proc/net/tcp6')];
        $hex = sprintf('%04X', (int) $port);
        foreach ($tables as $t) {
            foreach (explode("\n", (string) $t) as $line) {
                $f = preg_split('/\s+/', trim($line));
                if (count($f) > 3 && $f[3] === '0A' && preg_match('/^([0-9A-F]+):' . $hex . '$/', $f[1], $m)) {
                    $bin = '';
                    foreach (str_split(pack('H*', $m[1]), 4) as $word) {
                        $bin .= strrev($word); // each 32-bit word is in host (little-endian) order
                    }
                    $ip = (string) @inet_ntop($bin);
                    return (strpos($ip, ':') !== false ? "[$ip]" : $ip) . ":$port";
                }
            }
        }
        return '';
    }

    /** The last $n lines of a file, reading at most 1 MiB from its end. */
    public static function tail($file, $n)
    {
        $size = (int) @filesize($file);
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            throw new CliError("cannot read the log $file", 'check the file exists and belongs to this user');
        }
        fseek($fh, max(0, $size - 1048576));
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        return self::lastLines($text, $n);
    }

    public static function lastLines($text, $n)
    {
        $lines = explode("\n", rtrim(str_replace("\r\n", "\n", (string) $text), "\n"));
        return $text === '' ? [] : array_slice($lines, -max(1, (int) $n));
    }

    /**
     * Log lines as the model may see them: credentials removed (the literal values in $secrets, Plex and
     * Jellyfin token forms that lib/Cli.php's URL rule misses), and viewer addresses replaced unless the
     * user asked for them. Kept to $budget bytes, oldest lines dropped first.
     */
    public static function redact(array $lines, array $secrets, $keepAddresses, $budget = 12000)
    {
        $secrets = array_values(array_filter($secrets, function ($s) {
            return is_string($s) && strlen($s) >= 4;
        }));
        $out = [];
        foreach ($lines as $l) {
            $l = $secrets ? str_replace($secrets, 'REDACTED', (string) $l) : (string) $l;
            $l = (string) preg_replace(['/(X-(?:Plex|Emby|MediaBrowser)-Token[=:"\s]+)[^&\s",;]+/i', '/(\bToken=\\\\?")[^"\\\\]*/i',
                '/([?&](?:api_?key|ApiKey)=)[^&\s"]+/i'], '$1REDACTED', $l);
            if (!$keepAddresses) {
                $l = self::maskAddresses($l); // a four-part version number too: the cost of never leaking a viewer's address
            }
            $out[] = Cli::cleanString($l);
        }
        $dropped = 0;
        while ($out && strlen(Cli::json($out)) > $budget) {
            array_shift($out);
            $dropped++;
        }
        return [$out, $dropped];
    }

    /**
     * $line with every IP address replaced by ADDRESS (a port after it is kept). Two passes over
     * candidate substrings, each validated on its own: runs of hex digits, dots and colons with two or
     * more colons (IPv6, bracketed or not, IPv4-mapped, with or without a trailing :port), then
     * dotted quads anywhere (IPv4 alone, with :port, in a URL, after user@). A run that is not a valid
     * address (a time 12:30:45, a version 10.10.1) is left as it is.
     */
    public static function maskAddresses($line)
    {
        $line = (string) preg_replace_callback('/(?:(?<![\w.:])|(?<=[A-Za-z_])(?=:))[0-9A-Fa-f.:]*:[0-9A-Fa-f.:]*:[0-9A-Fa-f.:]*(?!\w)/',
            function ($m) {
                return self::maskRun($m[0]);
            }, $line);
        return (string) preg_replace_callback('/(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?!\d|\.\d)/', function ($m) {
            return max(array_map('intval', explode('.', $m[0]))) <= 255 ? 'ADDRESS' : $m[0];
        }, $line);
    }

    /** One colon run: the address in it (one leading ':', one trailing '.' or ':', a trailing :port aside), or the run as it is. */
    private static function maskRun($run)
    {
        $pre = preg_match('/^:[^:]/', $run) ? ':' : '';
        $core = substr($run, strlen($pre));
        $post = preg_match('/[^:][.:]$/', $core) ? substr($core, -1) : '';
        $core = $post !== '' ? substr($core, 0, -1) : $core;
        if (filter_var($core, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $pre . 'ADDRESS' . $post;
        }
        if (preg_match('/^(.+):(\d{1,5})$/', $core, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $pre . 'ADDRESS:' . $m[2] . $post;
        }
        return $run;
    }
}
