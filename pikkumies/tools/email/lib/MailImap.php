<?php
/**
 * MailImap: the IMAP commands of the imap and antispam units over MailTransport (ADR-0053). libcurl URL-decodes a custom
 * request and only then refuses control bytes, so every argument is checked here first: a uid is ^[1-9][0-9]{0,9}$
 * (never a range or `*`), a folder or search word has no byte below 0x20, 0x7F or above 0x7E, a quoted string escapes
 * `\` and `"` (RFC 3501), and `%` becomes `%25` over the whole command, so `%22` stays text. A folder in the URL path is
 * rawurlencode'd per segment, so a `;UID=` inside a name cannot change the operation. Bodies come through the URL form
 * `;UID=n` (curl drops literals from custom answers). Deletes are UID EXPUNGE of the given uids only (UIDPLUS); without
 * it the delete is refused, never a plain EXPUNGE, which removes every \Deleted message in the folder.
 */
class MailImap
{
    const MAX_UIDS = 500;

    private $t;
    private $caps = null;

    public function __construct(MailTransport $t)
    {
        $this->t = $t;
    }

    public static function uid($uid)
    {
        if (!is_string($uid) && !is_int($uid) || !preg_match('/^[1-9][0-9]{0,9}\z/', (string) $uid) || (float) $uid > 4294967295) {
            throw new CliError('not a message uid: ' . Cli::cleanString((string) $uid), 'use the uid that list or search printed (a whole number)');
        }
        return (string) $uid;
    }

    /** A comma list of checked uids (at most MAX_UIDS), never a range. */
    public static function uidSet(array $uids)
    {
        if (!$uids || count($uids) > self::MAX_UIDS) {
            throw new CliError('give between 1 and ' . self::MAX_UIDS . ' uids', 'split the list into smaller calls');
        }
        return implode(',', array_map([__CLASS__, 'uid'], $uids));
    }

    /** Printable ASCII only: a folder name exactly as `folders` prints it, or a search word. */
    public static function text($s, $what)
    {
        if (!is_string($s) || $s === '' || strlen($s) > 255 || preg_match('/[^\x20-\x7E]/', $s)) {
            throw new CliError("not a usable $what (printable ASCII, 1-255 characters): " . Cli::cleanString((string) $s),
                $what === 'folder' ? 'use the name exactly as folders prints it' : 'use plain words without accents or control characters');
        }
        return $s;
    }

    public static function quote($s)
    {
        return '"' . addcslashes($s, "\\\"") . '"';
    }

    /** The final custom request: curl URL-decodes it, so a literal % is sent as %25. */
    public static function custom($command)
    {
        return str_replace('%', '%25', $command);
    }

    /** URL path of a folder (each segment rawurlencode'd), plus ;UIDVALIDITY, /;UID and ;SECTION when given. */
    public static function url($folder = null, $uid = null, $section = null, $uidvalidity = null)
    {
        if ($folder === null) {
            return '/';
        }
        $segments = explode('/', self::text($folder, 'folder'));
        if (in_array('', $segments, true)) {
            throw new CliError('a folder name with an empty part: ' . $folder, 'use the name exactly as folders prints it');
        }
        $path = '/' . implode('/', array_map('rawurlencode', $segments));
        $path .= $uidvalidity !== null ? ';UIDVALIDITY=' . self::uid($uidvalidity) : '';
        if ($uid !== null) {
            $path .= '/;UID=' . self::uid($uid) . ($section !== null ? ';SECTION=' . rawurlencode($section) : '');
        }
        return $path;
    }

    private function run($folder, $command, array $o = [])
    {
        return $this->t->request(self::url($folder), $o + ['custom' => self::custom($command)]);
    }

    public function capabilities()
    {
        if ($this->caps === null) {
            $raw = $this->run(null, 'CAPABILITY');
            $this->caps = preg_match('/^\* CAPABILITY (.*)$/mi', $raw, $m) ? array_map('strtoupper', preg_split('/\s+/', trim($m[1]))) : [];
        }
        return $this->caps;
    }

