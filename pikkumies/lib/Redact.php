<?php
/**
 * Secrets removed from text that leaves the command line or the machine, one line at a time: what Cli::cleanString
 * removes (URL passkeys, user:password@), option, header, cookie and variable forms, config-file lines (ini, YAML,
 * JSON, netrc), a mysql -p, sshpass -p or curl -u password, PEM private-key blocks, and common token forms (vsk-, sk-,
 * AKIA, ghp_, xoxb-, JWT), which Hermes' own export redaction misses for vsk-. Common forms only: anything else stays,
 * so a person reads the result before it is sent. A regex engine error throws; it never returns less than it removed.
 * Used by the command log (ADR-0044) and by /feedback (ADR-0056).
 */
class Redact
{
    /** A secret-like name: the keyword whole or between underscores (API_KEY, db_password), never inside a word (monkey, compass). */
    const NAME = '(?<![A-Za-z0-9_])(?:[A-Za-z0-9]*_)*(?:password|passwd|passphrase|pass|pwd|token|secret|key|apikey|passkey|auth|credentials?)(?:_[A-Za-z0-9_]*)?(?![A-Za-z0-9])';
    const VALUE = '(\'[^\']*\'|"[^"]*"|\S+)';

    public static function secrets($text)
    {
        $out = [];
        $inKey = false;
        foreach (explode("\n", (string) $text) as $line) {
            // A PEM private key: the BEGIN line is kept up to its marker, the base64 body is left out up to END.
            if ($inKey) {
                if (strpos($line, '-----END') !== false) {
                    $inKey = false;
                } elseif (preg_match('#^(\s*|[A-Za-z0-9+/=]+\s*|[A-Za-z-]+:\s.*)$#', $line) === 1) {
                    continue;
                } else {
                    $inKey = false;
                }
            }
            if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $line) === 1) {
                $inKey = strpos(substr($line, (int) strpos($line, '-----BEGIN') + 10), '-----END') === false;
                $line = (string) preg_replace('/(-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----).*$/', '$1REDACTED', $line);
            }
            $out[] = self::line($line);
        }
        return implode("\n", $out);
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
