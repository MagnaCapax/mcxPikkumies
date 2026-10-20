<?php
/**
 * MoveRunner: every outside call move makes, in one place so a test can replace each one.
 * - unit(): a torrent-client unit verb, here (`php <client>.php ...`) or on a service through its forced-command serve
 *   (`tool torrent-clients/<client> ...`, lib/Services.php run()); the JSON Lines answer decoded, `ok: false` thrown.
 * - rsync(): the destination's pull, the file list on stdin from a file, never in argv.
 * Processes start from an argv through lib/ToolProc.php line() and `exec` (PHP 7.3 proc_open takes a string); a unit
 * call that does not answer in time is killed with its process group gone with it (it spawns nothing long-lived).
 */
class MoveRunner
{
    const UNIT_TIMEOUT = 120;
    const MAX_PAGES_BYTES = 33554432; // 32 MiB of answers for one paged read: a 20 MiB .torrent in base64 fits

    public $unitsDir;
    /** Test seams, each null in production: */
    public $remoteFn = null;  // fn($nick, array $argv, $stdin, $timeout) => ['rc', 'stdout', 'stderr']
    public $localFn = null;   // fn(array $argv, $stdin, $timeout) => ['rc', 'stdout', 'stderr']
    public $serviceFn = null; // fn($nick) => the services.jsonl record
    public $sshFn = null;     // fn($nick) => [ssh program and options (argv words), host spec]
    public $rsyncFn = null;   // fn(array $argv, $listFile) => ['code', 'err']
    public $sleepFn = null;
    public $nowFn = null;

    public function __construct($unitsDir)
    {
        $this->unitsDir = rtrim($unitsDir, '/');
    }

    public function service($nick)
    {
        if ($this->serviceFn !== null) {
            return call_user_func($this->serviceFn, $nick);
        }
        self::needServices();
        return Services::get($nick);
    }

    /** ['records' => [...], 'status' => {...}] of one unit verb; $where is 'local' or a service nick. */
    public function unit($where, $client, array $args, $stdin = null, $timeout = self::UNIT_TIMEOUT)
    {
        $args = array_map('strval', $args);
        if ($where === 'local') {
            $argv = array_merge([PHP_BINARY, "{$this->unitsDir}/$client/$client.php"], $args);
            $r = $this->localFn !== null ? call_user_func($this->localFn, $argv, $stdin, $timeout) : MoveProc::proc($argv, $stdin, $timeout);
        } else {
            $argv = array_merge(['tool', "torrent-clients/$client"], $args);
            if ($this->remoteFn !== null) {
                $r = call_user_func($this->remoteFn, $where, $argv, $stdin, $timeout);
            } else {
                self::needServices();
                $r = Services::run($where, $argv, $stdin, $timeout);
            }
        }
        return MoveProc::decode($r, ($where === 'local' ? 'here' : $where) . " $client " . (isset($args[0]) ? $args[0] : ''));
    }

    /** Every record of a verb whose answer may be cut at the 16 KiB budget (lib/Cli.php): pages with --offset. */
    public function pages($where, $client, array $args)
    {
        $all = [];
        $offset = 0;
        $status = [];
        $bytes = 0;
        for ($n = 0; $n < 2000; $n++) {
            $r = $this->unit($where, $client, array_merge($args, ['--limit', '0', '--offset', (string) $offset]));
            $bytes += isset($r['bytes']) ? $r['bytes'] : 0;
            if ($bytes > self::MAX_PAGES_BYTES) { // a source that never stops answering cannot fill this box's memory
                throw new CliError("the answer of $where $client {$args[0]} grew past " . self::MAX_PAGES_BYTES . ' bytes; stopped', 'nothing was changed; check that box');
            }
            $all = array_merge($all, $r['records']);
            $status = $r['status'];
            if (empty($status['truncated']) || !isset($status['next_offset']) || (int) $status['next_offset'] <= $offset) {
                return ['records' => $all, 'status' => $status];
            }
            $offset = (int) $status['next_offset'];
        }
        throw new CliError('the answer did not end after 2000 pages', 'nothing was changed; check that box');
    }

    /** [ssh words up to and without the host, host spec] for rsync -e, from Services::sshArgv. */
    public function ssh($nick)
    {
        if ($this->sshFn !== null) {
            return call_user_func($this->sshFn, $nick);
        }
        self::needServices();
        $argv = Services::sshArgv($nick, ['rsync']);
        $cut = array_search('--', $argv, true);
        if ($cut === false || !isset($argv[$cut + 1])) {
            throw new CliError("cannot build the ssh command for $nick", 'run `services show ' . $nick . '` and check the service');
        }
        return [array_slice($argv, 0, $cut), (string) $argv[$cut + 1]];
    }

    public function rsync(array $argv, $listFile)
    {
        if ($this->rsyncFn !== null) {
            return call_user_func($this->rsyncFn, $argv, $listFile);
        }
        $err = $listFile . '.err';
        $p = proc_open('exec ' . ToolProc::line($argv), [0 => ['file', $listFile, 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']], $pipes);
        if (!is_resource($p)) {
            throw new CliError('could not start rsync', 'check that rsync is installed');
        }
        $code = proc_close($p); // rsync's own --timeout ends a stalled transfer and its ssh child
        $tail = trim((string) @file_get_contents($err, false, null, max(0, (int) @filesize($err) - 2000)));
        @unlink($err);
        return ['code' => $code, 'err' => $tail];
    }

    public function sleep($s)
    {
        if ($this->sleepFn !== null) {
            call_user_func($this->sleepFn, $s);
            return;
        }
        sleep((int) $s);
    }

    public function now()
    {
        return $this->nowFn !== null ? call_user_func($this->nowFn) : time();
    }

    private static function needServices()
    {
        if (!class_exists('Services')) {
            $f = dirname(__DIR__, 3) . '/lib/Services.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        if (!class_exists('Services')) {
            throw new CliError('the services list is not installed (lib/Services.php)', 'update Pikkumies; moves between boxes need the services tool');
        }
    }
}
