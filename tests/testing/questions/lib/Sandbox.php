<?php
/**
 * Sandbox: the throwaway root the questions are asked in (ADR-0039). root/checkout is a copy of this checkout's
 * tracked files (never local/ with its keys), installed with its own `pikkumies install`; root/home is HOME for
 * every command, with a fake `crontab` first on PATH (Targets::env). Commands run through Asker.
 */
class Sandbox
{
    const MARKER = '.pikkumies-qa-root';
    /** Where the agent leaves traces between questions; files not in the snapshot are deleted before each question. */
    const TRACES = ['home', 'checkout/pikkumies/memory', 'checkout/pikkumies/local/state', 'checkout/pikkumies/local/hermes/sessions',
        'checkout/pikkumies/local/hermes/memories', 'checkout/pikkumies/local/hermes/logs', 'checkout/pikkumies/local/hermes/cron', 'checkout/pikkumies/local/docs-cache'];
    /** The settings file: a question that changes a setting (telemetry, the agent's name) must not hand it to the next. */
    const CONFIG = 'checkout/pikkumies/local/config.php';
    public $root;
    public $home;
    private $snapshot = [];
    private $config = null;

    public function __construct($root)
    {
        $this->root = $root;
        $this->home = "$root/home";
    }

    /** A new root under the system temp dir with the tracked files of $repo and the fake crontab. */
    public static function create($repo)
    {
        $root = sys_get_temp_dir() . '/pikkumies-questions-' . bin2hex(random_bytes(6));
        if (!mkdir("$root/home/.qa-bin", 0700, true) || !chmod($root, 0700)) {
            throw new Exception("could not create $root");
        }
        file_put_contents("$root/" . self::MARKER, "made by tests/testing/questions/run.php\n");
        exec('git -C ' . escapeshellarg($repo) . ' ls-files -z', $ls, $code);
        foreach (array_filter(explode("\0", implode("\n", $ls)), 'strlen') as $file) {
            if (is_file("$repo/$file") && !is_link("$repo/$file")) {
                @mkdir(dirname("$root/checkout/$file"), 0700, true);
                copy("$repo/$file", "$root/checkout/$file");
                chmod("$root/checkout/$file", fileperms("$repo/$file") & 0700);
            }
        }
        if ($code !== 0 || !is_file("$root/checkout/pikkumies/bin/pikkumies")) {
            throw new Exception("could not copy the tracked files of $repo to $root");
        }
        $tab = escapeshellarg("$root/home/.qa-bin/tab");
        file_put_contents("$root/home/.qa-bin/crontab", "#!/bin/sh\n# Fake crontab of the question suite: never the real one.\n"
            . "if [ \"\$1\" = -l ]; then [ -f $tab ] && exec cat $tab; echo 'no crontab for qa' >&2; exit 1; fi\n"
            . "if [ \"\$1\" = -r ]; then rm -f $tab; exit 0; fi\ncat \"\${1:--}\" > $tab\n");
        chmod("$root/home/.qa-bin/crontab", 0700);
        return new self($root);
    }

    /** An earlier root (--install DIR): only one this runner made, owned by this account, private, under the temp dir. */
    public static function reuse($dir)
    {
        $real = realpath($dir);
        $tmp = rtrim((string) realpath(sys_get_temp_dir()), '/') . '/';
        $st = $real ? @stat($real) : false;
        if (!$real || strpos($real, $tmp) !== 0 || !$st || $st['uid'] !== posix_geteuid() || ($st['mode'] & 0777) !== 0700
            || !is_file("$real/" . self::MARKER) || !is_file("$real/checkout/pikkumies/bin/pikkumies") || !is_file("$real/home/.qa-bin/crontab")) {
            throw new Exception("--install $dir is not a private sandbox this runner made under " . $tmp);
        }
        return new self($real);
    }

