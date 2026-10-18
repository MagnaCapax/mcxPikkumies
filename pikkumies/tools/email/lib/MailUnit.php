<?php
/**
 * MailUnit: what every email unit loads (ADR-0053), and the small state helpers they share: a private JSON state
 * file (written to a temporary file and renamed, 0600 in a 0700 directory) and the run log
 * local/logs/email/YYYYMMDD.jsonl (counts, bytes and seconds only; never addresses, subjects or text).
 */
$mailLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'SecretFile', 'HomePath', 'Quota', 'Host', 'Http', 'HermesInstall'] as $class) {
    require_once "$mailLib/$class.php";
}
foreach (['MailTransport', 'MailImap', 'MailParse', 'MailPath', 'Maildir', 'MailLogin'] as $class) {
    require_once __DIR__ . "/$class.php";
}

class MailUnit
{
    /** A JSON state file, or [] when there is none or it is unreadable. */
    public static function readState($file)
    {
        $d = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file, false, null, 0, 16777216), true) : null;
        return is_array($d) ? $d : [];
    }

    public static function writeState($file, array $data)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        $tmp = $file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false && @rename($tmp, $file);
        umask($old);
        if (!$ok) {
            @unlink($tmp);
            throw new CliError("cannot write $file", "check that $dir is writable");
        }
    }

    /**
     * One line in local/logs/email/YYYYMMDD.jsonl (UTC); a log that cannot be written never fails the verb. A new
     * day's file prunes files older than sessions.retentionDays (0 = keep forever), as the command log and the halt
     * guard log do.
     */
    public static function log($stateDir, array $fields)
    {
        $dir = dirname(rtrim($stateDir, '/')) . '/logs/email';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
        $new = !is_file($file);
        $old = umask(0077);
        @file_put_contents($file, json_encode(['ts' => time()] + $fields, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        umask($old);
        $config = Config::load();
        $days = isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0;
        if ($new && $days > 0) {
            $cut = gmdate('Ymd', time() - $days * 86400);
            foreach ((array) glob($dir . '/[0-9]*.jsonl') as $f) {
                if (basename($f, '.jsonl') < $cut) {
                    @unlink($f);
                }
            }
        }
    }

    /** --limit with a per-call $cap: default Cli::LIMIT; 0 (or less) means as many as one call takes, as in Cli. */
    public static function limit(array $opt, $cap)
    {
        $n = isset($opt['limit']) ? (int) $opt['limit'] : Cli::LIMIT;
        return $n <= 0 ? $cap : min($cap, $n);
    }

    /** The positional uids of a verb, at least one. */
    public static function uids(array $args)
    {
        if (!$args) {
            throw new CliError('no uid given', 'give the uid that list or search printed');
        }
        return array_map(['MailImap', 'uid'], $args);
    }
}
