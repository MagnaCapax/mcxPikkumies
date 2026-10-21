<?php
/**
 * ServicesSetup: `setup <nick>` (ADR-0084), the same order as backup remote's setup (ADR-0049). The one-time login comes
 * only from PIKKUMIES_REMOTE_PASSWORD, read first and removed from this process's environment at once; ssh gets it
 * through backup remote's askpass.php (SSH_ASKPASS, SSH_ASKPASS_REQUIRE=force), never on an argv or in a file. Then:
 * pin the host key with no login, one login (host key pinned) appends one restricted key line with umask 077, and the
 * new key must prove its limit (ServicesKeys::prove): serve's own `ping` answer for transport pikkumies. Anything short
 * of that deletes the new key here.
 */
class ServicesSetup
{
    const ENV = 'PIKKUMIES_REMOTE_PASSWORD';

    public static function run(array $args, array $opt, $askpass)
    {
        $secret = getenv(self::ENV);
        putenv(self::ENV);
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
        if (count($args) !== 1) {
            throw new CliError('setup takes <nick>', 'add the service first: services add <nick> --host H --user U');
        }
        $svc = Services::get($args[0]);
        Services::refuseListed(Services::host($svc['host']));
        $want = isset($opt['fingerprint']) ? (string) $opt['fingerprint'] : null;
        if ($svc['transport'] === 'shell' && empty($opt['allow-shell'])) {
            throw new CliError("service {$svc['nick']} is transport shell: its key would open a full shell on that machine; nothing done",
                'only when the user asked for a shell key there (e.g. for a GPU box): setup ' . $svc['nick'] . ' --allow-shell');
        }
        if ($secret === false || $secret === '') {
            throw new CliError('no remote login given; nothing done', 'pass it only as a prefix: ' . self::ENV . "='...' php <this tool> setup <nick> (never as an argument)");
        }
        $opens = $svc['transport'] === 'tunnel' ? ServicesKeys::permitOpen($svc) : []; // before any connection: a bad endpoint refuses here
        ToolProc::need('ssh', 'install openssh-client');
        $dir = Services::secretDir($svc['nick']);
        $new = ['key.new', 'key.new.pub', 'known_hosts.new', 'install.sh.new', 'install.out.new'];
        $allowDelete = !empty($opt['allow-delete']);
        try {
            self::clean($dir, $new);
            list($type, $b64) = ServicesKeys::keygen($dir, '.new');
            list($svc['host_key'], $svc['host_key_alg']) = ServicesKeys::pinHost($svc, "$dir/known_hosts.new", $want);
            $label = ServicesKeys::label();
            self::install($svc, $dir, ServicesKeys::keyLine($svc['transport'], $label, $allowDelete, $type, $b64, $opens), $secret, $askpass);
            $secret = null;
            $proof = ServicesKeys::prove($svc, '.new');
            if (!$proof['works']) {
                throw new CliError("the new key does not do what transport {$svc['transport']} needs ({$proof['why']}), so it was deleted here; nothing set up",
                    "on {$svc['host']} remove the line ending in pikkumies-$label from ~/.ssh/authorized_keys; the server may ignore restrict/command= options");
            }
            foreach (['key.new' => 'key', 'key.new.pub' => 'key.pub', 'known_hosts.new' => 'known_hosts'] as $from => $to) {
                rename("$dir/$from", "$dir/$to");
            }
        } finally {
            self::clean($dir, $new);
        }
        $svc = Services::put(['setup_at' => gmdate('c'), 'allow_delete' => $allowDelete] + $svc);
        return ['records' => [['service' => $svc['nick'], 'host_key' => $svc['host_key'], 'transport' => $svc['transport'], 'allow_delete' => $allowDelete,
            'restricted' => $svc['transport'] !== 'shell', 'next' => ['change the password of that account now: the key works without it, and changing it is what really scrubs it',
                'the password you typed has already reached the model provider and this session\'s history; it is not stored here']]], 'status' => ['verified' => true]];
    }

    /** One login runs a fixed `sh -s` script: umask 077, append the key line once (needs Pikkumies there for pikkumies). */
    private static function install(array $svc, $dir, $line, $secret, $askpass)
    {
        $check = $svc['transport'] === 'pikkumies'
            ? "[ -f \"\$HOME/pikkumies/tools/seedbox/services/serve.php\" ] || { echo pikkumies-missing; exit 3; }\n" : '';
        $script = "umask 077\nset -e\n$check" . "mkdir -p \"\$HOME/.ssh\"\nline='$line'\ntouch \"\$HOME/.ssh/authorized_keys\"\n"
            . "grep -qxF \"\$line\" \"\$HOME/.ssh/authorized_keys\" || printf '%s\\n' \"\$line\" >> \"\$HOME/.ssh/authorized_keys\"\necho pikkumies-key-installed\n";
        $argv = array_merge(Services::baseArgv($svc, "$dir/known_hosts.new", 'yes'), ['-o', 'BatchMode=no', '-o', 'PubkeyAuthentication=no',
            '-o', 'PreferredAuthentications=keyboard-interactive,password', '-o', 'NumberOfPasswordPrompts=1', '--', $svc['host'], 'sh', '-s']);
        $r = Services::exec($argv, $script, 120, ['SSH_ASKPASS' => $askpass, 'SSH_ASKPASS_REQUIRE' => 'force',
            'DISPLAY' => getenv('DISPLAY') !== false ? getenv('DISPLAY') : ':0', self::ENV => $secret]);
        if (strpos($r['stdout'], 'pikkumies-missing') !== false) {
            throw new CliError("Pikkumies is not installed on {$svc['host']} for {$svc['user']} (no ~/pikkumies/tools/seedbox/services/serve.php); nothing kept here",
                'install Pikkumies there first, or use --transport tunnel for a box without it');
        }
        if ($r['rc'] !== 0 || strpos($r['stdout'], 'pikkumies-key-installed') === false) {
            throw new CliError("the login to {$svc['user']}@{$svc['host']} or the install failed (exit {$r['rc']}): " . Services::sshError($r['stderr'], $r['rc']),
                'check the account name and the password; the remote shell must offer sh. Nothing is kept here');
        }
    }

    private static function clean($dir, array $files)
    {
        foreach ($files as $f) {
            @unlink("$dir/$f");
        }
    }
}
