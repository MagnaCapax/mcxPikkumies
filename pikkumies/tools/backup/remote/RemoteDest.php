<?php
/**
 * RemoteDest: where `backup remote` sends archives and how it reaches that machine (ADR-0049). Everything lives in
 * local/secrets/backup-remote/ (directory 0700, files 0600), inside the delete guard's secret fence, so the agent can
 * neither read the key and passphrase nor rewrite the destination or the pinned host key (dest.json, known_hosts):
 * key, key.pub, pass, dest.json, state.json, known_hosts. Every ssh call is an argv with -F /dev/null (no user ssh
 * config, no ProxyCommand from a file the agent could write), the dedicated known_hosts and `--` before the host. A host on
 * the person's SSH deny list (ssh.deny in local/config.php, ADR-0068) is refused before any connection, also by a scheduled run.
 */
class RemoteDest
{
    const NAME_RE = '/^(\d{8}T\d{6}Z)\.(meta|full)\.tgz\.gpg$/';
    const RECV = 'sh ~/.pikkumies-recv/recv.sh';
    const CALL_MAX = 1048576; // bytes of one receiver answer: a listing of thousands of archives fits

    public $dir;
    public $localDir;
    private $configFile;

    public function __construct($localDir)
    {
        $this->dir = rtrim($localDir, '/') . '/secrets/backup-remote';
        $this->configFile = rtrim($localDir, '/') . '/config.php';
        $this->localDir = rtrim($localDir, '/');
        RemotePipe::$localDir = $this->localDir; // the stages' stderr files go to local/tmp too (ADR-0099)
    }

    /** Refuse a host on the person's SSH deny list (ADR-0068); allow and unknown do not apply here (setup is asked, ADR-0049). */
    public function refuseDenied($host)
    {
        $config = SshLists::config($this->configFile);
        if (empty($config['ssh']['deny'])) {
            return;
        }
        $name = SshLists::host($host);
        if ($name === null) {
            throw new CliError("$host cannot be checked against the SSH deny list (ssh.deny); nothing was sent", 'use the host name or a plain dotted IPv4 address');
        }
        if (SshLists::decide($config, [$name]) === SshLists::DENY) {
            throw new CliError("$host is on the SSH deny list in local/config.php (ssh.deny); nothing was sent", 'only the person removes a host from that list, by hand');
        }
    }

