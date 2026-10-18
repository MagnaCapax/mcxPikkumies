<?php
/**
 * SmtpMime: builds the message from a checked draft (SmtpDraft), CRLF line ends throughout (ADR-0053). Headers come only
 * from checked values: From (the login's address), To, an RFC 2047-encoded Subject when it is not ASCII, Date,
 * Message-ID, MIME-Version. Text is quoted-printable UTF-8; attachments base64 with a plain ASCII filename plus an
 * RFC 2231 filename* for any other name. Attachment bytes are re-read and re-checked against the draft's sha256.
 */
class SmtpMime
{
    public static function build(array $d, $home, $date = null, $seed = null)
    {
        $seed = $seed !== null ? $seed : bin2hex(random_bytes(12));
        $domain = substr(strrchr($d['from'], '@'), 1);
        $head = 'From: ' . $d['from'] . "\r\n" . 'To: ' . implode(",\r\n ", $d['to']) . "\r\n" . 'Subject: ' . self::encodeHeader($d['subject']) . "\r\n"
            . 'Date: ' . date('r', $date !== null ? $date : time()) . "\r\n" . "Message-ID: <$seed@$domain>\r\n" . "MIME-Version: 1.0\r\n";
        $text = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $d['body'])) . "\r\n";
        if (!$d['attachments']) {
            $mime = $head . $text;
        } else {
            $b = "=_pikkumies_$seed";
            $mime = $head . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\nThis is a MIME message.\r\n\r\n--$b\r\n" . $text;
            foreach ($d['attachments'] as $a) {
                $data = SmtpDraft::attachment($a, $home);
                $mime .= "--$b\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; ' . self::filename((string) $a['name']) . "\r\n\r\n" . chunk_split(base64_encode($data), 76, "\r\n");
            }
            $mime .= "--$b--\r\n";
        }
        if (strlen($mime) > SmtpDraft::MAX_TOTAL) {
            throw new CliError('the message is ' . strlen($mime) . ' bytes encoded, over the ' . SmtpDraft::MAX_TOTAL . '-byte limit; nothing sent', 'send fewer or smaller attachments');
        }
        return $mime;
    }

    /** ASCII stays as it is; anything else becomes =?UTF-8?B?...?= words of at most 45 bytes of text each. */
    public static function encodeHeader($s)
    {
        if (!preg_match('/[^\x20-\x7E]/', $s)) {
            return $s;
        }
        $words = [];
        while ($s !== '') {
            $part = function_exists('mb_strcut') ? mb_strcut($s, 0, 45, 'UTF-8') : substr($s, 0, 45);
            $part = $part === '' ? substr($s, 0, 45) : $part;
            $words[] = '=?UTF-8?B?' . base64_encode($part) . '?=';
            $s = (string) substr($s, strlen($part));
        }
        return implode("\r\n ", $words);
    }

    private static function filename($name)
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        $out = 'filename="' . ($ascii !== '' ? $ascii : 'attachment') . '"';
        return $ascii === $name ? $out : $out . "; filename*=UTF-8''" . rawurlencode($name);
    }
}
