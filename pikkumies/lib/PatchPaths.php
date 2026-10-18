<?php
/**
 * PatchPaths: the files a V4A patch names. That form of Hermes' `patch` tool has no `path` field: each file is named in a
 * header, `*** Update File: p`, `*** Add File: p`, `*** Delete File: p` or `*** Move File: a -> b` (Hermes
 * tools/patch_parser.py, _OP_MARKERS and parse_v4a_patch). Hermes splits the patch into lines, matches each header with
 * Python's Unicode \s and trims the path with str.strip(). Python counts U+001C to U+001F as whitespace and PCRE's \s does
 * not, even with (*UCP), so WS adds them; WS never matches a line break, because Hermes reads one line at a time and a
 * header must not swallow the next line's path. Every line is read, also outside Begin/End Patch, so this sees at least
 * the files Hermes changes. Used by the config-change and guard-destructive hooks (ADR-0059, ADR-0020).
 */
class PatchPaths
{
    const WS = '(?:[^\S\n]|[\x{1c}-\x{1f}])';

    /** Every path the headers name (both sides of a Move), trimmed; null on a regex engine error, which a caller must block. */
    public static function v4a($patch)
    {
        $ws = self::WS;
        $headers = [
            '/(*UCP)^\*\*\*' . $ws . '*(?:Update|Add|Delete)' . $ws . '+File:' . $ws . '*(.+)$/mu',
            '/(*UCP)^\*\*\*' . $ws . '*Move' . $ws . '+File:' . $ws . '*(.+?)' . $ws . '*->' . $ws . '*(.+)$/mu',
        ];
        $paths = [];
        foreach ($headers as $re) {
            if (preg_match_all($re, (string) $patch, $m) === false) {
                return null;
            }
            for ($i = 1; $i < count($m); $i++) {
                $paths = array_merge($paths, $m[$i]);
            }
        }
        foreach ($paths as $k => $path) {
            $paths[$k] = preg_replace('/(*UCP)^' . $ws . '+|' . $ws . '+$/u', '', $path);
            if ($paths[$k] === null) {
                return null;
            }
        }
        return $paths;
    }
}
