<?php
/**
 * ReportFormat: text and number formatting for the report kit. Every string that reaches the HTML goes through esc()
 * (or inline(), which escapes first and only then adds **bold** and `code`); CSS strings go through cssString().
 * Plain PHP 7.3, no dependency outside this directory, so the kit can be copied into another repository as it is.
 */
class ReportFormat
{
    /** Narrow no-break space: between a number and its unit, so "812 GiB" never breaks across lines. */
    const NNBSP = "\xE2\x80\xAF";
    const BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

    public static function esc($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escaped text with **bold** and `code`; nothing else is markup, so a value can never open a tag. */
    public static function inline($text)
    {
        $s = self::esc($text);
        $s = preg_replace('/`([^`\n]{1,200})`/', '<code>$1</code>', $s);
        return (string) preg_replace('/\*\*([^*\n]{1,300})\*\*/', '<strong>$1</strong>', $s);
    }

    /** A CSS string literal: quotes, backslashes, angle brackets and control characters as CSS escapes. */
    public static function cssString($s)
    {
        $out = '';
        foreach (preg_split('//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = strlen($ch) === 1 ? ord($ch) : 0x80;
            $out .= ($o < 0x20 || $o === 0x7f || strpos('"\'\\<>&', $ch) !== false) ? sprintf('\\%X ', $o) : $ch;
        }
        return '"' . $out . '"';
    }

    /** 1234.5 -> "1,234.5". Decimals default by size: integers as they are, under 10 two places, under 100 one. */
    public static function number($v, $decimals = null)
    {
        if (!is_int($v) && !is_float($v)) {
            return '–';
        }
        if ($decimals === null) {
            $a = abs($v);
            $cap = (is_int($v) || floor($v) == $v || $a >= 100) ? 0 : ($a >= 10 ? 1 : 2);
            $s = rtrim(sprintf('%.6F', $v), '0');
            $decimals = min($cap, max(0, strlen($s) - strpos($s, '.') - 1));
        }
        return number_format($v, (int) $decimals, '.', ',');
    }

    /** [number text, unit] for a figure: bytes scaled to binary units, percent glued to the number. */
    public static function parts(array $f)
    {
        $v = $f['value'];
        $unit = (string) $f['unit'];
        $dec = $f['decimals'];
        if ($unit === 'bytes' && (is_int($v) || is_float($v))) {
            $i = 0;
            $x = (float) $v;
            while (abs($x) >= 1024 && $i < count(self::BYTE_UNITS) - 1) {
                $x /= 1024;
                $i++;
            }
            $auto = $i === 0 || abs($x) >= 100 || abs($x - round($x)) < 0.05 ? 0 : 1;
            return [self::number($i === 0 ? (int) $x : $x, $dec !== null ? $dec : $auto), self::BYTE_UNITS[$i]];
        }
        return [self::number($v, $dec), $unit];
    }

    /** "812 GiB", "81.2%", "37". */
    public static function figure(array $f)
    {
        list($n, $u) = self::parts($f);
        if ($u === '') {
            return $n;
        }
        return $u === '%' ? $n . '%' : $n . self::NNBSP . $u;
    }

    /** Unix time from an int, a numeric string or an ISO-8601 / date string; null when it is not a time. */
    public static function ts($v)
    {
        if (is_int($v) || (is_float($v) && $v > 0)) {
            return (int) $v;
        }
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        $t = strtotime($v . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? ' 00:00:00 UTC' : ''));
        return $t === false ? null : $t;
    }

    public static function date($ts)
    {
        return $ts === null ? 'no date' : gmdate('j M Y', (int) $ts);
    }

    public static function dateTime($ts)
    {
        return $ts === null ? 'no date' : gmdate('j M Y, H:i', (int) $ts) . ' UTC';
    }

    /** "3 h", "12 d": how much older $ts is than $ref; "" when either is unknown or it is not older. */
    public static function age($ts, $ref)
    {
        if ($ts === null || $ref === null || $ref <= $ts) {
            return '';
        }
        $s = $ref - $ts;
        return $s < 3600 ? max(1, intdiv($s, 60)) . ' min' : ($s < 172800 ? intdiv($s, 3600) . ' h' : intdiv($s, 86400) . ' d');
    }

    /** Words of a text, for the reading-length check: runs of letters/digits, markup ignored. */
    public static function sentences($text)
    {
        $t = preg_replace('/\{fig:[A-Za-z0-9_.-]+\}/', 'N', (string) $text);
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"(])|\n+/u', trim((string) $t));
        return array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));
    }

    public static function wordCount($sentence)
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’.,-]*/u', (string) $sentence);
    }
}
