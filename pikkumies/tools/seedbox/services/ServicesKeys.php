<?php
/**
 * ServicesKeys: keys, key lines and host-key pinning for `services` (ADR-0084), shared by setup, accept, pubkey and test.
 * The host key is recorded by a connection that offers no login at all (StrictHostKeyChecking=accept-new into the
 * service's own known_hosts), as backup remote does (ADR-0049); every later call uses StrictHostKeyChecking=yes.
 */
class ServicesKeys
{
    const SERVE = 'php ~/pikkumies/tools/seedbox/services/serve.php';
    const HOST_KEY_ALGS = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512'];
    const PUB_RE = '#^(ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|ssh-rsa) ([A-Za-z0-9+/]{40,8192}={0,3})( [A-Za-z0-9._@:+=-]{1,100})?\z#';

    /** This box's name in a peer's key line: short host name and account, so several boxes can share one target. */
    public static function label()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $who = is_array($pw) && $pw['name'] !== '' ? $pw['name'] : (string) $uid;
        $label = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', explode('.', php_uname('n'))[0] . '-' . $who), '.-');
        return $label !== '' ? substr($label, 0, 64) : 'pikkumies';
    }

    public static function checkLabel($l)
    {
        if (!is_string($l) || !preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $l)) {
            throw new CliError('--label must be 1-64 letters, digits, . _ or -', 'the name of the box this key belongs to, e.g. hdd1-xyzjohn');
        }
        return $l;
    }

    /**
     * A public key line pasted by the user: exactly one ssh-ed25519, ECDSA or RSA public key whose base64 holds the same
     * type name. A private key, several lines or options in front are refused. Returns [type, base64].
     */
    public static function publicKey($line)
    {
        $line = trim((string) $line);
        if (stripos($line, 'PRIVATE KEY') !== false || strpos($line, "\n") !== false || strpos($line, "\r") !== false) {
            throw new CliError('that is not one public key line (a private key or several lines were given); nothing was written',
                'paste only the line from `services pubkey <nick>` on the other box, starting with ssh-ed25519; never a private key');
        }
        $blob = preg_match(self::PUB_RE, $line, $m) ? base64_decode($m[2], true) : false;
        $len = $blob !== false && strlen($blob) > 4 ? unpack('N', substr($blob, 0, 4))[1] : 0;
        if ($blob === false || $len !== strlen($m[1]) || substr($blob, 4, $len) !== $m[1]) {
            throw new CliError('that is not a valid ssh-ed25519, ECDSA or RSA public key line; nothing was written', 'paste the whole line from `services pubkey <nick>`');
        }
        return [$m[1], $m[2]];
    }

    /**
     * The authorized_keys line for $transport (comment pikkumies-<label>, which also names it for removal). $permitOpen: the
     * "host:port" list from permitOpen(), each a permitopen= option on a tunnel key, so it forwards only there (ADR-0107).
     */
    public static function keyLine($transport, $label, $allowDelete, $type, $b64, array $permitOpen = [])
    {
        $tail = "$type $b64 pikkumies-$label";
        if ($transport === 'pikkumies') {
            return 'restrict,command="' . self::SERVE . " $label" . ($allowDelete ? ' --allow-delete' : '') . "\" $tail";
        }
        if ($transport !== 'tunnel') {
            return "no-agent-forwarding,no-X11-forwarding $tail";
        }
        $opens = '';
        foreach ($permitOpen as $hp) {
            $opens .= ',permitopen="' . $hp . '"';
        }
        return "restrict,port-forwarding$opens,command=\"/bin/false\" $tail";
    }

    /**
     * The TCP endpoints a tunnel key may forward to, as permitopen takes them ("127.0.0.1:5000", "[::1]:5000"): each client's
     * `endpoint` written tcp://H:P or H:P, or its `port` alone (127.0.0.1:P). A unix socket, an https `url` or no endpoint adds
     * none. sshd applies permitopen to TCP forwards and, once any is set, refuses every unix-socket forward of that key too
     * (OpenSSH channels.c, open_match and channel_permit_all; ADR-0107), so a service with ANY socket endpoint (a `socket` key,
     * or an `endpoint` that is a path) gets none: permitopen would silently take its socket forward away. A malformed TCP
     * endpoint refuses, never a silently unscoped key.
     */
    public static function permitOpen(array $svc)
    {
        $out = [];
        $socket = false;
        foreach (isset($svc['clients']) && is_array($svc['clients']) ? $svc['clients'] : [] as $c => $conf) {
            $socket = $socket || (is_array($conf) && isset($conf['socket']) && is_string($conf['socket']) && $conf['socket'] !== '');
            $ep = is_array($conf) && isset($conf['endpoint']) && is_string($conf['endpoint']) ? $conf['endpoint'] : '';
            if ($ep === '' && is_array($conf) && isset($conf['port']) && $conf['port'] !== null && $conf['port'] !== '') {
                $ep = '127.0.0.1:' . $conf['port'];
            }
            if ($ep !== '' && (in_array($ep[0], ['/', '~'], true) || preg_match('#^(unix|file)://#i', $ep))) {
                $socket = true; // a socket path: no TCP forward, and no permitopen for this key at all
                continue;
            }
            if ($ep === '' || (strpos($ep, '://') !== false && stripos($ep, 'tcp://') !== 0)) {
                continue; // another kind of address: no TCP forward
            }
            $hp = stripos($ep, 'tcp://') === 0 ? substr($ep, 6) : $ep;
            $m = [];
            $host = preg_match('#^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+):([0-9]{1,5})\z#', $hp, $m) ? SshLists::host($m[1]) : null;
            if ($host === null || (int) $m[2] < 1 || (int) $m[2] > 65535) {
                throw new CliError("clients.$c endpoint " . json_encode($ep) . ' is not a TCP host:port the key could be limited to; nothing was set up',
                    'write it as tcp://127.0.0.1:<port> (or a unix socket path), then run setup again');
            }
            $out[] = (strpos($host, ':') !== false ? "[$host]" : $host) . ':' . (int) $m[2];
        }
        return $socket ? [] : array_values(array_unique($out));
    }

    /** Makes key$suffix / key$suffix.pub in $dir (ed25519, no passphrase); returns [type, base64]. */
    public static function keygen($dir, $suffix)
    {
        $keygen = ToolProc::need('ssh-keygen', 'install openssh-client');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("cannot create $dir", 'check that pikkumies/local is writable by this account');
        }
        @chmod($dir, 0700);
        @unlink("$dir/key$suffix");
        @unlink("$dir/key$suffix.pub");
        $r = ToolProc::run([$keygen, '-q', '-t', 'ed25519', '-N', '', '-C', 'pikkumies-' . self::label(), '-f', "$dir/key$suffix"]);
        $pub = trim((string) @file_get_contents("$dir/key$suffix.pub"));
        if ($r['code'] !== 0 || !preg_match('#^ssh-ed25519 ([A-Za-z0-9+/=]+)( |$)#', $pub, $m)) {
            throw new CliError('ssh-keygen could not make a key', 'check ssh-keygen and the disk space');
        }
        @chmod("$dir/key$suffix", 0600);
        @chmod("$dir/key$suffix.pub", 0600);
        return ['ssh-ed25519', $m[1]];
    }

    /**
     * Pins the host key into $kh with login-less connections (one per accepted type when --fingerprint does not match the
     * first). Returns [fingerprint, key type]; a CliError when the host cannot be reached or no key matches $want.
     */
    public static function pinHost(array $svc, $kh, $want)
    {
        if ($want !== null && !preg_match('#^SHA256:[A-Za-z0-9+/]{43}\z#', $want)) {
            throw new CliError('--fingerprint takes the SHA256:... form', 'the owner of that machine reads it with ssh-keygen -lf on its host key');
        }
        $keygen = ToolProc::need('ssh-keygen', 'install openssh-client');
        $seen = [];
        foreach (array_merge([null], self::HOST_KEY_ALGS) as $alg) {
            file_put_contents($kh, '');
            chmod($kh, 0600);
            $probe = array_merge(Services::baseArgv(['host_key_alg' => $alg] + $svc, $kh, 'accept-new'), ['-o', 'BatchMode=yes',
                '-o', 'PubkeyAuthentication=no', '-o', 'PasswordAuthentication=no', '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'GSSAPIAuthentication=no', '--', Services::host($svc['host']), 'true']);
            $r = ToolProc::run($probe);
            $l = ToolProc::run([$keygen, '-l', '-E', 'sha256', '-f', $kh]);
            $fp = preg_match('#\b(SHA256:[A-Za-z0-9+/]{43})\b#', $l['out'], $m) ? $m[1] : null;
            $line = explode(' ', trim((string) @file_get_contents($kh)));
            $types = ['ssh-ed25519' => 'ssh-ed25519', 'ecdsa-sha2-nistp256' => 'ecdsa-sha2-nistp256', 'ssh-rsa' => 'rsa-sha2-512'];
            if ($fp === null && $alg === null) {
                throw new CliError("could not reach {$svc['host']} port {$svc['port']} to read its host key: " . Services::sshError($r['err'], $r['code']),
                    'check the host name, the port and the network; nothing was sent');
            }
            if ($fp !== null && ($want === null || $fp === $want)) {
                return [$fp, isset($line[1], $types[$line[1]]) ? $types[$line[1]] : null];
            }
            $seen[] = $fp;
        }
        throw new CliError("no host key of {$svc['host']} (" . implode(', ', array_unique(array_filter($seen))) . ") is $want; nothing was sent",
            'someone may be in between, or the fingerprint is wrong: report the mismatch in your answer; it is a security matter for the owner of that machine, never something to work around');
    }

    /** `pubkey <nick>`: this box's key for that service (made when missing) and the command to run on the other box. */
    public static function pubkey(array $args, array $opt)
    {
        $svc = Services::get(isset($args[0]) ? $args[0] : '');
        $dir = Services::secretDir($svc['nick']);
        $made = false;
        if (!is_file("$dir/key") || !is_file("$dir/key.pub")) {
            self::keygen($dir, '');
            $made = true;
        }
        $parts = explode(' ', trim((string) file_get_contents("$dir/key.pub")));
        if (count($parts) < 2 || $parts[0] !== 'ssh-ed25519' || !preg_match('#^[A-Za-z0-9+/=]+$#', $parts[1])) {
            throw new CliError("$dir/key.pub is not an ed25519 public key", "run `services setup {$svc['nick']}` or remove the service and add it again");
        }
        $pub = "ssh-ed25519 {$parts[1]} pikkumies-" . self::label();
        $flag = !empty($opt['allow-delete']) ? ' --allow-delete' : '';
        return [['service' => $svc['nick'], 'public_key' => $pub, 'made' => $made,
            'next' => "on {$svc['host']}, as {$svc['user']}, run: php ~/pikkumies/tools/seedbox/services/services.php accept '$pub' --label "
                . self::label() . "$flag --install ; then here: services test {$svc['nick']}"]];
    }

    /** `accept "<public key>" --label L [--allow-delete] [--install]`: the forced-command line for a peer's key on THIS box. */
    public static function accept(array $args, array $opt, $home)
    {
        if (count($args) !== 1) {
            throw new CliError('accept takes one public key line, quoted', "e.g. accept 'ssh-ed25519 AAAA... pikkumies-box' --label box");
        }
        list($type, $b64) = self::publicKey($args[0]);
        $label = self::checkLabel(isset($opt['label']) ? $opt['label'] : null);
        $line = self::keyLine('pikkumies', $label, !empty($opt['allow-delete']), $type, $b64);
        $rec = ['authorized_keys_line' => $line, 'label' => $label, 'allow_delete' => !empty($opt['allow-delete']), 'installed' => false];
        if (empty($opt['install'])) {
            return [$rec + ['next' => 'add this line to ~/.ssh/authorized_keys on this box, or run accept again with --install']];
        }
        $ssh = "$home/.ssh";
        $file = "$ssh/authorized_keys";
        $old = umask(0077);
        try {
            if (!is_dir($ssh) && !@mkdir($ssh, 0700)) {
                throw new CliError("cannot create $ssh", 'check the home directory permissions');
            }
            if (is_link($file)) {
                throw new CliError("$file is a symlink; not writing through it", 'look at where it points first');
            }
            $have = is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : [];
            foreach ($have as $l) {
                if (strpos($l, " $b64") !== false && trim($l) !== $line) {
                    throw new CliError('this key already has another line in authorized_keys (maybe without the forced command); nothing was written',
                        "remove that line (it contains the same key) first, then accept again: two lines would let the key skip the restriction");
                }
            }
            if (!in_array($line, $have, true) && @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new CliError("cannot write $file", 'check the disk space and permissions');
            }
            @chmod($file, 0600);
        } finally {
            umask($old);
        }
        $ok = in_array($line, (array) file($file, FILE_IGNORE_NEW_LINES), true);
        return ['records' => [array_merge($rec, ['installed' => $ok, 'file' => '~/.ssh/authorized_keys'])], 'status' => ['verified' => $ok]];
    }

    /** `test <nick> [--fingerprint ...]`: pins the host key when none is pinned yet, then proves what the key can do. */
    public static function test(array $args, array $opt)
    {
        $svc = Services::get(isset($args[0]) ? $args[0] : '');
        Services::refuseListed($svc['host']);
        $dir = Services::secretDir($svc['nick']);
        if (!is_file("$dir/key")) {
            throw new CliError("service {$svc['nick']} has no key here", "run `services pubkey {$svc['nick']}` or `services setup {$svc['nick']}` first");
        }
        $pinned = false;
        if (!is_file("$dir/known_hosts") || trim((string) file_get_contents("$dir/known_hosts")) === '') {
            list($svc['host_key'], $svc['host_key_alg']) = self::pinHost($svc, "$dir/known_hosts", isset($opt['fingerprint']) ? (string) $opt['fingerprint'] : null);
            $svc = Services::put($svc);
            $pinned = true;
        }
        $proof = self::prove($svc, '');
        return ['records' => [['service' => $svc['nick'], 'transport' => $svc['transport'], 'host_key' => isset($svc['host_key']) ? $svc['host_key'] : null,
            'pinned_now' => $pinned] + $proof], 'status' => ['verified' => $proof['works']]];
    }

    /**
     * What the key really does: pikkumies must get serve's own `ping` answer (a shell would not give it); tunnel must NOT
     * run a command; shell must. Returns ['works' => bool, 'allow_delete' => ?, 'why' => text].
     */
    public static function prove(array $svc, $suffix)
    {
        $words = $svc['transport'] === 'pikkumies' ? ['ping'] : ['echo', 'pikkumies-shell-probe'];
        $mode = $svc['transport'];
        $probeSvc = $mode === 'tunnel' ? ['transport' => 'shell'] + $svc : $svc;
        $r = Services::exec(Services::sshArgv($svc['nick'], $words, ['service' => $probeSvc, 'suffix' => $suffix]), null, 60);
        if ($mode === 'pikkumies') {
            $first = json_decode((string) strtok($r['stdout'], "\n"), true);
            $ok = $r['rc'] === 0 && is_array($first) && isset($first['serve']) && $first['serve'] === 'pikkumies';
            return ['works' => $ok, 'allow_delete' => $ok ? !empty($first['allow_delete']) : null,
                'why' => $ok ? 'the key reaches only the forced command (serve answered ping)' : 'no serve answer: ' . Services::sshError($r['stderr'], $r['rc'])];
        }
        $ran = strpos($r['stdout'], 'pikkumies-shell-probe') !== false;
        if ($mode === 'tunnel') {
            $ok = !$ran && $r['rc'] !== 255;
            return ['works' => $ok, 'why' => $ran ? 'the key opens a shell: it is not limited to a tunnel' : ($ok ? 'the key logs in and runs no command' : Services::sshError($r['stderr'], $r['rc']))];
        }
        return ['works' => $ran, 'why' => $ran ? 'the key runs commands (shell, allowed for this service)' : Services::sshError($r['stderr'], $r['rc'])];
    }
}
