<?php
/**
 * AntispamRules: the user's own spam rules (local/state/email-rules.json: senders, domains, subject words) and the score
 * of one message from its headers (ADR-0053). The provider's verdict is read only from the FIRST Authentication-Results
 * header (the one the receiving server adds on top; a sender can write lower ones) and from X-Spam-Flag / X-Spam-Status.
 * Score: SPF fail or softfail 1, DKIM fail 1, DMARC fail 2, provider says spam 3, each matching user rule 5; spam at 3.
 */
class AntispamRules
{
    const KINDS = ['from' => 'from', 'domain' => 'domain', 'subject-word' => 'subject_word'];
    const SPAM_AT = 3;
    const HEAD = 'HEADER.FIELDS (FROM SUBJECT AUTHENTICATION-RESULTS X-SPAM-FLAG X-SPAM-STATUS)';

    public static function load($file)
    {
        $d = MailUnit::readState($file);
        $out = [];
        foreach (self::KINDS as $key) {
            $out[$key] = isset($d[$key]) && is_array($d[$key]) ? array_values(array_filter($d[$key], 'is_string')) : [];
        }
        return $out;
    }

    /** [kind key, value] from exactly one of --from, --domain, --subject-word, checked. */
    public static function pick(array $opt)
    {
        $given = array_values(array_intersect(array_keys(self::KINDS), array_keys($opt)));
        if (count($given) !== 1 || !is_string($opt[$given[0]])) {
            throw new CliError('give exactly one of --from ADDRESS, --domain NAME or --subject-word WORD', 'e.g. rule-add --domain spam.example');
        }
        $v = strtolower(trim($opt[$given[0]]));
        $ok = $given[0] === 'from' ? preg_match(MailParse::ADDRESS, $v)
            : ($given[0] === 'domain' ? preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/', $v) : $v !== '' && strlen($v) <= 100 && !preg_match('/[\x00-\x1F\x7F]/', $v));
        if (!$ok) {
            throw new CliError("not a usable {$given[0]} rule: " . Cli::cleanString($v), 'an address, a domain name, or a word of at most 100 characters');
        }
        return [self::KINDS[$given[0]], $v];
    }

    /** The plain address inside a From header, lower case, or ''. */
    public static function sender($from)
    {
        $from = (string) $from;
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) || preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) {
            return strtolower(trim($m[1], '.,;'));
        }
        return '';
    }

    /** One scan record from parsed headers. */
    public static function score(array $headers, array $rules)
    {
        $ar = isset($headers['authentication-results'][0]) ? strtolower($headers['authentication-results'][0]) : '';
        $auth = [];
        foreach (['spf', 'dkim', 'dmarc'] as $k) {
            $auth[$k] = preg_match('/\b' . $k . '=([a-z]+)/', $ar, $m) ? $m[1] : null;
        }
        $flag = isset($headers['x-spam-flag'][0]) ? strtolower(trim($headers['x-spam-flag'][0])) : null;
        $status = isset($headers['x-spam-status'][0]) ? strtolower(trim($headers['x-spam-status'][0])) : null;
        $provider = $flag === 'yes' || ($status !== null && strpos($status, 'yes') === 0) ? true : ($flag !== null || $status !== null ? false : null);
        $from = self::sender(MailParse::header($headers, 'from'));
        $domain = (string) substr(strrchr($from, '@'), 1);
        $subject = strtolower((string) MailParse::header($headers, 'subject'));
        $matched = [];
        foreach ($rules['from'] as $r) {
            if ($from !== '' && $from === $r) {
                $matched[] = "from:$r";
            }
        }
        foreach ($rules['domain'] as $r) {
            if ($domain !== '' && ($domain === $r || substr($domain, -strlen($r) - 1) === ".$r")) {
                $matched[] = "domain:$r";
            }
        }
        foreach ($rules['subject_word'] as $r) {
            if (strpos($subject, $r) !== false) {
                $matched[] = "subject-word:$r";
            }
        }
        $score = (in_array($auth['spf'], ['fail', 'softfail'], true) ? 1 : 0) + ($auth['dkim'] === 'fail' ? 1 : 0)
            + ($auth['dmarc'] === 'fail' ? 2 : 0) + ($provider === true ? 3 : 0) + 5 * count($matched);
        return ['from' => MailParse::header($headers, 'from'), 'subject' => MailParse::header($headers, 'subject'), 'spf' => $auth['spf'],
            'dkim' => $auth['dkim'], 'dmarc' => $auth['dmarc'], 'provider_spam' => $provider, 'rules' => $matched, 'score' => $score,
            'spam' => $score >= self::SPAM_AT];
    }
}

