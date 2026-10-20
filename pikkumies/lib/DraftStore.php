<?php
/**
 * DraftStore: what a unit sends off the machine, saved once before it goes, and the one record the user's yes is about
 * (/feedback ADR-0056, /ticket ADR-0089). The exact JSON is saved under the unit's state directory as <id>.json, mode
 * 0400, where <id> is the first 32 hex digits of its sha256. load() refuses a symlink, a file whose sha256 no longer
 * starts with its id, one that carries anything but the unit's own fields, and one already sent: after the receiver
 * took it, it is renamed to <id>.sent.json and is never sent again. lock() keeps two sends of one draft from overlapping.
 */
class DraftStore
{
    const ID_RE = '/^[0-9a-f]{32}$/D';

    /** The draft file for $id; $what names the unit in the refusal of a malformed id. */
    public static function path($dir, $id, $what, $suffix = '.json')
    {
        if (!is_string($id) || !preg_match(self::ID_RE, $id)) {
            throw new CliError("a $what id is 32 lowercase hex digits", 'run list to see your drafts');
        }
        return "$dir/$id$suffix";
    }

    /** Saves $json once (an existing draft with the same content is kept as it is). @return array [id, path] */
    public static function save($dir, $json)
    {
        $id = substr(hash('sha256', $json), 0, 32);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("could not create $dir", 'check the disk and the permissions of ~/pikkumies/local/state');
        }
        $path = "$dir/$id.json";
        if (!is_file($path)) {
            $tmp = "$dir/.$id." . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !chmod($tmp, 0400) || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new CliError('could not save the draft', 'check the free disk space');
            }
        }
        return [$id, $path];
    }

    /**
     * [json, decoded payload] of a draft not yet sent whose content still matches its id, with exactly the keys of
     * $fields (name => 'type|type', gettype names) in that order and v 1; anything else is "not a $what draft".
     */
    public static function load($dir, $id, array $fields, $what)
    {
        $path = self::path($dir, $id, $what);
        if (is_file(self::path($dir, $id, $what, '.sent.json'))) {
            throw new CliError("draft $id was already sent", 'it is never sent twice; draft a new one to add something');
        }
        if (is_link($path) || !is_file($path)) {
            throw new CliError("no draft $id", 'run list to see your drafts, or draft a new one');
        }
        $json = (string) file_get_contents($path);
        if (substr(hash('sha256', $json), 0, 32) !== $id) {
            throw new CliError("draft $id was changed after it was made", 'draft it again; a changed draft is never sent');
        }
        $p = json_decode($json, true);
        $ok = is_array($p) && array_keys($p) === array_keys($fields) && $p['v'] === 1;
        foreach ($ok ? $fields : [] as $k => $types) {
            $ok = $ok && in_array(gettype($p[$k]), explode('|', $types), true);
        }
        if (!$ok) {
            throw new CliError("draft $id is not a $what draft", 'draft it again');
        }
        return [$json, $p];
    }

    /** An exclusive lock on the draft while it is sent (released when the handle closes), or a refusal. */
    public static function lock($dir, $id, $what)
    {
        $h = @fopen(self::path($dir, $id, $what), 'r');
        if ($h === false) {
            throw new CliError("no draft $id", 'run list to see your drafts, or draft a new one');
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            throw new CliError("draft $id is being sent right now", 'wait for that send to finish; it is never sent twice');
        }
        return $h;
    }

    /** After the receiver took it: <id>.json becomes <id>.sent.json, which load() never sends. */
    public static function markSent($dir, $id, $what)
    {
        return @rename(self::path($dir, $id, $what), self::path($dir, $id, $what, '.sent.json'));
    }

    /** One row per draft, newest first ($row(id, decoded payload or null, file, sent) gives it; its 'made' sorts). */
    public static function all($dir, callable $row)
    {
        $rows = [];
        foreach (glob("$dir/*.json") ?: [] as $f) {
            $id = preg_replace('/\.sent$/', '', basename($f, '.json'));
            if (preg_match(self::ID_RE, $id)) {
                $p = json_decode((string) file_get_contents($f), true);
                $rows[] = $row($id, is_array($p) ? $p : null, $f, substr($f, -10) === '.sent.json');
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['made'], $a['made']);
        });
        return $rows;
    }
}
