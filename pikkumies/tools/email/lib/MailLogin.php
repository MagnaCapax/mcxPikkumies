<?php
/**
 * MailLogin: the mail logins, typed by the user at a terminal (ADR-0019, ADR-0053), never in the chat or on a command
 * line, stored with SecretFile as local/secrets/email-<unit> (directory 0700, file 0600). The guard blocks the agent
 * from reading that directory and from running any email `login` (a terminal the agent opens is no proof of a human).
 * A login is tried against the server before it is stored, so a typo is caught at once. status shows host and user only.
 */
class MailLogin
{
    const PORT = ['imap' => 993, 'pop' => 995, 'smtp' => 465];

    public static function file($dir, $unit)
    {
        return rtrim($dir, '/') . "/email-$unit";
    }

    /** The stored login (host, port, user, password and any extra fields), or null. */
    public static function load($dir, $unit, $uid = null)
    {
        $raw = SecretFile::read(self::file($dir, $unit), "the user runs `$unit login` in their own terminal", $uid);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['host'], $d['port'], $d['user'], $d['password']) || !is_string($d['host']) || !is_string($d['user'])
            || !is_string($d['password'])) {
            return null;
        }
        return $d;
    }

    /** The stored login or a CliError saying how to make one. */
    public static function need($dir, $unit)
    {
        $d = self::load($dir, $unit);
        if ($d === null) {
            throw new CliError("no $unit login stored", "the user runs `php " . dirname(__DIR__) . "/$unit/$unit.php login` in their own terminal");
        }
        return $d;
    }

    public static function transport(array $d)
    {
        $ports = MailTransport::PORTS + MailTransport::$extraPorts;
        $scheme = isset($ports[(int) $d['port']]) ? $ports[(int) $d['port']] : '';
        return new MailTransport($scheme, $d['host'], (int) $d['port'], $d['user'], $d['password']);
    }

    /** Asks host, (smtp: port), user and the hidden password; returns the fields, not yet stored. */
    public static function prompt($unit, callable $prompt)
    {
        $ask = function ($label, $hide = false) use ($prompt, $unit) {
            return trim((string) call_user_func($prompt, $label, 'login reads from a terminal, and stdin is not one',
                "the user runs `php " . dirname(__DIR__) . "/$unit/$unit.php login` in their own terminal", $hide));
        };
        $host = $ask("$unit server (e.g. " . ($unit === 'smtp' ? 'smtp' : $unit) . '.example.org): ');
        $port = self::PORT[$unit];
        if ($unit === 'smtp') {
            $typed = $ask('port, 465 (TLS) or 587 (STARTTLS) [465]: ');
            $port = $typed === '' ? 465 : (int) $typed;
            if (!in_array($port, [465, 587], true)) {
                throw new CliError('the port must be 465 or 587; nothing stored', 'port 25 is never used; run login again');
            }
        }
        $ports = MailTransport::PORTS + MailTransport::$extraPorts;
        MailTransport::endpoint(isset($ports[$port]) ? $ports[$port] : '', $host, $port);
        $user = $ask('user name (often the full address): ');
        $secret = (string) call_user_func($prompt, 'password (hidden; many providers need an app password here): ',
            'login reads from a terminal, and stdin is not one', "the user runs `php " . dirname(__DIR__) . "/$unit/$unit.php login` in their own terminal", true);
        if ($user === '' || $secret === '') {
            throw new CliError('an empty user name or password; nothing stored', 'run login again');
        }
        return ['host' => strtolower($host), 'port' => $port, 'user' => $user, 'password' => $secret];
    }

    /** Tries the login with $check(MailTransport), then stores it; a failed try stores nothing. */
    public static function verifyAndSave($dir, $unit, array $fields, callable $check)
    {
        call_user_func($check, self::transport($fields));
        SecretFile::write(self::file($dir, $unit), $fields, "$unit login");
        return self::status($fields) + ['stored' => true];
    }

    public static function status($d)
    {
        return $d === null ? ['login' => false] : ['login' => true, 'host' => $d['host'], 'port' => (int) $d['port'], 'user' => $d['user']];
    }
}
