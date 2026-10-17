<?php
/**
 * PerfProbe: how the performance unit runs things (ADR-0035, README.md "Runner").
 *  - Every program runs through ToolProc::run with an argv (never a shell string), at the lowest CPU priority,
 *    with `timeout -k 2 <s>` in front so a stuck program is killed, children included. Walks (find) use scan
 *    mode, the idle I/O class; probes use measure mode, best-effort level 7, so they measure the disk instead
 *    of waiting behind everyone. Every argv run is kept in $calls (and a test's call log).
 *  - One run at a time per account: a non-blocking lock on local/performance/run.lock.
 *  - Probe files go to local/performance/scratch/<pid>/ (0700; files 0600, created exclusively), removed by
 *    the run that made them; the next run removes directories whose process is gone. Never /tmp: it is outside
 *    the account's quota.
 *  - The cost of each run (wall, children's CPU, bytes, packets) and its classes go to local/performance/runs.jsonl
 *    (0600, never sent anywhere), keyed by a hash of the machine fingerprint, so a move to another server shows.
 *  - The one PSI parser (cpu some, io full, memory full; avg10 and avg60) and the gate that refuses heavy tests
 *    on a server under pressure.
 */
$perfStorageLib = dirname(__DIR__, 2) . '/storage/lib/ToolProc.php';
require_once $perfStorageLib; // ToolProc stays in storage/lib; backup requires it the same way (tools/backup/lib/Backup.php)
require_once __DIR__ . '/PerfLimits.php';
require_once __DIR__ . '/PerfFs.php';

/** A bad argument: the dispatcher prints it like any error and exits 2 (usage), as Cli does for unknown options. */
class PerfUsageError extends CliError
{
}

class PerfProbe
{
    /** @var PerfFs */
    public $fs;
    public $uid;
    public $home;
    /** Every argv this run started, in order (the timeout prefix included). */
    public $calls = [];
    /** Router class ids this run found, and [check, reason] pairs it skipped: the verb fills them, finish() logs them. */
    public $classes = [];
    public $skipped = [];
    private $localDir;
    private $callLog;
    private $fingerprintSeam;
    private $lock = null;
    private $scratch = null;
    private $t0;
    private $ru0;
    private $cost = ['bytes_written' => 0, 'bytes_read_direct' => 0, 'packets_sent' => 0, 'bytes_downloaded' => 0];

    /**
     * $ctx (test seams; every key optional): local_dir, uid, home, roots (PerfFs logical => real), read_log,
     * call_log, fingerprint. Nothing is read from the environment except HOME when no seam gives it.
     */
    public function __construct(array $ctx = [])
    {
        $this->uid = isset($ctx['uid']) ? (int) $ctx['uid'] : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $this->home = isset($ctx['home']) ? (string) $ctx['home'] : (string) getenv('HOME');
        $this->localDir = isset($ctx['local_dir']) ? (string) $ctx['local_dir'] : Config::localDir();
        $this->fs = new PerfFs(isset($ctx['roots']) ? (array) $ctx['roots'] : [], $this->uid, isset($ctx['read_log']) ? $ctx['read_log'] : null);
        $this->callLog = isset($ctx['call_log']) ? (string) $ctx['call_log'] : null;
        $this->fingerprintSeam = isset($ctx['fingerprint']) ? (string) $ctx['fingerprint'] : null;
        $this->t0 = microtime(true);
        $this->ru0 = self::childrenCpu();
    }

