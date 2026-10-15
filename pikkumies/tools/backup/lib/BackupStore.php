<?php
/**
 * BackupStore: the repository and its password for one backup tool (ADR-0019), the same pattern as
 * torrent-clients/qbittorrent/QbitSecret.php: the user types the password once at a terminal
 * prompt (never in the chat, never on a command line); it is stored in local/secrets/<tool>
 * (directory 0700, file 0600) and handed to the program as a file path (restic
 * RESTIC_PASSWORD_FILE, borg BORG_PASSCOMMAND), never as a value. The repository location is kept
 * in local/backup/<tool>.json. A new login is written next to the stored one (<tool>.new) and
 * checked from there; only a login the repository accepted replaces the stored one, and a stored
 * password that differs is kept as <tool>.prev-<time>, so a typo or a second repository never
 * deletes the only copy. QbitSecret and this class should become one pikkumies/lib class.
 */
class BackupStore
{
    private $tool;
    private $secretFile;
    private $repoFile;
    private $pending = null; // [repo, file] of a login being checked

    public function __construct($tool, $secretDir, $localDir)
    {
        $this->tool = $tool;
        $this->secretFile = rtrim($secretDir, '/') . '/' . $tool;
        $this->repoFile = rtrim($localDir, '/') . "/backup/$tool.json";
    }

    public function secretPath()
    {
        return $this->secretFile;
    }

    /** The saved repository, or null. */
    public function repo()
    {
        $d = is_file($this->repoFile) ? json_decode((string) @file_get_contents($this->repoFile), true) : null;
        return is_array($d) && isset($d['repo']) && is_string($d['repo']) && $d['repo'] !== '' ? $d['repo'] : null;
    }

    /** True when a repository and its password are stored. */
    public function loggedIn()
    {
        return $this->repo() !== null && is_file($this->secretFile);
    }

    /** [repo, password file] (the login being checked, if any) or a CliError telling the user to run login. */
    public function usable($script)
    {
        if ($this->pending !== null) {
            return $this->pending;
        }
        if (!$this->loggedIn()) {
            throw new CliError("no {$this->tool} repository is set up for this tool",
                "the user runs `php $script login --repo <repository>`"
                . ' in a terminal and types the repository password there (never in the chat)');
        }
        return [$this->repo(), $this->secretFile];
    }

    /** Writes a new login beside the stored one; usable() hands it out until commit() or discard(). */
    public function stage($repo, $password)
    {
        $this->write($this->secretFile . '.new', $password);
        $this->pending = [$repo, $this->secretFile . '.new'];
    }

    /** The checked login becomes the stored one. Returns where a different old password was kept, or null. */
    public function commit()
    {
        list($repo, $new) = $this->pending;
        $old = is_file($this->secretFile) ? (string) @file_get_contents($this->secretFile) : null;
        $kept = null;
        if ($old !== null && $old !== (string) @file_get_contents($new)) {
            $kept = $this->secretFile . '.prev-' . date('YmdHis');
            $this->write($kept, $old);
        }
        $was = $this->repo();
        if (!@rename($new, $this->secretFile)) {
            throw new CliError("cannot store the {$this->tool} password", 'check that ' . dirname($new) . ' is writable');
        }
        $this->pending = null;
        $this->write($this->repoFile, json_encode(['repo' => $repo, 'saved_at' => time()]
            + ($kept !== null && $was !== null ? ['previous_repo' => $was] : []), JSON_UNESCAPED_SLASHES));
        return $kept;
    }

    /** Drops a login that was not accepted; the stored one is untouched. */
    public function discard()
    {
        if ($this->pending !== null) {
            @unlink($this->pending[1]);
        }
        $this->pending = null;
    }

    /** Reads a password from the terminal without echo; refuses when stdin is not a terminal. */
    public static function prompt($label, $script)
    {
        $tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') && @posix_isatty(STDIN));
        if (!$tty) {
            throw new CliError('login reads the password from a terminal, and stdin is not one; nothing stored',
                "the user runs `php $script login --repo <repository>` in their own terminal (never pass the password on the"
                . ' command line or in chat)');
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

    private function write($file, $content)
    {
        $dir = dirname($file);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $tmp = $file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, $content) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot store the {$this->tool} settings", "check that $dir is writable");
        }
    }
}
