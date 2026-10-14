<?php
/**
 * AutobrrSecret: the autobrr API key, typed once by the user at a terminal (ADR-0019), the same pattern as
 * torrent-clients/qbittorrent/QbitSecret.php and backup/lib/BackupStore.php: never in the chat, never on a
 * command line; stored in local/secrets/autobrr (directory 0700, file 0600, written to a temporary file and
 * renamed). autobrr answers a wrong key with 401 and nothing more (internal/http/middleware.go:71-85), so there
 * is no "rejected" mark as for qBittorrent; a rejected key is an error that names `login`. The three classes
 * should become one pikkumies/lib class.
 */
class AutobrrSecret
{
    private $file;

    public function __construct($dir)
    {
        $this->file = rtrim($dir, '/') . '/autobrr';
    }

    public function path()
    {
        return $this->file;
    }

    /** The stored key, or '' when none is stored. */
    public function load()
    {
        $raw = is_file($this->file) && !is_link($this->file) ? @file_get_contents($this->file, false, null, 0, 4096) : false;
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) && isset($d['key']) && is_string($d['key']) ? ArrConfig::key($d['key']) : '';
    }

    public function save($key)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $tmp = $this->file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode(['key' => $key, 'saved_at' => time()])) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new CliError('cannot store the autobrr API key', "check that $dir is writable");
        }
    }

    /** Reads the key from the terminal without echo; refuses when stdin is not a terminal. */
    public static function prompt($label)
    {
        $tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') && @posix_isatty(STDIN));
        if (!$tty) {
            throw new CliError('login reads the API key from a terminal, and stdin is not one', 'the user runs `php '
                . dirname(__FILE__) . '/autobrr.php login` in their own terminal (never pass the key on the command line or in chat)');
        }
        fwrite(STDERR, $label);
        @shell_exec('stty -echo 2>/dev/null');
        register_shutdown_function(function () {
            @shell_exec('stty echo 2>/dev/null');
        });
        $line = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
        return trim((string) $line);
    }
}
