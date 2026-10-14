<?php
/**
 * RcloneCmd: how this unit calls rclone (README.md here). Every call adds --ask-password=false, so
 * rclone fails instead of prompting for a config password (rclone 1.69.1 docs/content/docs.md,
 * "Configuration Encryption"), reads stdin from /dev/null, and puts `--` before the paths.
 * An encrypted config is detected and the tool stops: it never asks for that password.
 */
class RcloneCmd
{
    public $home;
    public $uid;
    private $config = false;
    private $version = false;

    public function __construct($home, $uid)
    {
        $this->home = $home;
        $this->uid = (int) $uid;
    }

    public function bin()
    {
        return ToolProc::need('rclone', 'rclone is not installed here. On Pulsed Media every server has it (ask support if it is'
            . ' missing); elsewhere the user installs it from rclone.org. This tool never installs anything.');
    }

    /** rclone <words> <flags> --ask-password=false -- <paths>; $gentle: nice 19 and idle I/O, a time limit in seconds (0: none).
     * "unable to decrypt configuration" is the encrypted-config error for any verb: rclone 1.53.3 may load the config before
     * `config file` answers (fs/config/config.go:360-361 there; 1.69.1 fs/config/crypt.go:147-148). */
    public function run(array $words, array $flags = [], array $paths = [], $onLine = null, $gentle = null)
    {
        $this->bin();
        $argv = array_merge(['rclone'], $words, $flags, ['--ask-password=false'], $paths ? array_merge(['--'], $paths) : []);
        $r = ToolProc::run($gentle === null ? $argv : ToolProc::gentle($argv, $gentle), [], $onLine);
        if ($r['code'] !== 0 && stripos($r['err'], 'unable to decrypt configuration') !== false) {
            throw $this->encryptedError(is_string($this->config) ? $this->config : 'in use');
        }
        return $r;
    }

    /** Runs and fails with rclone's own error text when the exit code is not one of $ok. */
    public function must(array $words, array $flags = [], array $paths = [], $onLine = null, array $ok = [0])
    {
        $r = $this->run($words, $flags, $paths, $onLine);
        if (!in_array($r['code'], $ok, true)) {
            throw new CliError('rclone ' . implode(' ', $words) . " failed (exit {$r['code']}): " . ToolProc::tail($r['err']),
                'read the error; a remote name comes from `list`');
        }
        return $r;
    }

    /** "1.69.1" from `rclone version` (first line `rclone v1.69.1`, rclone_version.md), or null. */
    public function version()
    {
        if ($this->version === false) {
            $r = $this->run(['version']);
            $this->version = preg_match('/^rclone v?(\d+\.\d+(\.\d+)?)/m', $r['out'], $m) ? $m[1] : null;
        }
        return $this->version;
    }

    public function atLeast($v)
    {
        return $this->version() !== null && version_compare($this->version(), $v, '>=');
    }

    /** The config file rclone uses (`rclone config file`: the path is the last line, fs/config/ui.go:639-650), or null. */
    public function configPath()
    {
        if ($this->config === false) {
            $this->config = null;
            $r = $this->run(['config', 'file']);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $r['out'])), 'strlen'));
            $last = $lines ? end($lines) : '';
            $this->config = $r['code'] === 0 && $last !== '' && $last[0] === '/' ? $last : null;
        }
        return $this->config;
    }

    /**
     * True when the first line that is not empty or a comment starts with RCLONE_ENCRYPT_V (rclone
     * 1.69.1 fs/config/crypt.go:68-77). The file is read here, never printed.
     */
    public function encrypted()
    {
        $path = $this->configPath();
        $fh = $path !== null && is_file($path) ? @fopen($path, 'r') : false;
        if (!$fh) {
            return false;
        }
        $enc = false;
        while (($line = fgets($fh)) !== false) {
            $l = trim($line);
            if ($l === '' || $l[0] === '#' || $l[0] === ';') {
                continue;
            }
            $enc = strpos($l, 'RCLONE_ENCRYPT_V') === 0;
            break;
        }
        fclose($fh);
        return $enc;
    }

    /** Stops before any call that would need an encrypted config. */
    public function ready()
    {
        $this->bin();
        if ($this->encrypted()) {
            throw $this->encryptedError($this->configPath());
        }
    }

    private function encryptedError($path)
    {
        return new CliError("the rclone config ($path) is encrypted; this tool cannot unlock it and never asks for its password;"
            . ' nothing was done',
            'the user runs the rclone command in their own terminal, where rclone asks for the config password; '
            . 'that password is never typed into the chat');
    }

    /**
     * A source or destination: "remote:path" with a remote name, or an absolute local path inside the
     * home ($mustExist: it exists; otherwise its parent does). Never an option, never a connection
     * string (":sftp,pass=...:" carries credentials on the command line).
     */
    public function place($s, $mustExist)
    {
        $s = (string) $s;
        if ($s === '' || $s[0] === '-' || $s[0] === ':' || strpos($s, "\n") !== false) {
            throw new CliError("not a place rclone may use: $s", 'use remote:path with a remote from `list`, or a full local path;'
                . ' connection strings are refused because they carry passwords');
        }
        if ($s[0] !== '/') {
            if (!preg_match('/^[\w.+@ -]+:/', $s)) {
                throw new CliError("not remote:path and not a full local path: $s", 'remote names come from `list`; local paths'
                    . ' start with /');
            }
            return $s;
        }
        if ($mustExist || file_exists($s)) {
            return HomePath::existing($s, $this->home);
        }
        $parent = realpath(dirname($s));
        if ($parent === false || !HomePath::inside($parent, $this->home)) {
            throw new CliError("$s is not inside the home directory", 'local paths must be under ' . $this->home);
        }
        return $parent . '/' . basename($s);
    }

    public static function isLocal($place)
    {
        return $place !== '' && $place[0] === '/';
    }
}
