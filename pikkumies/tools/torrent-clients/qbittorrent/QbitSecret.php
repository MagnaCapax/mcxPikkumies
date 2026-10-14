<?php
/**
 * QbitSecret: the qBittorrent WebUI password, typed once by the user at a terminal (ADR-0019).
 * PMSS keeps only a PBKDF2 hash of it in qBittorrent.conf (PMSS scripts/lib/user/qbittorrent.php:167-173),
 * so it cannot be read from disk. Stored in local/secrets/qbittorrent (dir 0700, file 0600). After a
 * rejected login the password is marked rejected and never tried again until the user runs `login`:
 * qBittorrent bans the address after 5 failed logins (research torrent-clients.md §2.2), and the
 * panel proxy shares that address, so retrying would lock the user out of their own WebUI.
 */
class QbitSecret
{
    private $file;

    public function __construct($dir)
    {
        $this->file = rtrim($dir, '/') . '/qbittorrent';
    }

    public function path()
    {
        return $this->file;
    }

    /** ['password' => string, 'rejected' => bool], or null when none is stored. */
    public function load()
    {
        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['password']) || !is_string($d['password']) || $d['password'] === '') {
            return null;
        }
        return ['password' => $d['password'], 'rejected' => !empty($d['rejected'])];
    }

    public function save($password, $rejected = false)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $tmp = $this->file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode(['password' => $password, 'rejected' => (bool) $rejected, 'saved_at' => time()])) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new CliError('cannot store the qBittorrent password', "check that $dir is writable");
        }
    }

    /** Never try this password again (the next attempt could get the address banned). */
    public function reject()
    {
        $s = $this->load();
        if ($s !== null) {
            $this->save($s['password'], true);
        }
    }

    /** The usable password, or a CliError that tells the user what to run. */
    public function usable()
    {
        $s = $this->load();
        if ($s === null) {
            throw new CliError('no qBittorrent password is stored for this tool', 'the user runs `php ' . self::script() . ' login` in a terminal and types the WebUI password (it is never typed into the chat)');
        }
        if ($s['rejected']) {
            throw new CliError('qBittorrent rejected the stored password; not trying again (repeated failures ban this address, which also locks the user out of the WebUI)', 'the user runs `php ' . self::script() . ' login` in a terminal with the current WebUI password');
        }
        return $s['password'];
    }

    /** Reads a password from the terminal without echo; refuses when stdin is not a terminal. */
    public static function prompt($label)
    {
        $tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') && @posix_isatty(STDIN));
        if (!$tty) {
            throw new CliError('login reads the password from a terminal, and stdin is not one', 'the user runs `php ' . self::script() . ' login` in their own terminal (never pass the password on the command line or in chat)');
        }
        fwrite(STDERR, $label);
        @shell_exec('stty -echo 2>/dev/null'); // stty works on its stdin, which is our terminal
        register_shutdown_function(function () {
            @shell_exec('stty echo 2>/dev/null');
        });
        $line = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
        $pw = rtrim((string) $line, "\r\n");
        if ($pw === '') {
            throw new CliError('no password typed; nothing stored', 'run login again');
        }
        return $pw;
    }

    private static function script()
    {
        return dirname(__FILE__) . '/qbittorrent.php';
    }
}
