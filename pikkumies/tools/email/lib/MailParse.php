<?php
/**
 * MailParse: reads one RFC 5322 message for the email units (ADR-0053): headers unfolded and decoded (RFC 2047),
 * the text body (text/plain first, else HTML stripped to text), and the attachments with names decoded from RFC 2231
 * or RFC 2047. Everything it returns came from a stranger: units print the body as `untrusted_text`, a JSON value the
 * agent treats as data (DOCTRINE.md). Depth and part counts are capped so a crafted message cannot loop or explode.
 */
class MailParse
{
    const DEPTH = 8;
    const PARTS = 200;
    /** A plain address: no display name, no <>, no spaces, quotes or control bytes (used for every envelope address). */
    const ADDRESS = '/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/';

    /** [headers (lowercase name => [raw values]), body]. */
    public static function split($raw)
    {
        $raw = (string) $raw;
        $p = strpos($raw, "\r\n\r\n");
        $q = strpos($raw, "\n\n");
        if ($p !== false && ($q === false || $p < $q)) {
            list($head, $body) = [substr($raw, 0, $p), (string) substr($raw, $p + 4)];
        } elseif ($q !== false) {
            list($head, $body) = [substr($raw, 0, $q), (string) substr($raw, $q + 2)];
        } else {
            list($head, $body) = [$raw, ''];
        }
        $headers = [];
        foreach (preg_split('/\r?\n(?![ \t])/', str_replace("\r\n", "\n", $head)) as $line) {
            $c = strpos($line, ':');
            if ($c > 0) {
                $headers[strtolower(trim(substr($line, 0, $c)))][] = trim(preg_replace('/\n[ \t]+/', ' ', substr($line, $c + 1)));
            }
        }
        return [$headers, $body];
    }

    /** The first value of a header, RFC 2047-decoded, or null. */
    public static function header(array $headers, $name)
    {
        return isset($headers[$name][0]) ? self::decodeWords($headers[$name][0]) : null;
    }

    /** RFC 2047 encoded words (=?charset?B|Q?text?=) to UTF-8; spaces between adjacent words dropped. */
    public static function decodeWords($s)
    {
        $s = (string) preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?)/', '$1', (string) $s);
        return (string) preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', function ($m) {
            $text = strtoupper($m[2]) === 'B' ? (string) base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::utf8($text, preg_replace('/\*.*$/', '', $m[1]));
        }, $s);
    }

    public static function utf8($text, $charset)
    {
        $charset = strtoupper(trim((string) $charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $text;
        }
        $out = function_exists('iconv') ? @iconv($charset, 'UTF-8//IGNORE', $text) : false;
        return $out === false ? $text : $out;
    }

    /** A parameter of a header value (charset, boundary, filename, name), with RFC 2231 continuations and charsets. */
    public static function param($value, $name)
    {
        $value = (string) $value;
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;\s]+)/i', $value, $m)) {
            return self::decodeWords(isset($m[2]) && $m[1][0] === '"' ? stripcslashes($m[2]) : $m[1]);
        }
        $parts = [];
        $charset = null;
        preg_match_all('/;\s*' . preg_quote($name, '/') . '\*(\d*)(\*?)\s*=\s*("([^"]*)"|[^;\s]+)/i', $value, $all, PREG_SET_ORDER);
        foreach ($all as $p) {
            $v = isset($p[4]) && $p[3][0] === '"' ? $p[4] : $p[3];
            if ($p[2] === '*' || $p[1] === '') {
                if (($p[1] === '' || $p[1] === '0') && preg_match("/^([^']*)'[^']*'(.*)$/", $v, $cs)) {
                    list($charset, $v) = [$cs[1], $cs[2]];
                }
                $v = rawurldecode($v);
            }
            $parts[(int) $p[1]] = $v;
        }
        ksort($parts);
        return $parts ? self::utf8(implode('', $parts), $charset) : null;
    }

    /** ['headers', 'text' (UTF-8, at most $maxText bytes), 'text_truncated', 'attachments' => [['name','type','bytes','data'?]]]. */
    public static function parse($raw, $maxText = 8000, $withData = false)
    {
        list($headers, $body) = self::split($raw);
        $acc = ['plain' => null, 'html' => null, 'attachments' => [], 'parts' => 0];
        self::walk($headers, $body, 0, $acc, $withData);
        $text = $acc['plain'] !== null ? $acc['plain'] : ($acc['html'] !== null ? self::htmlText($acc['html']) : '');
        $text = trim(str_replace("\r\n", "\n", $text));
        $cut = strlen($text) > $maxText;
        if ($cut) {
            $text = function_exists('mb_strcut') ? mb_strcut($text, 0, $maxText, 'UTF-8') : substr($text, 0, $maxText); // Cli cleans a cut character
        }
        return ['headers' => $headers, 'text' => $text, 'text_truncated' => $cut, 'attachments' => $acc['attachments']];
    }

    private static function walk(array $headers, $body, $depth, array &$acc, $withData)
    {
        if (++$acc['parts'] > self::PARTS || $depth > self::DEPTH) {
            return;
        }
        $type = isset($headers['content-type'][0]) ? $headers['content-type'][0] : 'text/plain';
        $mime = strtolower(trim(strtok($type, ';')));
        if (strpos($mime, 'multipart/') === 0 && ($b = self::param($type, 'boundary')) !== null && $b !== '') {
            $chunks = explode('--' . $b, $body);
            array_shift($chunks); // the preamble
            foreach ($chunks as $chunk) {
                if (strncmp($chunk, '--', 2) === 0) {
                    break; // the closing boundary
                }
                list($h, $b2) = self::split(preg_replace('/^\r?\n/', '', $chunk));
                self::walk($h, preg_replace('/\r?\n$/', '', $b2), $depth + 1, $acc, $withData);
            }
            return;
        }
        $disp = isset($headers['content-disposition'][0]) ? $headers['content-disposition'][0] : '';
        $name = self::param($disp, 'filename');
        $name = $name !== null ? $name : self::param($type, 'name');
        $data = self::decodeBody($body, isset($headers['content-transfer-encoding'][0]) ? $headers['content-transfer-encoding'][0] : '');
        if ($name === null && stripos($disp, 'attachment') !== 0 && in_array($mime, ['text/plain', 'text/html'], true)) {
            $key = $mime === 'text/plain' ? 'plain' : 'html';
            if ($acc[$key] === null) {
                $acc[$key] = self::utf8($data, self::param($type, 'charset'));
            }
            return;
        }
        if ($mime === 'message/rfc822' && $name === null) {
            list($h, $b2) = self::split($data);
            self::walk($h, $b2, $depth + 1, $acc, $withData);
            return;
        }
        $att = ['name' => $name !== null ? $name : '', 'type' => $mime, 'bytes' => strlen($data)];
        $acc['attachments'][] = $withData ? $att + ['data' => $data] : $att;
    }

    public static function decodeBody($body, $encoding)
    {
        $e = strtolower(trim((string) $encoding));
        if ($e === 'base64') {
            return (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
        }
        return $e === 'quoted-printable' ? quoted_printable_decode($body) : $body;
    }

    /** HTML to plain text: scripts, styles and comments dropped, tags removed, entities decoded, blank runs folded. */
    public static function htmlText($html)
    {
        $t = (string) preg_replace(['#<(script|style|head)\b.*?</\1\s*>#is', '/<!--.*?-->/s', '#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i'], ['', '', "\n"], $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace(["/[ \t\x{A0}]+/u", "/\n\s*\n+/"], [' ', "\n\n"], $t));
    }
}
