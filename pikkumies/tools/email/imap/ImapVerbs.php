<?php
/**
 * ImapVerbs: the read and change verbs of the imap unit (README.md here, ADR-0053). Reading never changes the mailbox:
 * a body or header fetch sets \Seen on the server (curl has no BODY.PEEK form), so a message that was unseen is set back
 * to unseen afterwards. Changes take uids from list or search; each change reads the state back (`verified`).
 */
class ImapVerbs
{
    const SHOW_MAX = 26214400; // 25 MiB: larger messages are for fetch
    const HEAD = 'HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID)';

    public static function folder(array $opt)
    {
        return MailImap::text(isset($opt['folder']) && is_string($opt['folder']) ? $opt['folder'] : 'INBOX', 'folder');
    }

    public static function limit(array $opt)
    {
        return max(1, min(100, isset($opt['limit']) ? (int) $opt['limit'] : Cli::LIMIT));
    }

    /** Header records of the newest $limit of $uids (newest first), with the folder's uidvalidity. */
    public static function summaries(MailImap $imap, $folder, array $uids, $limit)
    {
        $st = $imap->status($folder);
        $total = count($uids);
        $pick = array_reverse(array_slice($uids, -$limit));
        $meta = $pick ? $imap->meta($folder, $pick) : [];
        $records = [];
        $unseen = [];
        foreach ($pick as $uid) {
            if (!isset($meta[$uid])) {
                continue; // gone since the search
            }
            list($h) = MailParse::split($imap->fetch($folder, $uid, self::HEAD, ['max' => 262144]));
            $seen = in_array('\\Seen', $meta[$uid]['flags'], true);
            if (!$seen) {
                $unseen[] = $uid;
            }
            $records[] = ['uid' => $uid, 'folder' => $folder, 'from' => MailParse::header($h, 'from'), 'to' => MailParse::header($h, 'to'),
                'subject' => MailParse::header($h, 'subject'), 'date' => MailParse::header($h, 'date'), 'size_bytes' => $meta[$uid]['size'],
                'seen' => $seen, 'flagged' => in_array('\\Flagged', $meta[$uid]['flags'], true)];
        }
        if ($unseen) {
            $imap->store($folder, $unseen, '-', '\\Seen');
        }
        return ['records' => $records, 'status' => ['total' => $total, 'uidvalidity' => $st['uidvalidity']]];
    }

    /** One message as a record: headers, attachment list and the body as untrusted_text (at most 8000 bytes). */
    public static function show(MailImap $imap, $folder, $uid)
    {
        $meta = $imap->meta($folder, [$uid]);
        if (!isset($meta[(int) $uid])) {
            throw new CliError("no message $uid in $folder", 'list the folder again; the uid may be gone');
        }
        $m = $meta[(int) $uid];
        if ($m['size'] !== null && $m['size'] > self::SHOW_MAX) {
            throw new CliError("message $uid is {$m['size']} bytes, too large to show", 'use fetch to save its attachments');
        }
        $p = MailParse::parse($imap->fetch($folder, $uid, null, ['max' => self::SHOW_MAX]));
        if (!in_array('\\Seen', $m['flags'], true)) {
            $imap->store($folder, [$uid], '-', '\\Seen');
        }
        $h = $p['headers'];
        return ['uid' => (int) $uid, 'folder' => $folder, 'from' => MailParse::header($h, 'from'), 'to' => MailParse::header($h, 'to'),
            'cc' => MailParse::header($h, 'cc'), 'subject' => MailParse::header($h, 'subject'), 'date' => MailParse::header($h, 'date'),
            'message_id' => MailParse::header($h, 'message-id'), 'size_bytes' => $m['size'],
            'attachments' => array_map(function ($a) {
                return ['name' => $a['name'], 'type' => $a['type'], 'bytes' => $a['bytes']];
            }, $p['attachments']),
            'untrusted_text' => $p['text'], 'text_truncated' => $p['text_truncated']];
    }

    public static function move(MailImap $imap, $folder, array $uids, $to)
    {
        $imap->move($folder, $uids, $to);
        $left = $imap->meta($folder, $uids);
        return ['records' => [['moved' => count($uids), 'folder' => $folder, 'to' => $to]], 'status' => ['verified' => !$left]];
    }

    public static function flag(MailImap $imap, $folder, array $uids, array $opt)
    {
        $map = ['seen' => ['+', '\\Seen'], 'unseen' => ['-', '\\Seen'], 'flagged' => ['+', '\\Flagged']];
        $chosen = array_values(array_intersect(array_keys($map), array_keys($opt)));
        if (count($chosen) !== 1) {
            throw new CliError('give exactly one of --seen, --unseen or --flagged', 'e.g. flag 1234 --seen');
        }
        list($sign, $flag) = $map[$chosen[0]];
        $imap->store($folder, $uids, $sign, $flag);
        $ok = true;
        foreach ($imap->meta($folder, $uids) as $m) {
            $ok = $ok && (in_array($flag, $m['flags'], true) === ($sign === '+'));
        }
        return ['records' => [['flagged' => count($uids), 'folder' => $folder, 'change' => $chosen[0]]], 'status' => ['verified' => $ok]];
    }

    /** Deletes exactly these uids, after checking --expect against the folder's uidvalidity (from list). */
    public static function delete(MailImap $imap, $folder, array $uids, array $opt)
    {
        $st = $imap->status($folder);
        if (!isset($opt['expect']) || (string) $opt['expect'] !== (string) $st['uidvalidity']) {
            throw new CliError('--expect must be the uidvalidity that list printed for ' . $folder . '; nothing deleted',
                'run list again and pass its uidvalidity, e.g. delete 1234 --expect ' . $st['uidvalidity']);
        }
        $imap->delete($folder, $uids);
        $left = $imap->meta($folder, $uids);
        return ['records' => [['deleted' => count($uids) - count($left), 'folder' => $folder]], 'status' => ['verified' => !$left]];
    }
}
