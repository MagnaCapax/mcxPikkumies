<?php
/**
 * ImapFetch: saves one message's attachments into a NEW directory (MailPath::freshDir; default
 * ~/mail-attachments/<folder>-<uid>-<time>), never into a directory that exists (R15, ADR-0053). Names are made safe by
 * MailPath::safeName and written with fopen 'x' 0600. The message size is checked against the free quota first.
 */
class ImapFetch
{
    const MAX = 52428800; // 50 MiB per message

    public static function run(MailImap $imap, $folder, $uid, array $opt, $home)
    {
        $meta = $imap->meta($folder, [$uid]);
        if (!isset($meta[(int) $uid])) {
            throw new CliError("no message $uid in $folder", 'list the folder again; the uid may be gone');
        }
        $size = (int) $meta[(int) $uid]['size'];
        if ($size > self::MAX) {
            throw new CliError("message $uid is $size bytes, over the " . self::MAX . '-byte fetch limit; nothing saved', 'save it from webmail or a mail client');
        }
        $q = Quota::fields($home);
        if ($q['quota_free_bytes'] !== null && $q['quota_limit_bytes'] > 0 && $q['quota_free_bytes'] < $size) {
            throw new CliError("not enough free quota for a $size-byte message; nothing saved", 'free some space first (storage stats)');
        }
        $p = MailParse::parse($imap->fetch($folder, $uid, null, ['max' => self::MAX]), 0, true);
        if (!in_array('\\Seen', $meta[(int) $uid]['flags'], true)) {
            $imap->store($folder, [$uid], '-', '\\Seen');
        }
        if (!$p['attachments']) {
            return ['records' => [], 'status' => ['dir' => null, 'note' => 'the message has no attachments; nothing saved']];
        }
        $dir = self::target($opt, $home, $folder, $uid);
        $records = [];
        foreach ($p['attachments'] as $n => $a) {
            $path = MailPath::writeNew($dir, MailPath::safeName($a['name'], $n + 1), $a['data']);
            $records[] = ['path' => $path, 'original_name' => $a['name'], 'type' => $a['type'], 'bytes' => strlen($a['data'])];
        }
        return ['records' => $records, 'status' => ['dir' => $dir]];
    }

    private static function target(array $opt, $home, $folder, $uid)
    {
        if (isset($opt['to']) && is_string($opt['to'])) {
            return MailPath::freshDir($opt['to'], $home);
        }
        $base = $home . '/mail-attachments';
        if (is_link($base) || (file_exists($base) && !is_dir($base))) {
            throw new CliError("$base is a symlink or a file; nothing saved", 'name a new directory with --to');
        }
        if (!is_dir($base) && !@mkdir($base, 0700)) {
            throw new CliError("cannot create $base", 'check the quota (storage stats)');
        }
        return MailPath::freshDir($base . '/' . MailPath::safeName($folder, 0) . "-$uid-" . date('Ymd-His'), $home);
    }
}
