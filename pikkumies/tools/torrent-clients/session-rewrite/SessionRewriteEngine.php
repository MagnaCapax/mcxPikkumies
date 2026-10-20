<?php
/**
 * SessionRewriteEngine: the raw-preserving bencode rewrite behind `rewrite` (lib/Bencode.php: dict/string/
 * skip only, no encoder, so the byte-splice and the encoding of a changed value both live here). Every byte
 * outside a changed key's own value is copied unchanged; the file is re-decoded afterwards to prove it.
 */
class SessionRewriteEngine
{
    /**
     * Every known-key change $raw would get for prefix $from -> $to: list of ['key','start','end','old'],
     * $start/$end the ORIGINAL byte range of that key's bencoded string value (lib/Bencode.php's dict()
     * already gives the value's range, not the key's own bytes). [] for a file with no change, including an
     * unreadable one: Bencode::dict() answers [] for anything that is not a valid dictionary at offset 0.
     * Throws (refuses the whole file) when any of $keys repeats at the top level: lib/Bencode.php's dict()
     * silently keeps only the LAST occurrence, and this tool must never guess which copy a real client
     * actually reads (security review N5).
     */
    public static function plan($raw, array $keys, $from)
    {
        $dup = self::duplicateKey($raw, $keys);
        if ($dup !== null) {
            throw new CliError("refused: this file repeats the \"$dup\" key at the top level; which copy the client actually reads cannot be known, so nothing in it is rewritten",
                'this file is unusual and was left alone; look at it by hand before repathing or renaming this account');
        }
        $from = rtrim((string) $from, '/');
        $top = Bencode::dict($raw, 0);
        $changes = [];
        foreach ($keys as $key) {
            if (!isset($top[$key])) {
                continue;
            }
            list($start, $end) = $top[$key];
            if (!isset($raw[$start]) || !ctype_digit($raw[$start])) {
                continue; // the value is not a plain bencoded string (an int, list or dict): never touched
            }
            $value = Bencode::string($raw, $start);
            // A path-COMPONENT prefix, never a byte prefix: --from /home/u/dl must not also match
            // /home/u/dl2/movie (security review N3).
            if ($value !== $from && strncmp($value, $from . '/', strlen($from) + 1) !== 0) {
                continue;
            }
            $changes[] = ['key' => $key, 'start' => $start, 'end' => $end, 'old' => $value];
        }
        return $changes;
    }

    /**
     * The first of $keys that appears twice at $raw's top level, or null when none does (including when
     * $raw is not a valid dictionary at all -- Bencode::dict() itself answers [] for that, handled by the
     * caller). Walks the same way Bencode::dict() does (lib/Bencode.php), but keeps a presence map instead
     * of overwriting, so a repeat is caught instead of silently discarded.
     */
    private static function duplicateKey($raw, array $keys)
    {
        if (!isset($raw[0]) || $raw[0] !== 'd') {
            return null;
        }
        $want = array_flip($keys);
        $seen = [];
        $i = 1;
        while (isset($raw[$i]) && $raw[$i] !== 'e') {
            $keyEnd = Bencode::skip($raw, $i);
            if ($keyEnd < 0 || !ctype_digit($raw[$i])) {
                return null; // malformed: Bencode::dict() will answer [] for this file too
            }
            $key = Bencode::string($raw, $i);
            if (isset($want[$key])) {
                if (isset($seen[$key])) {
                    return $key;
                }
                $seen[$key] = true;
            }
            $end = Bencode::skip($raw, $keyEnd);
            if ($end < 0) {
                return null;
            }
            $i = $end;
        }
        return null;
    }

    /**
     * [new bytes, applied changes with 'new' added] for $raw with every $changes[] value's $from prefix
     * replaced by $to. Splices in one pass over the ORIGINAL offsets (never re-scans after a partial edit,
     * so an earlier replacement's different length cannot shift a later one's offset), then verifies the
     * result before returning it. Throws, writing nothing, if the result does not check out.
     */
    public static function apply($raw, array $changes, $from, $to)
    {
        $from = rtrim((string) $from, '/'); // same normalization plan() matched against; a mismatch here would corrupt the splice
        usort($changes, function ($a, $b) {
            return $a['start'] - $b['start'];
        });
        $out = '';
        $pos = 0;
        $applied = [];
        foreach ($changes as $c) {
            $out .= substr($raw, $pos, $c['start'] - $pos);
            $new = $to . substr($c['old'], strlen($from));
            $out .= strlen($new) . ':' . $new;
            $applied[] = $c + ['new' => $new];
            $pos = $c['end'];
        }
        $out .= substr($raw, $pos);
        self::verify($raw, $out);
        return [$out, $applied];
    }

    /**
     * Re-decodes both buffers and asserts: the new one is still a valid dictionary with the exact same key
     * set (nothing added, removed or renamed), and an "info" dictionary -- which none of SessionRewriteKeys'
     * target files should ever carry, but this is the explicit belt, not the only strap -- is byte-identical
     * where either copy has one, so a torrent's info-hash can never change through this path.
     */
    private static function verify($oldRaw, $newRaw)
    {
        $oldTop = Bencode::dict($oldRaw, 0);
        $newTop = Bencode::dict($newRaw, 0);
        if (!$newTop || array_keys($oldTop) !== array_keys($newTop)) {
            throw new CliError('rewrite produced an unreadable or differently-shaped file; nothing was written',
                'this is a bug in session-rewrite; report it. Nothing on disk was changed by this file.');
        }
        if (isset($oldTop['info']) !== isset($newTop['info'])) {
            throw new CliError("rewrite changed whether the 'info' key exists; nothing was written",
                'this is a bug in session-rewrite; report it. Nothing on disk was changed by this file.');
        }
        if (isset($oldTop['info'])) {
            list($os, $oe) = $oldTop['info'];
            list($ns, $ne) = $newTop['info'];
            if (substr($oldRaw, $os, $oe - $os) !== substr($newRaw, $ns, $ne - $ns)) {
                throw new CliError("rewrite changed the 'info' dictionary's bytes; nothing was written (the info-hash must never change)",
                    'this is a bug in session-rewrite; report it. Nothing on disk was changed by this file.');
            }
        }
    }
}