    /** [['name', 'delimiter', 'flags' => [...]], ...]; names sent as literals are skipped (curl drops them). */
    public function folders()
    {
        $out = [];
        foreach (explode("\n", $this->run(null, 'LIST "" "*"')) as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") ("(?:[^"\\\\]|\\\\.)*"|[^\s"{]+)\s*$/i', trim($line), $m)) {
                $name = $m[3][0] === '"' ? stripcslashes(substr($m[3], 1, -1)) : $m[3];
                $out[] = ['name' => $name, 'delimiter' => strtoupper($m[2]) === 'NIL' ? null : stripcslashes(substr($m[2], 1, -1)),
                    'flags' => preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)];
            }
        }
        return $out;
    }

    /** ['messages', 'unseen', 'uidvalidity', 'uidnext'] (ints). */
    public function status($folder)
    {
        $raw = $this->run(null, 'STATUS ' . self::quote(self::text($folder, 'folder')) . ' (MESSAGES UNSEEN UIDVALIDITY UIDNEXT)');
        $s = [];
        foreach (['messages', 'unseen', 'uidvalidity', 'uidnext'] as $k) {
            $s[$k] = preg_match('/\b' . strtoupper($k) . ' (\d+)/i', $raw, $m) ? (int) $m[1] : null;
        }
        return $s;
    }

    /** Uids matching ALL, UNSEEN or TEXT "<words>", ascending. */
    public function search($folder, $what = 'ALL', $words = null)
    {
        $crit = $what === 'TEXT' ? 'TEXT ' . self::quote(self::text($words, 'search word')) : ($what === 'UNSEEN' ? 'UNSEEN' : 'ALL');
        $raw = $this->run($folder, "UID SEARCH $crit");
        $uids = preg_match('/^\* SEARCH([\d ]*)/mi', $raw, $m) ? array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)) : [];
        sort($uids);
        return $uids;
    }

    /** [uid => ['flags' => [...], 'size' => int, 'date' => string]] for the given uids. */
    public function meta($folder, array $uids)
    {
        $out = [];
        foreach (explode("\n", $this->run($folder, 'UID FETCH ' . self::uidSet($uids) . ' (UID FLAGS RFC822.SIZE INTERNALDATE)')) as $line) {
            if (preg_match('/\bUID (\d+)/i', $line, $u)) {
                $out[(int) $u[1]] = ['flags' => preg_match('/FLAGS \(([^)]*)\)/i', $line, $f) ? preg_split('/\s+/', trim($f[1]), -1, PREG_SPLIT_NO_EMPTY) : [],
                    'size' => preg_match('/RFC822\.SIZE (\d+)/i', $line, $s) ? (int) $s[1] : null,
                    'date' => preg_match('/INTERNALDATE "([^"]*)"/i', $line, $d) ? $d[1] : null];
            }
        }
        return $out;
    }

    /** A message or one section of it ($section e.g. 'HEADER'); $o: max, sink, uidvalidity. Marks it \Seen (BODY[]). */
    public function fetch($folder, $uid, $section = null, array $o = [])
    {
        $path = self::url($folder, $uid, $section, isset($o['uidvalidity']) ? $o['uidvalidity'] : null);
        return $this->t->request($path, array_intersect_key($o, ['max' => 1, 'sink' => 1]));
    }

    /** +FLAGS or -FLAGS (\Seen, \Flagged, \Deleted) on the given uids, silently. */
    public function store($folder, array $uids, $sign, $flag)
    {
        if (!in_array($sign, ['+', '-'], true) || !in_array($flag, ['\\Seen', '\\Flagged', '\\Deleted'], true)) {
            throw new CliError('unknown flag change', 'this is a bug in the tool');
        }
        $this->run($folder, 'UID STORE ' . self::uidSet($uids) . " {$sign}FLAGS.SILENT ($flag)");
    }

    /** Moves uids to $dest: MOVE, else COPY + \Deleted + UID EXPUNGE of those uids; refused without MOVE or UIDPLUS. */
    public function move($folder, array $uids, $dest)
    {
        $set = self::uidSet($uids);
        $to = self::quote(self::text($dest, 'folder'));
        if (in_array('MOVE', $this->capabilities(), true)) {
            $this->run($folder, "UID MOVE $set $to");
            return;
        }
        $this->requireUidplus('move');
        $this->run($folder, "UID COPY $set $to");
        $this->expunge($folder, $uids);
    }

    /** Removes exactly these uids: \Deleted, then UID EXPUNGE of the same set. Never a plain EXPUNGE. */
    public function delete($folder, array $uids)
    {
        $this->requireUidplus('delete');
        $this->expunge($folder, $uids);
    }

    private function expunge($folder, array $uids)
    {
        $this->store($folder, $uids, '+', '\\Deleted');
        $this->run($folder, 'UID EXPUNGE ' . self::uidSet($uids));
    }

    private function requireUidplus($what)
    {
        if (!in_array('UIDPLUS', $this->capabilities(), true)) {
            throw new CliError("the server lacks UIDPLUS, so a $what could remove other messages marked deleted; nothing changed",
                "do this $what in a mail client or webmail");
        }
    }
}