    /**
     * `pikkumies install`, then wait for the runtime build it starts in the background (up to an hour), then kill what
     * is left (the background documentation fill: each family is refreshed in the foreground instead). '' or the problem.
     */
    public function install()
    {
        list($code) = Asker::run($this, Asker::pikkumies($this, ['install']), '', $this->root . '/install.out', $this->root . '/install.err', 600, false);
        $lock = $this->root . '/checkout/pikkumies/include/.hermes-build.lock';
        $ready = $this->root . '/checkout/pikkumies/include/.hermes-ready';
        $grace = time() + 30; // the build takes its lock a moment after install returns
        $end = time() + 3600;
        while (time() < $end && !is_file($ready) && (self::locked($lock) || time() < $grace)) {
            sleep(2);
        }
        Asker::killStray($this->home);
        return $code === 0 && is_file($ready) ? '' : "install failed (exit $code" . (is_file($ready) ? '' : ', runtime not built')
            . "); see {$this->root}/install.out and checkout/pikkumies/local/install.log";
    }

    /** True while another process holds an exclusive lock on $file. */
    private static function locked($file)
    {
        $h = @fopen($file, 'r');
        if (!$h) {
            return false;
        }
        $free = flock($h, LOCK_EX | LOCK_NB);
        fclose($h);
        return !$free;
    }

    /** Remember what exists now in the trace directories and the settings file, and copy Hermes' session database. */
    public function snapshot()
    {
        $cfg = $this->root . '/' . self::CONFIG;
        $this->config = is_file($cfg) && !is_link($cfg) ? (string) file_get_contents($cfg) : null;
        $this->snapshot = [];
        foreach (self::TRACES as $t) {
            foreach (self::walk($this->root . "/$t") as $p) {
                $this->snapshot[$p] = true;
            }
        }
        @mkdir($this->root . '/snap', 0700);
        array_map('unlink', glob($this->root . '/snap/state.db*') ?: []);
        foreach (glob($this->root . '/checkout/pikkumies/local/hermes/state.db*') ?: [] as $f) {
            copy($f, $this->root . '/snap/' . basename($f));
        }
    }

    /**
     * Back to the snapshot: delete what is new (a link is unlinked, never followed), put the settings file back as it
     * was (or remove it when there was none), restore the session database.
     */
    public function reset()
    {
        $cfg = $this->root . '/' . self::CONFIG;
        $inside = strpos((string) realpath(dirname($cfg)) . '/', realpath($this->root) . '/') === 0; // local/ itself may be a planted link
        if ($inside && ($this->config === null || is_link($cfg) || !is_file($cfg) || file_get_contents($cfg) !== $this->config)) {
            @unlink($cfg);
            if ($this->config !== null) {
                file_put_contents($cfg, $this->config);
                chmod($cfg, 0600);
            }
        }
        $root = realpath($this->root) . '/';
        foreach (self::TRACES as $t) {
            foreach (array_reverse(self::walk($this->root . "/$t")) as $p) {
                if (isset($this->snapshot[$p]) || strpos((string) realpath(dirname($p)) . '/', $root) !== 0) {
                    continue;
                }
                is_link($p) || !is_dir($p) ? @unlink($p) : @rmdir($p);
            }
        }
        $db = $this->root . '/checkout/pikkumies/local/hermes';
        array_map('unlink', glob("$db/state.db*") ?: []);
        foreach (glob($this->root . '/snap/state.db*') ?: [] as $f) {
            copy($f, "$db/" . basename($f));
        }
    }

    /** Every path under $dir, parents before children; symlinked directories are listed, never entered. */
    public static function walk($dir)
    {
        $out = [];
        foreach (is_dir($dir) && !is_link($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $out[] = "$dir/$name";
                $out = array_merge($out, is_link("$dir/$name") ? [] : self::walk("$dir/$name"));
            }
        }
        return $out;
    }

    /** Delete the sandbox: only a root under the temp dir that carries the marker. */
    public function remove()
    {
        Asker::killStray($this->home);
        if (strpos($this->root, rtrim(sys_get_temp_dir(), '/') . '/') === 0 && is_file($this->root . '/' . self::MARKER)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }
}
