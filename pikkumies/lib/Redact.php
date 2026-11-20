<?php
/**
 * Secrets removed from text that leaves the command line or the machine, one line at a time: what Cli::cleanString
 * removes (URL passkeys, user:password@), option, header, cookie and variable forms (also long options and headers that
 * end in -secret, -pass, -token or -key), config-file lines (ini, YAML, JSON with snake_case or camelCase names,
 * netrc), a mysql -p, sshpass -p or curl -u password, PEM private-key blocks (also indented or quoted), the private
 * lines of a PuTTY key, crypt(3) password hashes, and common token forms (vsk-, sk-, AKIA, ghp_, xoxb-, JWT), which
 * Hermes' own export redaction misses for vsk-. Common forms only: anything else stays, so a person reads the result
 * before it is sent; a send nobody reads adds its own pass (support tickets, ADR-0100). A regex engine error throws;
 * it never returns less than it removed. Used by the command log (ADR-0044), /feedback (ADR-0056) and support tickets;
 * text that leaves the machine goes through outbound() instead, which adds two rules only it applies (ADR-0117).
 */
class Redact
{
    /**
     * A secret-like name: the keyword whole or between underscores (API_KEY, db_password), never inside a word (monkey,
     * compass); or a camelCase name ending in Key, Token, Secret, Password or Passwd (accessToken, PrivateKey), matched
     * case-sensitively so a lower-case word that only contains one (monkey) stays.
     */
    const NAME = '(?<![A-Za-z0-9_])' . self::NAME_WORD;
    const NAME_WORD = '(?:(?:[A-Za-z0-9]*_)*(?:password|passwd|passphrase|pass|pwd|token|secret|key|apikey|passkey|auth|credentials?)(?:_[A-Za-z0-9_]*)?|(?-i:[A-Za-z0-9]*(?:Key|Token|Secret|Password|Passwd)))(?![A-Za-z0-9])';
    /**
     * Where an outbound name may start: after a non-word character, or right after an escaped \n, \r or \t (in
     * JSON-escaped text the escape's letter would otherwise read as part of the name: \npassword). Never on the escape's
     * own letter, so the name reported is the real one.
     */
    const OUT_START = '(?:(?<![A-Za-z0-9_\\\\])|(?<=\\\\[ntr]))';
    const VALUE = '(\'[^\']*\'|"[^"]*"|\S+)';
    /** The most lines a PuTTY "Private-Lines: N" block may claim, so a bad number cannot swallow the rest of a text. */
    const PPK_MAX = 200;
    /**
     * Outbound only (ADR-0117): a secret-like name, optionally quoted (a JSON key), then `=` or `:` and a quoted literal
     * at any escaping depth (", \", \\\"), anywhere on a line. Text inside a JSON-escaped tool-call argument never starts
     * a line, so the line-anchored form in line() misses it (#24). Groups: 1 everything before the value, 2 the name, 3
     * the value. The value never holds a quote or a backslash, so the quotes and escapes around it stay as they were.
     */
    const OUT_QUOTED = '/((' . self::OUT_START . self::NAME_WORD . ')(?:\\\\*["\'])?\s*[:=]\s*\\\\*["\'])([^"\'\\\\\n]++)(?=\\\\*["\'])/i';
    /** Outbound only: a value after a REDACTED marker (`NAME=REDACTED = "v"`, left when two redactors run in series, #24). */
    const OUT_TAIL = '/((REDACTED)\s*[:=]\s*\\\\*["\']?)([^\s"\'\\\\]++)/';

