<?php
/**
 * HermesConfigBackup: the record of every change Pikkumies makes to the user's Hermes files (ADR-0062). Before a change,
 * a 0600 copy of the old bytes goes to local/backups/hermes/ (every copy kept: rare, a few KB each); after it, or after a
 * failure, one JSON line goes to local/state/hermes-config.jsonl with the action, file, key, the sha256 of the bytes
 * before and after and the copy's path, never a value (.env holds keys). Doctor reads the last line.
 */
require_once __DIR__ . '/PathName.php';

class HermesConfigBackup
{
    /** Test seam: where notes go (null = STDERR). */
    public static $notes = null;

    public static function logFile($local)
    {
        return "$local/state/hermes-config.jsonl";
    }

    public static function dir($local)
    {
        return "$local/backups/hermes";
    }

    /** Back up $file, then replace it with $new through a temp file (its mode kept); logged. */
    public static function write($local, $file, $action, $new, $detail)
    {
        $before = (string) file_get_contents($file);
        $backup = self::copy($local, $file, $before);
        try {
            if ($backup === null) {
                throw new Exception('no backup could be written; nothing changed');
            }
            HermesConfig::put($file, $new, fileperms($file) & 0777);
        } catch (Exception $x) {
            return self::fail($local, PathName::base($file), null, $x->getMessage(), $backup);
        }
        self::log($local, $action, $file, null, $before, $new, $backup, $detail);
        return true;
    }

    /** A new 0600 copy of $bytes in backups/hermes/<name>.<UTC time>.<pid>[.n]; never over an existing name or link. */
    public static function copy($local, $file, $bytes)
    {
        $dir = self::dir($local);
        @mkdir($dir, 0700, true);
        $base = $dir . '/' . ltrim(PathName::base($file), '.') . '.' . gmdate('Ymd\THis\Z') . '.' . getmypid();
        for ($n = 1; $n < 100; $n++) {
            $path = $n === 1 ? $base : "$base.$n";
            $f = @fopen($path, 'x'); // fails when the name exists, a link included
            if ($f !== false) {
                $ok = fwrite($f, $bytes) === strlen($bytes) && fclose($f) && chmod($path, 0600);
                return $ok ? $path : null;
            }
        }
        return null;
    }

    /** One JSON line per change or failure; sha256 of the bytes, never the bytes. */
    public static function log($local, $action, $file, $key, $before, $after, $backup, $detail)
    {
        $line = json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'action' => $action, 'file' => PathName::base($file), 'key' => $key,
            'sha256_before' => $before === null ? null : hash('sha256', $before), 'sha256_after' => $after === null ? null : hash('sha256', $after),
            'backup' => $backup === null ? null : Config::shown($backup), 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n";
        @mkdir("$local/state", 0700, true);
        @file_put_contents(self::logFile($local), $line, FILE_APPEND | LOCK_EX);
    }

    /** For doctor: whose file is which, the migration, the last change Pikkumies made, where the copies are. */
    public static function doctorLine($local)
    {
        $lines = @file(self::logFile($local), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last = $lines ? json_decode((string) end($lines), true) : null;
        $home = "$local/hermes";
        return 'hermes config: ' . Config::shown("$home/config.yaml") . ' is yours (Hermes\' own edits stay); pinned: ' . Config::shown("$local/hermes-managed/config.yaml")
            . (is_file("$home/config.yaml") && HermesUserConfig::migrationDue("$home/config.yaml") ? '; old layout, moved over at the next start' : '')
            . (is_dir('/etc/hermes') ? '; /etc/hermes is not read (HERMES_MANAGED_DIR replaces it)' : '')
            . "\n          last change by Pikkumies: " . (is_array($last) ? $last['action'] . ' ' . $last['file'] . ' at ' . $last['ts'] . (isset($last['detail']) ? ' (' . $last['detail'] . ')' : '') : 'none')
            . '; copies: ' . Config::shown(self::dir($local)) . ' and Hermes\' ' . Config::shown("$home/backups/config")
            . '; a file Hermes cannot read: `pikkumies run config check`';
    }

    public static function fail($local, $file, $key, $why, $backup = null)
    {
        self::log($local, 'error', $file, $key, null, null, $backup, $why);
        self::note("Hermes config ($file" . ($key ? " $key" : '') . "): $why; tried again at the next start");
        return false;
    }

    public static function note($text)
    {
        fwrite(self::$notes !== null ? self::$notes : STDERR, "hermes config: $text\n");
    }

}
