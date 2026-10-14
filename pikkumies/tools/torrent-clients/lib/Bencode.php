<?php
/**
 * Bencode (shared by the rtorrent and qbittorrent units): just enough of a reader to find the raw
 * bytes of a .torrent's info dictionary, whose hash is the torrent's id (BEP 3 encoding: i<n>e,
 * <len>:<bytes>, l...e, d...e with string keys).
 * Malformed input gives an empty result, never a loop or a read past the end.
 */
class Bencode
{
    /** Keys of the dictionary at $at => [start, end) of each value; [] when it is not a valid dictionary. */
    public static function dict($s, $at)
    {
        $s = (string) $s;
        if (!isset($s[$at]) || $s[$at] !== 'd') {
            return [];
        }
        $out = [];
        $i = $at + 1;
        while (isset($s[$i]) && $s[$i] !== 'e') {
            $keyEnd = self::skip($s, $i);
            if ($keyEnd < 0 || !ctype_digit($s[$i])) {
                return [];
            }
            $key = self::string($s, $i);
            $end = self::skip($s, $keyEnd);
            if ($end < 0) {
                return [];
            }
            $out[$key] = [$keyEnd, $end];
            $i = $end;
        }
        return isset($s[$i]) ? $out : [];
    }

    /** The string value at $at ("4:spam" -> "spam"), '' when there is none. */
    public static function string($s, $at)
    {
        $colon = strpos($s, ':', $at);
        if ($colon === false || !ctype_digit(substr($s, $at, $colon - $at))) {
            return '';
        }
        return (string) substr($s, $colon + 1, (int) substr($s, $at, $colon - $at));
    }

    /** Offset just after the value that starts at $i, or -1 when it is malformed. */
    public static function skip($s, $i, $depth = 0)
    {
        $n = strlen($s);
        if ($i >= $n || $depth > 64) {
            return -1;
        }
        $c = $s[$i];
        if ($c === 'i') {
            $e = strpos($s, 'e', $i);
            return ($e === false || !preg_match('/^-?\d+$/', substr($s, $i + 1, $e - $i - 1))) ? -1 : $e + 1;
        }
        if ($c === 'l' || $c === 'd') {
            $i++;
            while ($i < $n && $s[$i] !== 'e') {
                $i = self::skip($s, $i, $depth + 1);
                if ($i < 0) {
                    return -1;
                }
            }
            return $i < $n ? $i + 1 : -1;
        }
        $colon = strpos($s, ':', $i);
        if ($colon === false || !ctype_digit(substr($s, $i, $colon - $i))) {
            return -1;
        }
        $end = $colon + 1 + (int) substr($s, $i, $colon - $i);
        return $end <= $n ? $end : -1;
    }
}
