<?php
/**
 * RemoteSetup: `setup <host> <user>` (ADR-0049). The one-time remote login comes only from PIKKUMIES_REMOTE_PASSWORD,
 * read first and removed from this process's environment at once; ssh gets it through askpass.php (SSH_ASKPASS,
 * SSH_ASKPASS_REQUIRE=force), never on an argv or in a file. Order: pin the host key without any login (accept-new into
 * a dedicated known_hosts, checked against --fingerprint when given), then one login with that key pinned
 * (StrictHostKeyChecking=yes) that installs the receiver and a `restrict,command=` key line, then prove the new key
 * lands in the receiver. Anything short of that deletes the new key here: an unrestricted key is never kept.
 */
class RemoteSetup
{
    const ENV = 'PIKKUMIES_REMOTE_PASSWORD';
    const HOST_KEY_ALGS = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512'];

    public static function run(RemoteDest $dest, array $args, array $opt, $askpass, $recvFile)
    {
        $secret = getenv(self::ENV);
        putenv(self::ENV);
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
        if (count($args) !== 2) {
            throw new CliError('setup takes <host> <user>', 'e.g. setup backup.example.com xyzjohn');
        }
        $d = ['host' => RemoteDest::host($args[0]), 'user' => RemoteDest::user($args[1]),
            'port' => RemoteDest::number($opt, 'port', 1, 65535, 22), 'keep_days' => RemoteDest::number($opt, 'keep-days', 1, 3650, 30),
            'label' => RemoteDest::label()];
        $dest->refuseDenied($d['host']);
        $want = isset($opt['fingerprint']) ? (string) $opt['fingerprint'] : null;
        if ($want !== null && !preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $want)) {
            throw new CliError('--fingerprint takes the SHA256:... form', 'the fingerprint of the host\'s ED25519, ECDSA (nistp256) or RSA key;'
                . ' the backup host owner reads it with ssh-keygen -lf on that key');
        }
        if ($secret === false || $secret === '') {
            throw new CliError('no remote login given; nothing done', 'pass it only as a prefix: ' . self::ENV . "='...' php <this tool> setup"
                . ' <host> <user> (never as an argument)');
        }
        $keygen = ToolProc::need('ssh-keygen', 'install openssh-client');
        ToolProc::need('ssh', 'install openssh-client');
        if (!@is_dir($dest->dir) && !@mkdir($dest->dir, 0700, true)) {
            throw new CliError("cannot create {$dest->dir}", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dest->dir, 0700);
        $new = ['key.new', 'key.new.pub', 'known_hosts.new', 'install.sh.new'];
        try {
            self::clean($dest, $new);
            $r = ToolProc::run([$keygen, '-q', '-t', 'ed25519', '-N', '', '-C', 'pikkumies-backup-' . $d['label'], '-f', $dest->path('key.new')]);
            $pub = trim((string) @file_get_contents($dest->path('key.new.pub')));
            if ($r['code'] !== 0 || !preg_match('#^ssh-ed25519 ([A-Za-z0-9+/=]+)( |$)#', $pub, $m)) {
                throw new CliError('ssh-keygen could not make a key: ' . ToolText::tail($r['err']), 'check ssh-keygen and the disk space');
            }
            list($d['host_key'], $d['host_key_alg']) = self::pinHost($dest, $d, $keygen, $want);
            $line = 'restrict,command="' . RemoteDest::RECV . " {$d['label']} {$d['keep_days']}\" ssh-ed25519 {$m[1]} pikkumies-backup-{$d['label']}";
            self::install($dest, $d, $line, $secret, $askpass, (string) file_get_contents($recvFile));
            $secret = null;
            self::verify($dest, $d);
            foreach (['key.new' => 'key', 'key.new.pub' => 'key.pub', 'known_hosts.new' => 'known_hosts'] as $from => $to) {
                rename($dest->path($from), $dest->path($to));
            }
        } finally {
            self::clean($dest, $new);
        }
        $dest->passFile(true);
        $dest->save('dest.json', $d + ['setup_at' => time()]);
        $state = $dest->state();
        return [$d + ['restricted' => true, 'verified' => true,
            'passphrase_saved_by_user' => !empty($state['passphrase_saved_by_user']),
            'next' => ['change the remote password now: the key works without it, and changing it is what really scrubs it',
                'the password you typed has already reached the model provider and this session\'s history; it is not stored here',
                'run `php ' . dirname($recvFile) . '/remote.php passphrase` in your own terminal and keep that passphrase somewhere other'
                . ' than this server and the backup machine: without it no archive can be decrypted']]];
    }