    /** local/performance/, created 0700. */
    public function stateDir()
    {
        $dir = $this->localDir . '/performance';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError('cannot create ' . $dir, 'check that the account can write its own pikkumies/local/ directory');
        }
        @chmod($dir, 0700);
        return $dir;
    }

    /** Takes the one-run-at-a-time lock; false when another run holds it. The kernel releases it when the process ends. */
    public function lock()
    {
        if ($this->lock !== null) {
            return true;
        }
        $file = $this->stateDir() . '/run.lock';
        $old = umask(077);
        $h = @fopen($file, 'c');
        umask($old);
        if ($h === false) {
            throw new CliError('cannot open the run lock', 'check that the account can write its own pikkumies/local/ directory');
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return false;
        }
        $this->lock = $h;
        return true;
    }

    /**
     * Removes scratch directories left by runs that died (their process is gone). Own directories only: a numeric
     * name, a real directory (not a link), owned by this account; links inside are removed, never followed.
     * Returns how many were removed.
     */
    public function sweep()
    {
        $root = $this->stateDir() . '/scratch';
        if (!is_dir($root) || is_link($root)) {
            return 0;
        }
        $removed = 0;
        foreach ((array) @scandir($root) as $name) {
            $dir = "$root/$name";
            if (!ctype_digit((string) $name) || (int) $name === getmypid() || is_link($dir) || !is_dir($dir) || @fileowner($dir) !== $this->uid) {
                continue;
            }
            if (self::pidAlive((int) $name)) {
                continue;
            }
            self::removeTree($dir);
            $removed += is_dir($dir) ? 0 : 1;
        }
        return $removed;
    }

    /** This run's scratch directory (0700), created on first use and removed when the run ends. */
    public function scratch()
    {
        if ($this->scratch !== null) {
            return $this->scratch;
        }
        $root = $this->stateDir() . '/scratch';
        if (is_link($root)) {
            throw new CliError('local/performance/scratch is a link; nothing was written', 'remove the link; the tool makes the directory itself');
        }
        if (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root)) {
            throw new CliError('cannot create the scratch directory', 'check free quota and that the account can write pikkumies/local/');
        }
        @chmod($root, 0700);
        $dir = $root . '/' . getmypid();
        if (file_exists($dir) || is_link($dir)) {
            self::removeTree($dir); // left by an earlier process with this pid, which is gone (this one is alive)
        }
        if (!@mkdir($dir, 0700)) {
            throw new CliError('cannot create the scratch directory', 'check free quota and that the account can write pikkumies/local/');
        }
        @chmod($dir, 0700);
        $this->scratch = $dir;
        register_shutdown_function([$this, 'cleanup']);
        return $dir;
    }

    /** A new, empty file in the scratch directory, 0600, created exclusively (an existing name is an error). */
    public function scratchFile($name)
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $name) || $name === '.' || $name === '..') {
            throw new CliError('bad scratch file name', 'internal: letters, digits, dot, dash and underscore only');
        }
        $path = $this->scratch() . '/' . $name;
        $old = umask(077);
        $h = @fopen($path, 'x');
        umask($old);
        if ($h === false) {
            throw new CliError("scratch file $name already exists or cannot be created", 'another probe in this run used the name');
        }
        fclose($h);
        @chmod($path, 0600);
        return $path;
    }

    /** Removes this run's scratch directory. Called at shutdown; safe to call twice. */
    public function cleanup()
    {
        if ($this->scratch !== null && is_dir($this->scratch)) {
            self::removeTree($this->scratch);
        }
        $this->scratch = null;
    }

    /** nice 19 + idle I/O class + timeout -k 2 <s>: for walks (find), which may take as long as they need to. */
    public static function scanArgv(array $argv, $seconds)
    {
        return array_merge(['nice', '-n', (string) PerfLimits::SCAN_NICE, 'ionice', '-c3'], self::timeoutPrefix($seconds), $argv);
    }

    /** nice 19 + best-effort I/O level 7 + timeout -k 2 <s>: for probes, which must measure, so not the idle class. */
    public static function measureArgv(array $argv, $seconds)
    {
        return array_merge(['nice', '-n', (string) PerfLimits::SCAN_NICE, 'ionice', '-c2', '-n' . PerfLimits::MEASURE_IO_LEVEL], self::timeoutPrefix($seconds), $argv);
    }

    private static function timeoutPrefix($seconds)
    {
        $s = (int) $seconds;
        if ($s < 1) {
            throw new CliError('every program needs a time limit', 'internal: pass the seconds from PerfLimits');
        }
        return ['timeout', '-k', (string) PerfLimits::KILL_AFTER_S, (string) $s];
    }

    public function scan(array $argv, $seconds, $onLine = null)
    {
        return $this->exec(self::scanArgv($argv, $seconds), 'scan', $onLine);
    }

    public function measure(array $argv, $seconds, $onLine = null)
    {
        return $this->exec(self::measureArgv($argv, $seconds), 'measure', $onLine);
    }

    /**
     * ToolProc::run plus: io_class (printed with probe results), wall_s, timed_out (timeout sent TERM: 124) and
     * stuck_io (it had to send KILL: 137, typically a probe waiting on the disk).
     */
    private function exec(array $argv, $mode, $onLine)
    {
        foreach (['nice', 'ionice', 'timeout'] as $p) {
            ToolProc::need($p, 'coreutils and util-linux provide it; the performance tool never runs a program without it');
        }
        $this->calls[] = $argv;
        if ($this->callLog !== null) {
            @file_put_contents($this->callLog, json_encode($argv, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }
        $t = microtime(true);
        $r = ToolProc::run($argv, ['LC_ALL' => 'C'], $onLine);
        $r['wall_s'] = round(microtime(true) - $t, 3);
        $r['io_class'] = $mode === 'scan' ? 'idle' : 'best-effort/' . PerfLimits::MEASURE_IO_LEVEL;
        $r['timed_out'] = in_array($r['code'], [124, 137], true);
        $r['stuck_io'] = $r['code'] === 137;
        return $r;
    }

    /** Adds to a cost counter: bytes_written, bytes_read_direct, packets_sent, bytes_downloaded. */
    public function addCost($key, $n)
    {
        if (!array_key_exists($key, $this->cost)) {
            throw new CliError("unknown cost counter $key", 'internal: ' . implode(', ', array_keys($this->cost)));
        }
        $this->cost[$key] += (int) $n;
    }

    /** The cost record: wall_s, cpu_s (finished children's user + system time), and the counters. */
    public function cost()
    {
        return ['wall_s' => round(microtime(true) - $this->t0, 3), 'cpu_s' => round(max(0, self::childrenCpu() - $this->ru0), 3)] + $this->cost;
    }

    /**
     * Appends one line to local/performance/runs.jsonl (0600): verb, fingerprint hash, the classes and skipped checks
     * the verb put in $classes and $skipped, cost. The dispatcher calls it once per run, also when the verb failed.
     */
    public function finish($verb)
    {
        $classes = $this->classes;
        $skipped = $this->skipped;
        $file = $this->stateDir() . '/runs.jsonl';
        $line = json_encode(['ts' => gmdate('c'), 'verb' => (string) $verb, 'fingerprint' => $this->fingerprint(),
            'classes' => array_values($classes), 'skipped' => array_values($skipped), 'cost' => $this->cost()], JSON_UNESCAPED_SLASHES) . "\n";
        $old = umask(077);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        umask($old);
        @chmod($file, 0600);
    }

    /** 16 hex characters of sha256(Detect::fingerprint): equal on the same machine and account, without naming either. */
    public function fingerprint()
    {
        $raw = $this->fingerprintSeam !== null ? $this->fingerprintSeam : Detect::fingerprint($this->uid);
        return substr(hash('sha256', $raw), 0, 16);
    }

    /**
     * Server-wide pressure: ['cpu' => [level some, avg10, avg60], 'io' => [full ...], 'memory' => [full ...]].
     * A missing or unreadable file (PSI off, an old kernel) gives null values: unknown, never 0.
     */
    public function psi()
    {
        $out = [];
        foreach (['cpu' => 'some', 'io' => 'full', 'memory' => 'full'] as $res => $level) {
            $p = self::parsePsi((string) $this->fs->read("/proc/pressure/$res"));
            $out[$res] = ['level' => $level, 'avg10' => isset($p[$level]) ? $p[$level]['avg10'] : null, 'avg60' => isset($p[$level]) ? $p[$level]['avg60'] : null];
        }
        return $out;
    }

    /** A pressure file -> ['some' => ['avg10' => f, 'avg60' => f], 'full' => [...]]; lines that do not parse are left out. */
    public static function parsePsi($text)
    {
        $out = [];
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(some|full)\s+avg10=([0-9]+(?:\.[0-9]+)?)\s+avg60=([0-9]+(?:\.[0-9]+)?)\b/', trim($line), $m)) {
                $out[$m[1]] = ['avg10' => (float) $m[2], 'avg60' => (float) $m[3]];
            }
        }
        return $out;
    }

    /**
     * Why a heavy test must not start now, or null. Light probes are never gated. An unknown value does not refuse
     * (PSI is off on some kernels); the heavy verb shows the PSI it saw beside its result.
     */
    public static function heavyGate(array $psi)
    {
        $why = [];
        $io = isset($psi['io']) ? $psi['io'] : [];
        if (isset($io['avg10']) && $io['avg10'] >= PerfLimits::PSI_IO_FULL_AVG10) {
            $why[] = 'disk pressure (io full avg10 ' . $io['avg10'] . ' >= ' . PerfLimits::PSI_IO_FULL_AVG10 . ')';
        }
        if (isset($io['avg60']) && $io['avg60'] >= PerfLimits::PSI_IO_FULL_AVG60) {
            $why[] = 'sustained disk pressure (io full avg60 ' . $io['avg60'] . ' >= ' . PerfLimits::PSI_IO_FULL_AVG60 . ')';
        }
        if (isset($psi['memory']['avg10']) && $psi['memory']['avg10'] >= PerfLimits::PSI_MEM_FULL_AVG10) {
            $why[] = 'memory pressure (memory full avg10 ' . $psi['memory']['avg10'] . ' >= ' . PerfLimits::PSI_MEM_FULL_AVG10 . ')';
        }
        if (isset($psi['cpu']['avg10']) && $psi['cpu']['avg10'] >= PerfLimits::PSI_CPU_SOME_AVG10) {
            $why[] = 'CPU pressure (cpu some avg10 ' . $psi['cpu']['avg10'] . ' >= ' . PerfLimits::PSI_CPU_SOME_AVG10 . ')';
        }
        return $why ? 'the server is busy now: ' . implode('; ', $why) . '; a load test would add to it and measure little' : null;
    }

    /**
     * Whether a process of this account with $pid exists. A pid now held by another account (EPERM, or hidden by
     * hidepid) means the run that made the directory is gone, so it counts as dead.
     */
    private static function pidAlive($pid)
    {
        if ($pid <= 0) {
            return false;
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir("/proc/$pid");
    }

    /** Removes $dir and what is in it without following links (a link is removed itself). */
    private static function removeTree($dir)
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree("$dir/$name");
            }
        }
        @rmdir($dir);
    }

    private static function childrenCpu()
    {
        $r = function_exists('getrusage') ? getrusage(1) : [];
        if (!$r) {
            return 0.0;
        }
        return (float) $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + (float) $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
    }
}
