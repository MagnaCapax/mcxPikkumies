<?php
/**
 * DrivesStore: what the drives tool keeps, per drive, under `local/drives/<KEY>/` (directories 0700, files 0600, a
 * symlinked directory refused): `smart.json` (the latest raw smartctl document), `events.jsonl` (verify start and
 * end, refusals worth keeping, verdicts and grades) and a running pass's files (`pass.log`, `pass.bb`, `pass.exit`,
 * `pass.lock`). KEY is the serial upper-cased and filtered to A-Z 0-9 . _ - (SATA and SAS print serials in
 * different case). The numeric history, one sample per `smart` call, goes to the shared telemetry store
 * (tools/system/telemetry, ADR-0084) when this Pikkumies has it; without it the history-based rule says
 * insufficient_history. Nothing here writes memory (ADR-0018): a verdict becomes a lesson record the agent saves.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class DrivesStore
{
    /** The fields sampled into the history: numbers only (the telemetry store keeps no text). */
    const SAMPLE = ['poh', 'realloc', 'pending', 'uncorrectable', 'reported_unc', 'crc'];

    private $root;
    private $telemetry;

    /** $telemetry: ['record' => fn(source, fields), 'series' => fn(source, field, days)]; null loads the shared store. */
    public function __construct($localDir, array $telemetry = null)
    {
        $this->root = rtrim($localDir, '/') . '/drives';
        $this->telemetry = $telemetry !== null ? $telemetry : self::sharedTelemetry(rtrim($localDir, '/'));
    }

    public static function key($serial)
    {
        $key = preg_replace('/[^A-Z0-9._-]/', '', strtoupper((string) $serial));
        if ($key === '' || $key === '.' || $key === '..') {
            throw new CliError('this drive reports no usable serial; nothing was kept for it', 'the drive is listed, but history and verify need a serial');
        }
        return $key;
    }

    /** The drive's directory, created 0700; a symlink at either level is refused (it could point anywhere). */
    public function dir($key)
    {
        foreach ([$this->root, "$this->root/$key"] as $d) {
            if (is_link($d)) {
                throw new CliError('refused: ' . Config::shown($d) . ' is a symlink', 'make it a plain directory; the drives tool never follows a link');
            }
            if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
                throw new CliError('could not create ' . Config::shown($d), 'check the permissions of ~/pikkumies/local');
            }
        }
        @chmod("$this->root/$key", 0700);
        return "$this->root/$key";
    }

    public function path($key, $name)
    {
        return $this->dir($key) . '/' . $name;
    }

    /** Written through a temporary file and a rename, so a reader never sees half a document. */
    public function saveSmart($key, array $raw)
    {
        $file = $this->path($key, 'smart.json');
        $tmp = $file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, Cli::json($raw) . "\n") !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }

    public function event($key, array $event)
    {
        $file = $this->path($key, 'events.jsonl');
        $new = !is_file($file);
        @file_put_contents($file, Cli::json(['ts' => time()] + $event) . "\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            @chmod($file, 0600);
        }
    }

    /** Every event of one drive, oldest first. */
    public function events($key)
    {
        $file = "$this->root/$key/events.jsonl";
        if (!is_file($file) || is_link($file)) {
            return [];
        }
        $out = [];
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $e = json_decode((string) $line, true);
            if (is_array($e)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Every drive key kept here (a plain directory with an events file). */
    public function keys()
    {
        $out = [];
        foreach ((array) glob("$this->root/*/events.jsonl") as $f) {
            $key = PathName::base(dirname($f));
            if (!is_link(dirname($f)) && preg_match('/^[A-Z0-9._-]+$/', $key) && $key !== '.' && $key !== '..') {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** The newest event whose `event` is one of $kinds, or null. */
    public function last($key, array $kinds)
    {
        foreach (array_reverse($this->events($key)) as $e) {
            if (isset($e['event']) && in_array($e['event'], $kinds, true)) {
                return $e;
            }
        }
        return null;
    }

    /** One history sample; never fails the call it rides along with. */
    public function sample($key, array $d)
    {
        if (empty($this->telemetry['record'])) {
            return;
        }
        try {
            call_user_func($this->telemetry['record'], self::source($key), array_intersect_key($d, array_flip(self::SAMPLE)));
        } catch (Throwable $e) {
            // history is a convenience: the screen is still right without it
        }
    }

    /** Reported-uncorrectable growth per hour since $since from the history; null without two samples (insufficient_history). */
    public function uncPerHour($key, $since)
    {
        if (empty($this->telemetry['series'])) {
            return null;
        }
        try {
            $rows = array_values(array_filter((array) call_user_func($this->telemetry['series'], self::source($key), 'reported_unc', 30), function ($r) use ($since) {
                return isset($r['ts']) && $r['ts'] >= $since - 3600;
            }));
        } catch (Throwable $e) {
            return null;
        }
        if (count($rows) < 2) {
            return null;
        }
        $hours = max(1, ($rows[count($rows) - 1]['ts'] - $rows[0]['ts']) / 3600);
        return ($rows[count($rows) - 1]['max'] - $rows[0]['min']) / $hours;
    }

    /** The telemetry source name: drive-<key> when it fits the store's name rule, else a stable hash of the key. */
    public static function source($key)
    {
        $s = 'drive-' . strtolower(str_replace('.', '_', $key));
        return preg_match('/^[a-z][a-z0-9_-]{0,31}\z/', $s) ? $s : 'drive-' . substr(sha1($key), 0, 16);
    }

    /** The shared store's record and series, when tools/system/telemetry ships in this Pikkumies (ADR-0084); else none. */
    private static function sharedTelemetry($localDir)
    {
        $file = dirname(__DIR__, 2) . '/system/telemetry/TelemetryLog.php';
        if (!is_file($file)) {
            return [];
        }
        require_once $file;
        return ['record' => function ($source, array $fields) use ($localDir) {
            return TelemetryLog::record($source, $fields, $localDir);
        }, 'series' => function ($source, $field, $days) use ($localDir) {
            return TelemetryLog::series($source, $field, $days, 'hour', $localDir);
        }];
    }
}
