<?php
/**
 * MediaSecret: what a media unit keeps between calls in local/secrets/<unit> (directory 0700,
 * file 0600, ADR-0019): a key or token the user typed with `login`, or a Jellyfin login session,
 * plus "rejected" marks so a refused credential is never tried again until the user runs `login`.
 * The guard blocks every command on local/secrets/ (ADR-0020), so only the tools read it.
 */
class MediaSecret
{
    private $file;

    public function __construct($dir, $unit)
    {
        $this->file = rtrim($dir, '/') . '/' . $unit;
    }

    public function path()
    {
        return $this->file;
    }

    /** The stored fields ([] when nothing is stored or the file is unreadable). */
    public function load()
    {
        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    /** Replaces the stored fields, atomically, 0600 in a 0700 directory. */
    public function save(array $fields)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        $tmp = $this->file . '.tmp' . getmypid();
        $old = umask(0077);
        $ok = @file_put_contents($tmp, json_encode($fields + ['saved_at' => time()])) !== false;
        umask($old);
        if (!$ok || !@chmod($tmp, 0600) || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new CliError('cannot store the credential', "check that $dir is writable");
        }
    }

    /** Merges $fields into what is stored (null removes a field). */
    public function update(array $fields)
    {
        $d = $this->load();
        foreach ($fields as $k => $v) {
            if ($v === null) {
                unset($d[$k]);
            } else {
                $d[$k] = $v;
            }
        }
        unset($d['saved_at']);
        $this->save($d);
    }

    /** A stable id for this tool's login sessions: a server keeps one token per device id. */
    public function deviceId()
    {
        $d = $this->load();
        if (isset($d['device_id']) && is_string($d['device_id']) && preg_match('/^pikkumies-[0-9a-f]{16}$/', $d['device_id'])) {
            return $d['device_id'];
        }
        $id = 'pikkumies-' . bin2hex(random_bytes(8));
        $this->update(['device_id' => $id]);
        return $id;
    }

    /**
     * The `login` verb: the user types a key at a terminal; $verify($key) tries it once (MediaAuthRejected:
     * refused, nothing stored; another CliError: stored unverified). Storing clears every rejected mark.
     */
    public function login($label, $script, callable $verify)
    {
        $key = self::prompt($label, $script);
        $verified = true;
        $note = '';
        try {
            $verify($key);
        } catch (MediaAuthRejected $e) {
            throw new CliError('the server refused that key; nothing was stored', 'copy the key again from the server dashboard and run login again');
        } catch (CliError $e) {
            list($verified, $note) = [false, 'stored without a check: ' . str_replace($key, 'REDACTED', $e->getMessage())];
        }
        $this->update(['key' => $key, 'key_rejected' => null, 'pw_rejected' => null, 'prefs_rejected' => null, 'session' => null]);
        return ['records' => [['stored' => $this->file, 'verified' => $verified] + ($note !== '' ? ['note' => $note] : [])], 'status' => ['verified' => $verified]];
    }

    /** Reads a value from the terminal without echo; refuses when stdin is not a terminal. */
    public static function prompt($label, $script)
    {
        $tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') && @posix_isatty(STDIN));
        if (!$tty) {
            throw new CliError('login reads the key from a terminal, and stdin is not one', "the user runs `php $script login` in their own terminal (never in the chat or on the command line)");
        }
        fwrite(STDERR, $label);
        @shell_exec('stty -echo 2>/dev/null'); // stty works on its stdin, which is our terminal
        register_shutdown_function(function () {
            @shell_exec('stty echo 2>/dev/null');
        });
        $line = fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
        $value = trim((string) $line);
        if ($value === '') {
            throw new CliError('nothing typed; nothing stored', 'run login again');
        }
        return $value;
    }
}