    public static function secrets($text)
    {
        $out = [];
        $inKey = false;
        $prefix = '';
        $skip = 0;
        foreach (explode("\n", (string) $text) as $line) {
            // A PuTTY key file's private part: "Private-Lines: N" and the N base64 lines after it are left out.
            if ($skip > 0) {
                $skip--;
                continue;
            }
            if (preg_match('/^\s*Private-Lines:\s*(\d{1,4})\s*$/', $line, $p) === 1) {
                $skip = min((int) $p[1], self::PPK_MAX);
                $out[] = 'Private-Lines: REDACTED';
                continue;
            }
            // A PEM private key: the BEGIN line is kept up to its marker, the base64 body is left out up to END. A key
            // indented or quoted in an email (">") keeps that prefix on every line: it is taken off before the body test.
            if ($inKey) {
                $body = $prefix !== '' && strpos($line, $prefix) === 0 ? substr($line, strlen($prefix)) : $line;
                if (strpos($line, '-----END') !== false) {
                    $inKey = false;
                } elseif (preg_match('#^(\s*|[A-Za-z0-9+/=]+\s*|[A-Za-z-]+:\s.*)$#', $body) === 1) {
                    continue;
                } else {
                    $inKey = false;
                }
            }
            if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $line) === 1) {
                $inKey = strpos(substr($line, (int) strpos($line, '-----BEGIN') + 10), '-----END') === false;
                $prefix = preg_match('/^\s*(?:>\s*)*/', $line, $pm) === 1 ? $pm[0] : '';
                $line = (string) preg_replace('/(-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----).*$/', '$1REDACTED', $line);
            }
            $out[] = self::line($line);
        }
        return implode("\n", $out);
    }

    /**
     * Text that leaves the machine (/feedback, /ticket, the publish check, upstream drafts; ADR-0117): secrets() plus the
     * two outbound rules. The command log and the local stores keep secrets() alone, so credentials stay usable inside
     * the session. A regex engine error throws, as in secrets(). $names receives the variable names the two rules removed
     * a value from (REDACTED for a value after a marker), never a value.
     */
    public static function outbound($text, &$names = null)
    {
        $names = is_array($names) ? $names : [];
        $out = [];
        foreach (explode("\n", self::secrets($text)) as $line) {
            $r = preg_replace_callback([self::OUT_QUOTED, self::OUT_TAIL], function ($m) use (&$names) {
                if (!self::literal($m[3])) {
                    return $m[0];
                }
                $names[] = $m[2];
                return $m[1] . 'REDACTED';
            }, $line);
            if (!is_string($r)) {
                self::fail();
            }
            $out[] = $r;
        }
        return implode("\n", $out);
    }

    /**
     * The first value the outbound rules would still remove from $text, as [name, line number], or null. Run on the exact
     * bytes before they leave: a draft saved before ADR-0117, or one changed by hand, is caught here. An engine error throws.
     */
    public static function leftover($text)
    {
        $n = 0;
        foreach (explode("\n", (string) $text) as $line) {
            $n++;
            foreach ([self::OUT_QUOTED, self::OUT_TAIL] as $re) {
                if (preg_match_all($re, $line, $all, PREG_SET_ORDER) === false) {
                    self::fail();
                }
                foreach ($all as $m) {
                    if (self::literal($m[3])) {
                        return [$m[2], $n];
                    }
                }
            }
        }
        return null;
    }

    /** Refuses $text, named $what, when leftover() finds a value; the message names the variable, never the value. */
    public static function refuseLeftover($text, $what)
    {
        $l = self::leftover($text);
        if ($l !== null) {
            throw new CliError("$what still holds a value assigned to {$l[0]}, so it is not sent",
                'it was made before quoted and JSON-escaped secrets were removed, or changed since: draft it again');
        }
    }

    /** A literal worth removing: not already REDACTED, not code ($var, call(), a.b.c names), not a placeholder word. */
    private static function literal($v)
    {
        return preg_match('/[A-Za-z0-9]/', $v) === 1 && preg_match('/^(?:REDACTED|[$({%<*\[#])/', $v) !== 1 && strpos($v, '(') === false
            && preg_match('/^[A-Za-z_]+(?:\.[A-Za-z_]+)+$/', $v) !== 1
            && preg_match('/^(?:true|false|null|none|yes|no|string|number|int|bool|basic|bearer|required|optional)$/i', $v) !== 1;
    }

    private static function line($line)
    {
        $s = Cli::cleanString($line);
        if (preg_last_error() !== PREG_NO_ERROR) {
            self::fail();
        }
        $n = self::NAME;
        $v = self::VALUE;
        $r = preg_replace([
            '/((?<![A-Za-z0-9_-])--?(?:password|passwd|pass|token|api[-_]?key|secret|auth)(?:=|\s+))' . $v . '/i',
            // A long option that ends in one of these words: --drive-client-secret X, --webdav-pass X, --rpc-secret=X.
            '/((?<![A-Za-z0-9_-])--[a-z0-9-]*-(?:secret|pass|password|token|key)(?:=|\s+))' . $v . '/i',
            // A header whose name ends in -token or -key: X-Auth-Token: X.
            '/(\b[A-Za-z0-9-]*-(?:token|key)\s*:\s*)(\'[^\']*\'|"[^"]*"|[^\s\'"]+)/i',
            // A crypt(3) password hash, as in /etc/shadow: $1$, $2b$, $5$, $6$, $y$, $gy$, $7$.
            '/(\$(?:1|2[abxy]?|5|6|y|gy|7)\$)[.\/A-Za-z0-9$]{8,}/',
            '/((?<![A-Za-z0-9_-])(?:-u|--user)\s*[\'"]?[^\s:\'"]*:)([^\s\'"]+)/',
            '/(\bsshpass\s+-p\s*)' . $v . '/',
            '/(\bmysql\S*[^\S\n]+(?:\S+[^\S\n]+)*?-p)(\S+)/',
            '/(\bauthorization\s*:\s*(?:[A-Za-z][A-Za-z0-9_-]*\s+)?)(\'[^\']*\'|"[^"]*"|[^\s\'"]+)/i',
            '/(\bx-api-key\s*:\s*)(\'[^\']*\'|"[^"]*"|[^\s\'"]+)/i',
            '/(\b(?:set-)?cookie\s*:\s*)(\S.*)$/i',
            '/^(\s*(?:-\s+)?["\']?' . $n . '["\']?(?:\s*:\s+|\s+=\s*|=\s+))(\S.*)$/i',
            // The whole shell word of the value: quoted parts, $'...', backslash escapes and plain characters, so
            // 'it'\''s' or "a b" is removed in full (ADR-0049); an unclosed quote takes the rest of the line. Same
            // NAME as the plain line below (so "keyboard=", "bypass=" never match), run first so a multi-segment
            // word is never partly redacted by the simpler line.
            '/(' . $n . '=)((?:\$\'(?:[^\'\\\\]|\\\\.)*\'?|\'[^\']*\'?|"(?:[^"\\\\]|\\\\.)*"?|\\\\.|[^\s\'"\\\\;&|<>()])+)/i',
            '/(' . $n . '=)' . $v . '/i',
            '/("' . $n . '"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"|[^\s,}\]]+)/i',
            '/(\b(?:machine|login)\s+\S+.*?\spassword\s+)(\S+)/i',
            '/^(\s*password\s+)(\S+)\s*$/i',
            '/(?<![A-Za-z0-9])(v?sk-)[A-Za-z0-9_-]{8,}/',
            '/(?<![A-Za-z0-9])(AKIA)[0-9A-Z]{16}/',
            '/(?<![A-Za-z0-9])(gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}/',
            '/(?<![A-Za-z0-9])(xox[abposr]-)[A-Za-z0-9-]{10,}/',
            '/(?<![A-Za-z0-9_-])(eyJ)[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        ], '$1REDACTED', $s);
        if (!is_string($r)) {
            self::fail();
        }
        return $r;
    }

    private static function fail()
    {
        throw new CliError('a line too long or too complex to check for passwords and keys',
            'nothing was kept; shorten it (for /feedback: leave the conversation out with --no-conversation)');
    }
}
