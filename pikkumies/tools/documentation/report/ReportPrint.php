<?php
/**
 * ReportPrint: prints a report's HTML to PDF with the server's own Chromium (Debian's package, which the PMSS updater
 * installs where it fits; PMSS ADR-0123), never a browser downloaded into the account (ADR-0091).
 *
 * The browser runs as `setsid unshare --map-root-user --net <chromium> --headless ...`: its own session, so a timeout
 * kills the whole process group, and a network namespace with no interface but a down loopback, so the page cannot
 * reach anything even if the HTML tried (the page's CSP already forbids every load). Without unshare, or where user
 * namespaces are off, it runs without the namespace but with the same network-off flags. The profile directory is a
 * fresh local/state/tmp/report-XXXX (0700), removed afterwards. chromium-headless-shell is preferred when installed
 * (the print-only build), then chromium. Every command is an argv (ToolProc::line); nothing comes from report text.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class ReportPrint
{
    const BROWSERS = ['chromium-headless-shell', 'chromium', 'chromium-browser'];
    /** Chromium prints CSS page-margin boxes (the running header, "Page X of Y") from this major version. */
    const MARGIN_BOXES = 131;
    /** Flags that keep the browser off the network and quiet, used in both modes. */
    const QUIET = ['--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-extensions',
        '--disable-default-apps', '--no-first-run', '--no-default-browser-check', '--disable-crash-reporter',
        '--host-resolver-rules=MAP * ~NOTFOUND', '--proxy-server=127.0.0.1:9', '--proxy-bypass-list=<-loopback>'];

    /** Absolute path of the browser to use: $given (must be an executable file) or the first of BROWSERS on PATH. */
    public static function browser($given = null)
    {
        if ($given !== null && $given !== '') {
            if (substr($given, 0, 1) !== '/' || !is_file($given) || !is_executable($given)) {
                throw new CliError("--browser $given is not an executable file given by its absolute path", 'name the Chromium binary, e.g. --browser /usr/bin/chromium');
            }
            return $given;
        }
        foreach (self::BROWSERS as $name) {
            $p = ToolProc::which($name);
            if ($p !== null) {
                return $p;
            }
        }
        return null;
    }

    /** Major version from `chromium --version` output ("Chromium 141.0.7390.54 built on Debian"), 0 when unknown. */
    public static function major($out)
    {
        return preg_match('/(?:Chromium|Chrome|Google Chrome[^0-9]*)\s+(\d{2,4})\./', (string) $out, $m) ? (int) $m[1] : 0;
    }

    /** The command line; $netns false leaves out unshare (the flags still keep the browser off the network). */
    public static function argv($browser, $profile, $html, $pdf, $netns)
    {
        return array_merge(['setsid'], $netns ? ['unshare', '--map-root-user', '--net'] : [], [$browser, '--headless', '--no-sandbox', '--disable-gpu',
            '--user-data-dir=' . $profile, '--disk-cache-dir=' . $profile . '/cache', '--no-pdf-header-footer', '--print-to-pdf-no-header',
            '--print-to-pdf=' . $pdf], self::QUIET, ['file://' . $html]);
    }

    /** True when an unprivileged user and network namespace can be made here (the probe takes a few milliseconds). */
    public static function canUnshare()
    {
        if (ToolProc::which('unshare') === null) {
            return false;
        }
        $r = self::run(['unshare', '--map-root-user', '--net', 'true'], 10, sys_get_temp_dir());
        return $r['code'] === 0;
    }

    /**
     * Prints $html to $pdf. Returns ['ok', 'mode' (netns|flags), 'major', 'pages', 'error', 'stderr']. $tmpDir: the
     * account's local/state/tmp (made 0700 here).
     */
    public static function pdf($browser, $html, $pdf, $tmpDir, $timeout = 90)
    {
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) {
            throw new CliError('cannot create ' . Config::shown($tmpDir), 'check that the pikkumies/local directory is yours and writable');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (is_link($tmpDir) || !is_dir($tmpDir) || @fileowner($tmpDir) !== $uid) {
            throw new CliError(Config::shown($tmpDir) . ' is not a directory of this account (a symlink or another owner)', 'remove it; Pikkumies creates it again');
        }
        @chmod($tmpDir, 0700);
        $profile = $tmpDir . '/report-' . bin2hex(random_bytes(6));
        if (!@mkdir($profile, 0700)) {
            throw new CliError('cannot create ' . Config::shown($profile), 'check the space and permissions of ' . Config::shown($tmpDir));
        }
        try {
            $ver = self::run([$browser, '--version'], 10, $profile);
            if ($ver['timedOut']) { // a browser that cannot print its version will not print a page either
                return ['ok' => false, 'mode' => '', 'major' => 0, 'pages' => 0, 'error' => PathName::base($browser) . ' did not answer --version within 10s and was stopped', 'stderr' => ''];
            }
            $netns = self::canUnshare();
            @unlink($pdf);
            $r = self::run(self::argv($browser, $profile, $html, $pdf, $netns), $timeout, $profile, ['HOME' => $profile, 'XDG_CONFIG_HOME' => $profile,
                'XDG_CACHE_HOME' => $profile, 'DBUS_SESSION_BUS_ADDRESS' => 'disabled:']);
            clearstatcache();
            $head = is_file($pdf) ? (string) file_get_contents($pdf, false, null, 0, 5) : '';
            $ok = $head === '%PDF-';
            $error = $ok ? '' : ($r['timedOut'] ? "Chromium did not finish within {$timeout}s and was stopped" : 'Chromium exited ' . $r['code'] . ' without writing a PDF');
            return ['ok' => $ok, 'mode' => $netns ? 'netns' : 'flags', 'major' => self::major($ver['out']), 'pages' => $ok ? self::pages($pdf) : 0,
                'error' => $error, 'stderr' => self::lastLines($r['err'])];
        } finally {
            self::rmTree($profile, $tmpDir);
        }
    }

    /** Pages of a PDF as Chromium writes it (one /Type /Page object per page); 0 when not countable. */
    public static function pages($pdf)
    {
        return (int) preg_match_all('#/Type\s*/Page(?![A-Za-z])#', (string) @file_get_contents($pdf));
    }

    /**
     * Runs $argv with a time limit. The browser runs in its own session (setsid at the front of the argv); on timeout its
     * whole process group gets SIGKILL. A probe without setsid gets SIGKILL itself. Returns ['code', 'out', 'err', 'timedOut'].
     */
    public static function run(array $argv, $timeout, $cwd, array $env = [])
    {
        $vars = getenv();
        foreach ($env as $k => $v) {
            $vars[$k] = $v;
        }
        $p = proc_open('exec ' . ToolProc::line($argv), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $vars);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'could not start ' . PathName::base($argv[0]), 'timedOut' => false];
        }
        $pid = (int) proc_get_status($p)['pid'];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $end = microtime(true) + $timeout;
        $timedOut = false;
        $code = null;
        while (true) {
            $out .= strlen($out) < 65536 ? (string) fread($pipes[1], 65536) : '';
            $err .= strlen($err) < 65536 ? (string) fread($pipes[2], 65536) : '';
            $st = proc_get_status($p);
            if (!$st['running']) {
                $code = $st['exitcode'];
                break;
            }
            if (microtime(true) > $end) {
                $timedOut = true;
                break;
            }
            usleep(50000);
        }
        if ($argv[0] === 'setsid') {
            self::killGroup($pid); // also after a normal exit: nothing of the browser outlives the call
        } elseif ($timedOut) {
            proc_terminate($p, 9); // a probe (--version, the unshare test) has no group of its own: kill it, or proc_close waits for it
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($p);
        return ['code' => $code !== null ? (int) $code : $closed, 'out' => $out, 'err' => $err, 'timedOut' => $timedOut];
    }

    /** SIGKILL to the process group $pgid (the setsid'ed browser and everything it started). */
    public static function killGroup($pgid)
    {
        if ($pgid <= 1) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill(-$pgid, 9);
            return;
        }
        exec('kill -KILL -- -' . (int) $pgid . ' 2>/dev/null');
    }

    /** Removes $dir (made by this class) below $parent; symlinks inside are unlinked, never followed. */
    public static function rmTree($dir, $parent)
    {
        if (strpos($dir, rtrim($parent, '/') . '/report-') !== 0 || strpos($dir, '..') !== false || !is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $path = $f->getPathname();
            (!is_link($path) && is_dir($path)) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function lastLines($text)
    {
        $lines = array_slice(array_values(array_filter(explode("\n", (string) $text), function ($l) {
            return trim($l) !== '' && stripos($l, 'dbus') === false;
        })), -3);
        return substr(implode(' | ', $lines), 0, 600);
    }
}