    /**
     * The host key, recorded by a connection that offers no login at all. The client pins whichever key type it negotiates
     * (OpenSSH 8.4 took ECDSA and 10.0 ED25519 from one server), so a --fingerprint that does not match is tried once per
     * accepted type. Returns [fingerprint, key type to keep using]; later connections set HostKeyAlgorithms to that type.
     */
    private static function pinHost(RemoteDest $dest, array $d, $keygen, $want)
    {
        $seen = [];
        foreach (array_merge([null], self::HOST_KEY_ALGS) as $alg) {
            list($fp, $type, $err) = self::probe($dest, $d, $keygen, $alg);
            if ($fp === null && $alg === null) {
                throw new CliError("could not reach {$d['host']} port {$d['port']} to read its host key: " . RemoteDest::sshTail($err),
                    'check the host name, the port and the network; nothing was sent');
            }
            if ($fp !== null && ($want === null || $fp === $want)) {
                return [$fp, $type];
            }
            $seen[] = $fp;
        }
        throw new CliError("no host key of {$d['host']} (" . implode(', ', array_unique(array_filter($seen))) . ") is $want; nothing was sent"
            . ' (the login was not used)', 'accepted key types: ED25519, ECDSA (nistp256) and RSA. Someone may be in between, or the'
            . ' fingerprint is wrong: ask the backup host owner');
    }

    /** One login-less connection into a fresh known_hosts.new, optionally offering only $alg: [fingerprint|null, type|null, stderr]. */
    private static function probe(RemoteDest $dest, array $d, $keygen, $alg)
    {
        $kh = $dest->path('known_hosts.new');
        file_put_contents($kh, '');
        chmod($kh, 0600);
        $probe = array_merge(RemoteDest::base($d + ['host_key_alg' => $alg], $kh, 'accept-new'), ['-o', 'BatchMode=yes',
            '-o', 'PubkeyAuthentication=no', '-o', 'PasswordAuthentication=no', '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'GSSAPIAuthentication=no', '--', $d['host'], 'true']);
        $r = ToolProc::run($probe);
        $l = ToolProc::run([$keygen, '-l', '-E', 'sha256', '-f', $kh]);
        $line = explode(' ', trim((string) @file_get_contents($kh)));
        $types = ['ssh-ed25519' => 'ssh-ed25519', 'ecdsa-sha2-nistp256' => 'ecdsa-sha2-nistp256', 'ssh-rsa' => 'rsa-sha2-512'];
        return [preg_match('#\b(SHA256:[A-Za-z0-9+/]{43})\b#', $l['out'], $m) ? $m[1] : null,
            isset($line[1], $types[$line[1]]) ? $types[$line[1]] : null, $r['err']];
    }
    /** One login (host key pinned) runs a fixed `sh -s` script: the receiver plus one key line, umask 077, append-only. */
    private static function install(RemoteDest $dest, array $d, $line, $secret, $askpass, $recv)
    {
        $script = "umask 077\nset -e\nmkdir -p \"\$HOME/.pikkumies-recv\" \"\$HOME/.ssh\"\n"
            . "cat > \"\$HOME/.pikkumies-recv/recv.sh.new\" <<'PIKKUMIES_RECV_EOF'\n" . rtrim($recv) . "\nPIKKUMIES_RECV_EOF\n"
            . "mv \"\$HOME/.pikkumies-recv/recv.sh.new\" \"\$HOME/.pikkumies-recv/recv.sh\"\nline='$line'\n"
            . "touch \"\$HOME/.ssh/authorized_keys\"\ngrep -qxF \"\$line\" \"\$HOME/.ssh/authorized_keys\""
            . " || printf '%s\\n' \"\$line\" >> \"\$HOME/.ssh/authorized_keys\"\necho pikkumies-recv-installed\n";
        file_put_contents($dest->path('install.sh.new'), $script);
        $out = (string) tempnam(sys_get_temp_dir(), 'pk-remote-out');
        $argv = array_merge(RemoteDest::base($d, $dest->path('known_hosts.new'), 'yes'), ['-o', 'BatchMode=no',
            '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=keyboard-interactive,password', '-o', 'NumberOfPasswordPrompts=1',
            '--', $d['host'], 'sh', '-s']);
        $r = RemotePipe::run([$argv], $dest->path('install.sh.new'), $out, -1, ['SSH_ASKPASS' => $askpass, 'SSH_ASKPASS_REQUIRE' => 'force',
            'DISPLAY' => getenv('DISPLAY') !== false ? getenv('DISPLAY') : ':0', self::ENV => $secret]);
        $said = (string) @file_get_contents($out);
        @unlink($out);
        if ($r['codes'][0] !== 0 || strpos($said, 'pikkumies-recv-installed') === false) {
            throw new CliError("the login to {$d['user']}@{$d['host']} or the install failed (exit {$r['codes'][0]}): " . ToolText::tail($r['err'][0]),
                'check the account name and the password; the remote shell must offer sh. Nothing is kept here');
        }
    }

    /**
     * The new key must log in and `df` must come back in the receiver's own form (avail_kib N): a shell would run the
     * system df instead, so this proves the forced command, and with it the restriction, is in effect.
     */
    private static function verify(RemoteDest $dest, array $d)
    {
        list($code, $out) = $dest->call($d, ['df'], '.new');
        if ($code !== 0 || !preg_match('/^avail_kib \d+$/', trim($out))) {
            throw new CliError('the new key is not limited to the backup receiver (or does not log in), so it was deleted here; nothing set up',
                "on the backup machine remove the line ending in pikkumies-backup-{$d['label']} from ~/.ssh/authorized_keys; the server may"
                . ' ignore restrict/command= options');
        }
    }

    private static function clean(RemoteDest $dest, array $files)
    {
        foreach ($files as $f) {
            @unlink($dest->path($f));
        }
    }
}
