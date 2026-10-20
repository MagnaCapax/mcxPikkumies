<?php
/**
 * SecretsInput: how values, passphrases and the TTL reach the secret store (ADR-0085). A value comes from the
 * terminal (not echoed) or from stdin, never from the command line, where it would sit in the shell history, the
 * process list and the agent's transcript. Passphrases come only from the controlling terminal, /dev/tty, never
 * from stdin (a pipe the agent fills), and are wiped after use as far as PHP allows.
 */
class SecretsInput
{
    const PHRASE_MIN = 10;
    const PHRASE_GOOD = 12;      // below this a note suggests a longer one; not a rule
    const TTL_DEFAULT = 28800;   // 8 h
    const TTL_MAX = 2592000;     // 30 days
    const TTL_WARN = 86400;      // longer than a day: printed warning

    /** Test seam: the controlling terminal. */
    public static $ttyPath = '/dev/tty';

    /** Whether stdin is a terminal (a value is then prompted for, not read from a pipe). */
    public static function tty()
    {
        return function_exists('posix_isatty') ? @posix_isatty(STDIN) : (function_exists('stream_isatty') && @stream_isatty(STDIN));
    }

    /** The controlling terminal opened for reading and writing, or null when this process has none. */
    public static function terminal()
    {
        $fh = @fopen(self::$ttyPath, 'r+');
        return $fh === false ? null : $fh;
    }

    /** The terminal's device name (/dev/pts/3), or null. */
    public static function terminalName()
    {
        $fh = self::terminal();
        $name = $fh !== null && function_exists('posix_ttyname') ? @posix_ttyname($fh) : false;
        if ($fh !== null) {
            fclose($fh);
        }
        return is_string($name) ? $name : null;
    }

    /** The value for $handle: typed at the terminal (hidden), or all of stdin with one trailing newline removed. */
    public static function value($handle)
    {
        if (self::tty()) {
            $v = SecretFile::prompt("Value for $handle (not shown): ", 'no terminal', '');
        } else {
            $v = (string) stream_get_contents(STDIN, Secrets::VALUE_MAX + 2);
            $v = (string) preg_replace('/\r?\n\z/', '', $v);
        }
        if ($v === '') {
            throw new CliError("no value for $handle; nothing was stored",
                'the user types it at the prompt in their own terminal, or a command pipes it in on stdin; never on the command line or in the chat');
        }
        if (strlen($v) > Secrets::VALUE_MAX) {
            sodium_memzero($v);
            throw new CliError('the value is longer than ' . Secrets::VALUE_MAX . ' bytes; nothing was stored', 'a password or an API key fits; a key file stays a file');
        }
        return $v;
    }

    /** Writes $text to the controlling terminal (not stdout or stderr, which the agent may read); false without one. */
    public static function say($text)
    {
        $fh = self::terminal();
        if ($fh === null) {
            return false;
        }
        fwrite($fh, $text);
        fclose($fh);
        return true;
    }

    /** One passphrase typed at the controlling terminal, echo off; refuses without one. */
    public static function phrase($label)
    {
        $fh = self::terminal();
        if ($fh === null) {
            throw new CliError('this process has no terminal (/dev/tty), and the store reads passphrases only from one; nothing was done',
                'the user runs this in their own terminal; the agent never gets the passphrase, so it cannot do this step');
        }
        $stty = 'stty %s < ' . escapeshellarg(self::$ttyPath) . ' 2>/dev/null';
        fwrite($fh, $label);
        @shell_exec(sprintf($stty, '-echo'));
        register_shutdown_function(function () use ($stty) {
            @shell_exec(sprintf($stty, 'echo'));
        });
        $line = fgets($fh);
        @shell_exec(sprintf($stty, 'echo'));
        fwrite($fh, "\n");
        fclose($fh);
        return trim((string) $line);
    }

    /** A new passphrase, typed twice at the terminal, at least PHRASE_MIN characters; a note below PHRASE_GOOD. */
    public static function newPhrase()
    {
        $a = self::phrase('New vault passphrase (not shown; ' . self::PHRASE_GOOD . '+ characters, a few random words work well): ');
        $b = self::phrase('Type it again: ');
        $ok = $a === $b;
        sodium_memzero($b);
        if (!$ok || strlen($a) < self::PHRASE_MIN) {
            sodium_memzero($a);
            throw new CliError($ok ? 'too short: at least ' . self::PHRASE_MIN . ' characters are needed; nothing was changed' : 'the two entries differ; nothing was changed',
                'run it again; nothing keeps the passphrase, so choose one you will remember (if forgotten, the secrets are entered again)');
        }
        if (strlen($a) < self::PHRASE_GOOD || preg_match('/^(.)\1*$|^[a-z]+$|^[0-9]+$/', $a)) {
            self::say('note: ' . self::PHRASE_GOOD . "+ characters mixing several words are much harder to guess from a copied vault.enc\n");
        }
        return $a;
    }

    /** Seconds from 90s, 30m, 8h or 7d; default 8 h, at most 30 days. */
    public static function ttl($opt)
    {
        if (!isset($opt['ttl'])) {
            return self::TTL_DEFAULT;
        }
        $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
        if (!is_string($opt['ttl']) || !preg_match('/^([1-9][0-9]{0,6})([smhd])\z/', $opt['ttl'], $m) || $m[1] * $mult[$m[2]] < 60
            || $m[1] * $mult[$m[2]] > self::TTL_MAX) {
            throw new CliError('--ttl must be a time from 60s to 30d, like 30m, 8h or 7d', 'the default is 8h');
        }
        return (int) $m[1] * $mult[$m[2]];
    }
}
