<?php
/**
 * StorageScan: `topdirs` (du) and `bigfiles` (find), bounded for a shared server (README.md, safety
 * rule 2): `nice -n 19 ionice -c3 timeout 300`, one filesystem (-x / -xdev, so FUSE mounts are
 * not walked), output read from the pipe line by line with a row cap, one scan at a time per
 * account (flock), and only the small ranked result cached under local/storage/ for ten minutes.
 * Nothing is written into the scanned tree; a cache or lock that cannot be written (full quota)
 * is skipped, never an error.
 */
class StorageScan
{
    const TIMEOUT = 300;
    const MAX_LINES = 200000; // du/find lines read; more stops the scan (result marked partial)
    const TTL = 600;

    private $dir;

    public function __construct($localDir)
    {
        $this->dir = rtrim($localDir, '/') . '/storage';
    }

    public function topdirs(array $opt)
    {
        list($home, $path) = $this->target($opt);
        $depth = isset($opt['depth']) ? (string) $opt['depth'] : '1';
        if (!in_array($depth, ['1', '2', '3'], true)) {
            throw new CliError('--depth must be 1, 2 or 3', 'deeper listings cost more disk reads; start with 1');
        }
        $inodes = !empty($opt['inodes']);
        return $this->cached(['topdirs', $path, $depth, $inodes], $opt, function () use ($path, $depth, $inodes) {
            $rows = [];
            // -0: NUL ends a record (GNU du --help)
            $du = ['du', '-0', '-x', '--max-depth=' . $depth, $inodes ? '--inodes' : '-k', '--', $path];
            $r = $this->scan($du, function ($line) use (&$rows) {
                if (preg_match('/^(\d+)\t(.+)$/s', $line, $m)) {
                    $rows[] = [(int) $m[1], $m[2]];
                }
            });
            $unit = $inodes ? 1 : 1024; // du -k prints KiB; --inodes prints counts (GNU coreutils du --help)
            $total = 0;
            foreach ($rows as $row) {
                $total = $row[1] === $path ? $row[0] * $unit : $total;
            }
            usort($rows, function ($a, $b) {
                return $b[0] - $a[0];
            });
            $field = $inodes ? 'files' : 'bytes';
            $records = [];
            foreach ($rows as $row) {
                if ($row[1] !== $path) {
                    $share = $total > 0 ? round($row[0] * $unit / $total, 3) : null;
                    $records[] = ['path' => $row[1], $field => $row[0] * $unit, 'share' => $share];
                }
            }
            return ['records' => $records, 'status' => $r + ['scanned' => $path, "total_$field" => $total,
                'note' => 'du counts every file under the path, whoever owns it; the quota counts only this account\'s files']];
        });
    }

    public function bigfiles(array $opt)
    {
        list($home, $path) = $this->target($opt);
        $min = Quota::bytes(isset($opt['min-size']) ? (string) $opt['min-size'] : '1G');
        if (!$min || $min < 1048576) {
            throw new CliError('--min-size must be a size of at least 1M, e.g. 500M or 2G', 'default 1G');
        }
        return $this->cached(['bigfiles', $path, $min], $opt, function () use ($path, $min) {
            $rows = [];
            $r = $this->scan(['find', $path, '-xdev', '-type', 'f', '-size', '+' . ($min - 1) . 'c', '-printf', '%s\t%n\t%T@\t%p\0'],
                function ($line) use (&$rows) {
                if (preg_match('/^(\d+)\t(\d+)\t([0-9.]+)\t(.+)$/s', $line, $m)) {
                    $rows[] = ['path' => $m[4], 'bytes' => (int) $m[1], 'links' => (int) $m[2], 'modified_at' => (int) $m[3],
                        'frees_space' => (int) $m[2] === 1];
                }
            });
            usort($rows, function ($a, $b) {
                return $b['bytes'] - $a['bytes'];
            });
            return ['records' => $rows, 'status' => $r + ['scanned' => $path, 'min_bytes' => $min,
                'note' => 'links > 1: other hard links (an *arr import, a cross-seed) keep the data, so deleting this name frees nothing']];
        });
    }

    private function target(array $opt)
    {
        $home = HomePath::home();
        $explicit = isset($opt['path']) && is_string($opt['path']);
        return [$home, HomePath::dir($explicit ? $opt['path'] : $home, $home, $explicit)];
    }

    /** Runs one bounded scan; returns status fields (partial, stopped_by). */
    private function scan(array $argv, callable $onLine)
    {
        ToolProc::need($argv[0], 'it comes with the base system (coreutils / findutils); ask the provider');
        $lock = $this->lock();
        $n = 0;
        $r = ToolProc::run(ToolProc::gentle($argv, self::TIMEOUT), [], function ($line) use (&$n, $onLine) {
            if (++$n > self::MAX_LINES) {
                return false;
            }
            call_user_func($onLine, $line);
            return true;
        }, null, "\0"); // du -0 and find -printf ...\0: a name holding a newline cannot forge a row
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $status = ['partial' => $r['stopped'] || $r['code'] !== 0];
        if ($r['stopped']) {
            $status['stopped_by'] = 'row cap (' . self::MAX_LINES . ' lines); scan a smaller --path';
        } elseif ($r['code'] === 124) {
            $status['stopped_by'] = 'time limit (' . self::TIMEOUT . ' s); scan a smaller --path';
        } elseif ($r['code'] !== 0) {
            $status['stopped_by'] = 'some directories could not be read: ' . ToolText::tail($r['err']);
        }
        return $status;
    }

    /** One scan per account at a time; a lock file that cannot be created (full quota) is skipped. */
    private function lock()
    {
        @mkdir($this->dir, 0700, true);
        $fh = @fopen($this->dir . '/scan.lock', 'c');
        if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new CliError('another scan is running for this account; nothing started',
                'wait for it to finish, then run the verb again');
        }
        return $fh ?: null;
    }

    private function cached(array $key, array $opt, callable $fresh)
    {
        $file = $this->dir . '/cache-' . substr(sha1(json_encode($key)), 0, 16) . '.json';
        $hit = empty($opt['fresh']) && is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($hit) && isset($hit['at'], $hit['records'], $hit['status']) && $hit['at'] > time() - self::TTL) {
            return ['records' => $hit['records'], 'status' => $hit['status'] + ['cached' => true, 'scanned_at' => $hit['at']]];
        }
        $res = call_user_func($fresh);
        $res['records'] = array_slice($res['records'], 0, 200); // the ranked top only
        $tmp = $file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, json_encode(['at' => time(), 'records' => $res['records'], 'status' => $res['status']])) === false
            || !@rename($tmp, $file)) {
            @unlink($tmp); // no cache this time: a full quota must not break the scan
        }
        $res['status'] += ['cached' => false, 'scanned_at' => time()];
        return $res;
    }
}
