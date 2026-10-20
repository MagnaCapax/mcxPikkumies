<?php
/**
 * SmtpDraft: a message to send, as one JSON file local/state/email-outbox/<sha256>.json (R15, ADR-0053). `send` writes it;
 * `send-draft <sha>` sends it after the user approved exactly what the guard showed from this file (From, every
 * recipient, Subject, each attachment's real path and size, the first 1 KiB of the body). The name is the sha256 of the
 * file, so a changed draft no longer matches what was approved; each attachment carries its own sha256 and size, so a
 * file changed after approval is refused too. Every field is checked again when the draft is read back.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class SmtpDraft
{
    const MAX_BODY = 1048576;     // 1 MiB of text
    const MAX_TOTAL = 20971520;   // 20 MiB, the encoded message (base64 grows attachments by about 1.37)
    const MAX_RCPT = 50;

    public static function address($a)
    {
        if (!is_string($a) || strlen($a) > 254 || !preg_match(MailParse::ADDRESS, $a)) {
            throw new CliError('not a plain email address: ' . Cli::cleanString((string) $a) . '; nothing sent', 'write it as name@example.org, without <> or a display name');
        }
        return $a;
    }

    /** A comma list of addresses, each checked, at most MAX_RCPT, no repeats. */
    public static function addresses($list)
    {
        $out = [];
        foreach (is_array($list) ? $list : explode(',', (string) $list) as $a) {
            $out[strtolower(trim($a))] = self::address(trim($a));
        }
        if (!$out || count($out) > self::MAX_RCPT) {
            throw new CliError('give between 1 and ' . self::MAX_RCPT . ' recipients; nothing sent', 'e.g. --to friend@example.org');
        }
        return array_values($out);
    }

    public static function subject($s)
    {
        if (!is_string($s) || $s === '' || strlen($s) > 900 || preg_match('/[\x00-\x1F\x7F]/', $s) || !preg_match('//u', $s)) {
            throw new CliError('the subject must be one line of text (no line breaks or control characters, at most 900 bytes); nothing sent', 'give --subject a plain line');
        }
        return $s;
    }

    /** A checked draft: from, to, subject, body (UTF-8 text), attachments [{path, name, bytes, sha256}]. */
    public static function spec($from, $to, $subject, $bodyFile, array $attach, $home)
    {
        $body = MailPath::readable($bodyFile, $home, 'the body file');
        if (filesize($body) > self::MAX_BODY) {
            throw new CliError('the body file is over ' . self::MAX_BODY . ' bytes; nothing sent', 'send a longer text as an attachment');
        }
        $text = (string) file_get_contents($body);
        if (!preg_match('//u', $text)) {
            throw new CliError('the body file is not UTF-8 text; nothing sent', 'save it as UTF-8, or send it as an attachment');
        }
        $files = [];
        $total = strlen($text);
        foreach ($attach as $a) {
            $real = MailPath::readable($a, $home, 'the attachment');
            $files[] = ['path' => $real, 'name' => PathName::base($real), 'bytes' => filesize($real), 'sha256' => hash_file('sha256', $real)];
            $total += (int) ceil(filesize($real) * 4 / 3);
        }
        if ($total > self::MAX_TOTAL) {
            throw new CliError("the message would be about $total bytes encoded, over the " . self::MAX_TOTAL . '-byte limit; nothing sent', 'send fewer or smaller attachments, or share a link instead');
        }
        return ['from' => self::address($from), 'to' => self::addresses($to), 'subject' => self::subject($subject), 'body' => $text, 'attachments' => $files];
    }

    /** Writes the draft (0600) and returns its sha256, the name `send-draft` takes. */
    public static function write($outbox, array $spec)
    {
        $json = json_encode($spec + ['created' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sha = hash('sha256', $json);
        if (!is_dir($outbox) && !@mkdir($outbox, 0700, true)) {
            throw new CliError("cannot create $outbox", 'check that pikkumies/local is writable');
        }
        $file = "$outbox/$sha.json";
        if (is_file($file) && !is_link($file) && hash_file("sha256", $file) === $sha) {
            return $sha; // the same draft written twice
        }
        $old = umask(0077);
        $fh = @fopen($file, 'x');
        umask($old);
        if ($fh === false || fwrite($fh, $json) !== strlen($json)) {
            throw new CliError("cannot write the draft $file", 'check the quota (storage stats)');
        }
        fclose($fh);
        return $sha;
    }

    /** The draft named $sha, re-hashed and re-checked; any difference refuses. */
    public static function load($outbox, $sha, $home)
    {
        if (!is_string($sha) || !preg_match('/^[0-9a-f]{64}\z/', $sha)) {
            throw new CliError('not a draft id (64 hex characters from send): ' . Cli::cleanString((string) $sha), 'use the draft value that send printed');
        }
        $file = "$outbox/$sha.json";
        if (!is_file($file) || is_link($file)) {
            throw new CliError("no draft $sha", 'write it again with send');
        }
        $json = (string) file_get_contents($file);
        if (!hash_equals($sha, hash('sha256', $json))) {
            throw new CliError('the draft changed after it was written, so it is not what was approved; nothing sent', 'write it again with send');
        }
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['from'], $d['to'], $d['subject'], $d['body'], $d['attachments']) || !is_array($d['attachments']) || !is_string($d['body'])
            || strlen($d['body']) > self::MAX_BODY || !preg_match('//u', $d['body'])) {
            throw new CliError('the draft is not readable; nothing sent', 'write it again with send');
        }
        self::address($d['from']);
        $d['to'] = self::addresses($d['to']);
        self::subject($d['subject']);
        foreach ($d['attachments'] as $a) {
            self::attachment($a, $home);
        }
        return $d;
    }

    /** The bytes of one attachment, refused unless it still passes the read rules and matches its size and sha256. */
    public static function attachment($a, $home)
    {
        $real = is_array($a) && isset($a['path'], $a['sha256'], $a['bytes']) ? MailPath::readable($a['path'], $home, 'the attachment') : '';
        $data = $real !== '' && $real === $a['path'] ? (string) file_get_contents($real) : null;
        if ($data === null || strlen($data) !== (int) $a['bytes'] || !hash_equals((string) $a['sha256'], hash('sha256', $data))) {
            throw new CliError('an attachment changed or moved since the draft was written; nothing sent', 'write the draft again with send');
        }
        return $data;
    }
}
