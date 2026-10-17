<?php
/**
 * PerfFs: the one file reader of the performance unit (ADR-0035, privacy rules P1 and P5 in README.md).
 * Every /proc and /sys read goes through read(), which
 *  - maps a logical path (/proc/..., /sys/...) to a test root when one is given (the test seam),
 *  - refuses a path with a glob character or a `..` segment, and has no listing function at all, so nothing
 *    can enumerate cgroupfs,
 *  - refuses a path that names another account's resource files, and the kernel's socket tables under
 *    /proc/net, whatever the caller asked for (it reads only your own account),
 *  - keeps a log of the logical paths it read and of the ones it refused (in memory; also appended to a file
 *    when a test passes one), the oracle of the privacy tests.
 */
class PerfFs
{
    const MAX_BYTES = 1048576; // one /proc or /sys file never needs more

    /** @var array logical prefix => real directory, longest first */
    private $roots;
    private $uid;
    private $logFile;
    public $read = [];
    public $refused = [];

    /** $roots: ['/proc' => dir, '/sys' => dir, ...]; $uid: this account's uid (the seam's, or the process euid). */
    public function __construct(array $roots = [], $uid = null, $logFile = null)
    {
        uksort($roots, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $this->roots = $roots;
        $this->uid = $uid !== null ? (int) $uid : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $this->logFile = $logFile;
    }

    public function uid()
    {
        return $this->uid;
    }

    /** The real path behind a logical one; null when the path is refused. */
    public function path($logical)
    {
        $logical = (string) $logical;
        $why = self::refusal($logical, $this->uid);
        if ($why !== null) {
            return null;
        }
        foreach ($this->roots as $prefix => $dir) {
            if ($logical === $prefix || strpos($logical, rtrim($prefix, '/') . '/') === 0) {
                return rtrim($dir, '/') . substr($logical, strlen(rtrim($prefix, '/')));
            }
        }
        return $logical;
    }

    /** Contents of a logical path, at most $max bytes, or null (missing, unreadable, refused). */
    public function read($logical, $max = self::MAX_BYTES)
    {
        $why = self::refusal((string) $logical, $this->uid);
        if ($why !== null) {
            $this->note('refused', $logical, $why);
            return null;
        }
        $real = $this->path($logical);
        if (!is_file($real) || !is_readable($real)) {
            return null;
        }
        $text = @file_get_contents($real, false, null, 0, max(1, (int) $max));
        if ($text === false) {
            return null;
        }
        $this->note('read', $logical);
        return $text;
    }

    /** Whether a logical path exists (a refused path never does). */
    public function exists($logical)
    {
        $real = $this->path($logical);
        return $real !== null && file_exists($real);
    }

    /**
     * Why a path is refused, or null. Public and static so other code and the tests can ask without reading.
     * The checks look at the path text only; nothing is listed or resolved to decide.
     */
    public static function refusal($logical, $uid)
    {
        if ($logical === '' || $logical[0] !== '/') {
            return 'not an absolute path';
        }
        if (strpbrk($logical, "*?[]{}\0") !== false) {
            return 'glob characters';
        }
        foreach (explode('/', $logical) as $seg) {
            if ($seg === '..') {
                return 'parent segment';
            }
            if (preg_match('/^user-(\d+)\.slice$/', $seg, $m) && (int) $m[1] !== (int) $uid) {
                return 'another account';
            }
        }
        if (preg_match('#^/proc/(?:self/|\d+/)?net/(?:tcp6?|udp6?|raw6?|unix)$#', $logical)) {
            return 'socket table';
        }
        return null;
    }

    private function note($kind, $logical, $why = '')
    {
        if ($kind === 'read') {
            $this->read[] = $logical;
        } else {
            $this->refused[] = ['path' => $logical, 'why' => $why];
        }
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, json_encode(['kind' => $kind, 'path' => $logical, 'why' => $why], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
