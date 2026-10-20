<?php
/**
 * UpstreamGit: an upstream project's recent commits without the API (ADR-0092). A treeless shallow bare clone in
 * local/upstream/<owner>__<name>.git (90 days of history, commits only; about 0.4 MB for PMSS, 9 MB for a busy project,
 * measured 2026-10-20) is searched offline with `git log -i --grep`. It is cloned again when older than 6 hours (a fresh
 * clone measured faster than a fetch of it) and removed when unused for 14 days. Without git, or when the clone fails,
 * the commits.atom feed (the last 20 commits) answers instead.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class UpstreamGit
{
    const FRESH = 21600;
    const UNUSED = 1209600;
    const DAYS = 90;
    const MAX_MIRRORS = 10;
    const MAX_BYTES = 104857600; // all mirrors together; the cache's own 20 MB cap is separate
    const NAME_RE = '#^[A-Za-z0-9-]+__[A-Za-z0-9._-]+\.git(\.(tmp|old)\d+)?$#';

    public static function dir()
    {
        return Upstream::localDir() . '/upstream';
    }

    /** ['records' => [...], 'source' => 'git'|'commits.atom', 'stale' => bool]. $days: 1 to 90. */
    public static function commits($repo, $grep, $days)
    {
        $now = Upstream::now();
        self::prune(self::dir(), $now);
        $git = ToolProc::which('git');
        if ($git !== null) {
            list($mirror, $stale) = self::mirror($repo, $git, $now);
            $log = $mirror !== '' ? self::log($git, $mirror, $repo, $grep, $days) : null;
            if ($log !== null) {
                return ['records' => $log, 'source' => 'git', 'stale' => $stale];
            }
        }
        $feed = UpstreamFeed::feed("$repo/commits.atom");
        if ($feed === null) {
            throw new CliError("could not read $repo's commits: the clone and the commits.atom feed both failed", 'check the owner/name; the project may be private, renamed or gone');
        }
        $records = [];
        foreach ($feed['entries'] as $e) {
            $hay = $e['title'] . ' ' . $e['excerpt'];
            if (($grep === '' || stripos($hay, $grep) !== false) && strtotime($e['updated']) >= $now - $days * 86400) {
                $sha = substr((string) PathName::base($e['url']), 0, 12);
                $records[] = ['commit' => $sha, 'date' => $e['updated'], 'subject' => $e['title'], 'url' => Upstream::WEB . "/$repo/commit/$sha"];
            }
        }
        return ['records' => $records, 'source' => 'commits.atom', 'stale' => $feed['stale']];
    }

    /** [path of the mirror, stale] or ['', false]: reused while fresh, cloned again into a temporary name and swapped in. */
    private static function mirror($repo, $git, $now)
    {
        $base = self::dir();
        $dir = "$base/" . str_replace('/', '__', $repo) . '.git';
        $fetched = (int) @filemtime("$dir/pikkumies-fetched");
        if (is_dir($dir) && $fetched > $now - self::FRESH) {
            @touch("$dir/pikkumies-used");
            return [$dir, false];
        }
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            return ['', false];
        }
        @chmod($base, 0700);
        $tmp = "$dir.tmp" . getmypid();
        self::removeTree($tmp, $base);
        // No stored credential and no askpass program is ever offered to the remote, not even on a 401.
        $argv = [$git, '-c', 'credential.helper=', '-c', 'core.askPass=', 'clone', '-q', '--bare', '--filter=tree:0', '--shallow-since=' . self::DAYS . '.days',
            '--single-branch', '--', Upstream::WEB . "/$repo.git", $tmp];
        $timeout = ToolProc::which('timeout');
        $r = ToolProc::run($timeout !== null ? array_merge([$timeout, '120'], $argv) : $argv, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '', 'SSH_ASKPASS' => '']);
        if ($r['code'] !== 0 || !is_dir($tmp)) {
            self::removeTree($tmp, $base);
            return is_dir($dir) ? [$dir, true] : ['', false]; // an older copy beats none
        }
        @touch("$tmp/pikkumies-fetched");
        @touch("$tmp/pikkumies-used");
        $old = "$dir.old" . getmypid();
        if (is_dir($dir) && !@rename($dir, $old)) {
            self::removeTree($tmp, $base);
            return [$dir, true];
        }
        if (!@rename($tmp, $dir)) {
            self::removeTree($tmp, $base);
            return ['', false];
        }
        self::removeTree($old, $base);
        return [$dir, false];
    }

    /** The newest 20 commits since $days ago, matching $grep (case-insensitive, a fixed string), or null when git fails. */
    private static function log($git, $mirror, $repo, $grep, $days)
    {
        $argv = [$git, '--git-dir=' . $mirror, 'log', '-n', '20', '--format=%H%x1f%cI%x1f%s', '--since=' . (int) $days . '.days.ago'];
        if ($grep !== '') {
            $argv = array_merge($argv, ['-i', '--fixed-strings', '--grep=' . $grep]);
        }
        $r = ToolProc::run($argv, ['GIT_TERMINAL_PROMPT' => '0']);
        if ($r['code'] !== 0) {
            return null;
        }
        $out = [];
        foreach (array_filter(explode("\n", $r['out']), 'strlen') as $line) {
            $f = explode("\x1f", $line);
            if (count($f) === 3 && preg_match('/^[0-9a-f]{40,64}$/', $f[0])) {
                // A 12-character hash in the link: Cli::cleanString takes a 16+ character token in a URL path for a tracker key.
                $out[] = ['commit' => substr($f[0], 0, 12), 'date' => $f[1], 'subject' => Upstream::cut($f[2], 200), 'url' => Upstream::WEB . "/$repo/commit/" . substr($f[0], 0, 12)];
            }
        }
        return $out;
    }

    /**
     * Remove mirrors unused for 14 days and temporary leftovers older than an hour; then, least recently used first,
     * mirrors beyond MAX_MIRRORS or beyond MAX_BYTES together.
     */
    public static function prune($base, $now)
    {
        $kept = [];
        foreach ((array) glob("$base/*.git*") as $d) {
            if (!is_dir($d) || is_link($d) || !preg_match(self::NAME_RE, PathName::base($d), $m)) {
                continue;
            }
            $temp = isset($m[1]) && $m[1] !== '';
            $used = (int) @filemtime(is_file("$d/pikkumies-used") ? "$d/pikkumies-used" : $d);
            if ($used < $now - ($temp ? 3600 : self::UNUSED)) {
                self::removeTree($d, $base);
            } elseif (!$temp) {
                $kept[$d] = $used;
            }
        }
        arsort($kept);
        $bytes = 0;
        $n = 0;
        foreach (array_keys($kept) as $d) {
            $bytes += self::size($d);
            if (++$n > self::MAX_MIRRORS || $bytes > self::MAX_BYTES) {
                self::removeTree($d, $base);
            }
        }
    }

    /** Bytes of the regular files under $dir, symlinks not followed. */
    public static function size($dir)
    {
        $n = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $n += $f->isLink() ? 0 : (int) $f->getSize();
        }
        return $n;
    }

    /** Delete $path, a mirror directory directly under $base, without following symlinks; anything else is refused. */
    public static function removeTree($path, $base)
    {
        $realBase = realpath($base);
        if (!is_dir($path) || is_link($path) || $realBase === false || dirname((string) realpath($path)) !== $realBase || !preg_match(self::NAME_RE, PathName::base($path))) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (is_dir($p) && !is_link($p)) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