    public static function host($h)
    {
        $h = (string) $h;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $h)) {
            throw new CliError("not a host name: $h", 'a name like backup.example.com or an IPv4 address; never an option');
        }
        return $h;
    }

    public static function user($u)
    {
        $u = (string) $u;
        if (!preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/', $u)) {
            throw new CliError("not an account name: $u", 'the login name on the backup machine, e.g. xyzjohn');
        }
        return $u;
    }

    /** A whole number in [$min, $max] from an option, or $default when it is absent. */
    public static function number($opt, $name, $min, $max, $default)
    {
        if (!isset($opt[$name])) {
            return $default;
        }
        $v = (string) $opt[$name];
        if (!preg_match('/^[0-9]{1,6}$/', $v) || (int) $v < $min || (int) $v > $max) {
            throw new CliError("--$name $v is not valid", "a whole number from $min to $max");
        }
        return (int) $v;
    }

    /** The receiver's directory name on the backup machine: this host and account, so several seedboxes can share one. */
    public static function label()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $who = is_array($pw) && $pw['name'] !== '' ? $pw['name'] : ((string) getenv('USER') !== '' ? (string) getenv('USER') : (string) $uid);
        $label = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', explode('.', php_uname('n'))[0] . '-' . $who), '.-');
        return $label !== '' ? substr($label, 0, 64) : 'pikkumies';
    }

    public function path($name)
    {
        return $this->dir . '/' . $name;
    }

    /** The saved destination, or a CliError naming setup. */
    public function load()
    {
        $raw = SecretFile::read($this->path('dest.json'), 'run `setup <host> <user>` again');
        $d = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['host'], $d['user'], $d['port'], $d['label']) || !is_file($this->path('key'))) {
            throw new CliError('no backup destination is set up', 'run `setup <host> <user>` (ADR-0049); the skill says how to hand'
                . ' over the login');
        }
        self::host($d['host']);
        self::user($d['user']);
        return $d;
    }

    /** state.json: the last run and passphrase_saved_by_user. */
    public function state()
    {
        $raw = is_file($this->path('state.json')) ? SecretFile::read($this->path('state.json'), 'run the backup again') : false;
        $s = $raw !== false ? json_decode($raw, true) : null;
        return is_array($s) ? $s : [];
    }

    /**
     * True when the user typed the passphrase back (`passphrase --confirm-saved`) for the passphrase file that is here now: a
     * regenerated file has another sha256, and an older confirmation without one was only a promise (ADR-0099).
     */
    public function passphraseConfirmed()
    {
        $s = $this->state();
        $f = $this->path('pass');
        return !empty($s['passphrase_saved_by_user']) && isset($s['passphrase_sha256']) && is_string($s['passphrase_sha256']) && is_file($f)
            && hash_equals($s['passphrase_sha256'], (string) hash_file('sha256', $f));
    }

    public function save($name, array $fields)
    {
        unset($fields['saved_at']);
        SecretFile::write($this->path($name), $fields, 'backup destination');
    }

    /** The gpg passphrase file; made at setup (48 random hex characters) and never replaced, so older archives stay readable. */
    public function passFile($create)
    {
        $f = $this->path('pass');
        if (!is_file($f) && $create) {
            $old = umask(0077);
            $ok = @file_put_contents($f . '.tmp', bin2hex(random_bytes(24)) . "\n") === 49 && @rename($f . '.tmp', $f);
            umask($old);
            if (!$ok) {
                throw new CliError("cannot write $f", 'check that pikkumies/local is writable by this account');
            }
        }
        if (SecretFile::read($f, 'run setup again (the passphrase of existing archives is then lost)') === false) {
            throw new CliError('no backup passphrase file on this machine', 'archives made with it can be decrypted only with the copy the'
                . ' user saved; run setup again to make a new one');
        }
        return $f;
    }

    /** ssh to the destination with the restricted key: StrictHostKeyChecking=yes, key login only, no prompts. $new: setup's ".new" files. */
    public function ssh(array $d, array $words, $new = '')
    {
        $this->refuseDenied($d['host']);
        return array_merge(self::base($d, $this->path('known_hosts' . $new), 'yes'), ['-i', $this->path('key' . $new), '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes', '-o', 'PreferredAuthentications=publickey', '--', $d['host']], $words);
    }

    /** The options every call shares (user, port, the dedicated known_hosts with its checking mode). */
    public static function base(array $d, $knownHosts, $strict)
    {
        if (strpbrk($knownHosts, "\"\n\r") !== false) {
            throw new CliError('the pikkumies directory path holds a quote or a line break', 'ssh cannot take it as an option value');
        }
        $alg = isset($d['host_key_alg']) && in_array($d['host_key_alg'], ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512'], true)
            ? ['-o', 'HostKeyAlgorithms=' . $d['host_key_alg']] : [];
        return array_merge(['ssh', '-F', '/dev/null', '-p', (string) (int) $d['port'], '-l', $d['user'], '-o', 'UserKnownHostsFile="' . $knownHosts . '"',
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'StrictHostKeyChecking=' . $strict, '-o', 'ConnectTimeout=20',
            '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=4'], $alg);
    }

    /**
     * ssh's error text for a message. A changed host key becomes its own error (someone may be in between), so ssh's
     * advice that comes with it, to delete the pinned key (ssh-keygen -R), is never passed on.
     */
    public static function sshTail($err)
    {
        if (preg_match('/Host key verification failed|HOST IDENTIFICATION HAS CHANGED|host key .*has changed/i', (string) $err)) {
            throw new CliError("the backup machine's identity (its host key) is not the one pinned at setup; someone may be in between, so"
                . ' nothing was sent', 'check with the backup host\'s owner, then run setup again with --fingerprint SHA256:... of the real key');
        }
        return ToolText::tail($err);
    }

    /** A new empty 0600 temporary file in local/tmp (counted in this account's quota, never the shared /tmp). */
    public function tmp($prefix)
    {
        return LocalTmp::file($this->localDir, $prefix);
    }

    /**
     * Runs one receiver command; returns [exit code, stdout, stderr tail]. Its stdout is read through a pipe and the call is
     * stopped once it passes CALL_MAX bytes (the backup machine decides what it prints), so nothing of it lands on disk.
     */
    public function call(array $d, array $words, $new = '')
    {
        $text = '';
        $r = ToolProc::run($this->ssh($d, $words, $new), [], function ($line) use (&$text) {
            $text .= $line . "\n";
            return strlen($text) <= self::CALL_MAX;
        });
        return [$r['stopped'] ? 1 : $r['code'], substr($text, 0, self::CALL_MAX), trim(substr($r['err'], -2000))];
    }

    /** Free space on the destination in bytes, from the receiver's `df`. */
    public function avail(array $d)
    {
        list($code, $out, $err) = $this->call($d, ['df']);
        if ($code !== 0 || !preg_match('/^avail_kib (\d+)$/m', $out, $m)) {
            throw new CliError('the backup machine did not answer (' . ($err !== '' ? self::sshTail($err) : "exit $code") . '); nothing sent',
                'check the network and that the backup machine is up; `stats` shows the destination');
        }
        return (int) $m[1] * 1024;
    }
}
