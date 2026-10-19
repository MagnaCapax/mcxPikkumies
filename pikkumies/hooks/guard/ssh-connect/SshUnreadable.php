<?php
/** SshUnreadable: the ssh-connect hook cannot tell for certain where a command connects, so it blocks (ADR-0068). */
class SshUnreadable extends Exception
{
    /** preg_match that never fails open: a regex engine error (false) makes the command unreadable. */
    public static function matches($re, $subject, &$m = null, $flags = 0, $offset = 0)
    {
        return self::check(preg_match($re, (string) $subject, $m, $flags, $offset));
    }

    /** A preg_* result, or unreadable when the engine failed (false or null). */
    public static function check($result)
    {
        if ($result === false || $result === null) {
            throw new self('a pattern check failed inside the regex engine (too long or too complex)');
        }
        return $result;
    }
}
