<?php
/**
 * ToolText: a program's error output made fit for an error message (storage and backup units): the lines that name the
 * error, cleaned, with any URL password removed. ToolProc runs the programs; this class only reads what they printed.
 */
class ToolText
{
    /**
     * The last lines of a program's error output, cleaned, for an error message. When the line naming the error comes
     * before them (a borg traceback ends in a Platform, PID and sys.argv footer), it is put first and the tail is cut.
     */
    public static function tail($text, $chars = Cli::TEXT_MAX)
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $text)), 'strlen'));
        $cause = '';
        foreach (array_reverse(array_slice($lines, 0, -4)) as $l) {
            if (preg_match('/error|exception|fatal/i', $l) && !preg_match('/^(sys\.argv|SSH_ORIGINAL_COMMAND|Platform|PID):/', $l)) {
                $cause = substr($l, 0, (int) ($chars / 2)) . ' | ... | ';
                break;
            }
        }
        $t = implode(' | ', array_slice($lines, -4));
        $room = $chars - strlen($cause);
        return Cli::cleanString(self::redact($cause . (strlen($t) > $room ? '...' . substr($t, -$room) : $t)));
    }

    /**
     * Removes the password of scheme://user:password@host, also one with an unencoded @: restic takes
     * rest:https://user:pass@host:8000/ (doc/030_preparing_a_new_repo.rst:208, 0.14.0) and parses it with Go's
     * url.Parse (internal/backend/rest/config.go:36), which splits the userinfo at the LAST @ (net/url/url.go:577,
     * go1.19.8). Cli::cleanString stops at the first @ and would leave the rest of such a password.
     */
    public static function redact($s)
    {
        return $s === null ? null : (string) preg_replace('#(://[^/\s:@]*:)[^/\s]*@#', '$1REDACTED@', (string) $s);
    }
}
