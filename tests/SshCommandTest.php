<?php
/**
 * hooks/guard/ssh-connect's reader (ADR-0068): which hosts a shell command connects to over SSH. Each row is a command and
 * the names expected per connection ('host+hop' joined), 'unreadable', or [] for no connection; written out by hand from
 * how OpenSSH, scp, rsync, sshfs, mosh and git read their arguments, not from the code, so a changed rule fails a row.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/ssh-connect/SshCommand.php';

if (!function_exists('sshReadNames')) {
    /** The names per connection, each connection's names joined with +, or 'unreadable'. */
    function sshReadNames($command)
    {
        try {
            $r = SshCommand::read($command);
        } catch (SshUnreadable $e) {
            return 'unreadable';
        }
        $out = [];
        foreach ($r['conns'] as $c) {
            $out[] = implode('+', array_values(array_unique($c['names'])));
        }
        return array_values(array_unique($out));
    }

    /** Every route the reader reports for $command, its connections' and its own. */
    function sshReadRoutes($command)
    {
        $r = SshCommand::read($command);
        $routes = $r['routes'];
        foreach ($r['conns'] as $c) {
            $routes = array_merge($routes, $c['routes']);
        }
        return $routes;
    }

    function sshReadTable(array $rows)
    {
        foreach ($rows as $command => $want) {
            $got = sshReadNames($command);
            check($got === $want, "$command: got " . json_encode($got) . ', want ' . json_encode($want));
        }
    }
}

return [
    'ssh reader: options before and after the host, -o HostName in every form with %h, ssh:// and the user and port ignored' => function () {
        sshReadTable([
            'ssh nas' => ['nas'], 'ssh -p 2222 -l bob nas.example.org uptime' => ['nas.example.org'], 'ssh bob@nas' => ['nas'],
            'ssh nas -o HostName=evil' => ['nas+evil'], 'ssh -oHostName=evil nas' => ['nas+evil'], 'ssh -o "hostname evil" nas' => ['nas+evil'],
            'ssh -o HostName=%h.evil nas' => ['nas+nas.evil'], 'ssh -o HostName=%%x nas' => 'unreadable', 'ssh -o HostName=%r nas' => 'unreadable',
            'ssh ssh://bob@nas:2222' => ['nas'], 'ssh -t nas ls -la' => ['nas'], 'ssh -- nas uptime' => ['nas'], '/usr/bin/ssh nas' => ['nas'],
        ]);
    },
    'ssh reader: names are normalised (case, trailing dot, IPv6, mapped IPv4); inet_aton spellings and zone ids are unreadable' => function () {
        sshReadTable([
            'ssh NAS.Example.ORG.' => ['nas.example.org'], 'ssh 192.168.1.1' => ['192.168.1.1'], 'ssh ssh://[2001:DB8:0::1]:22' => ['2001:db8::1'],
            'ssh ::ffff:10.0.0.1' => ['10.0.0.1'], 'ssh 3232235777' => 'unreadable', 'ssh 127.1' => 'unreadable', 'ssh 0300.0250.1.1' => 'unreadable',
            'ssh 0x7f000001' => 'unreadable', 'ssh fe80::1%eth0' => 'unreadable', 'ssh nas:22' => 'unreadable',
        ]);
    },
    'ssh reader: -J, ProxyJump, -W and a ProxyCommand ssh hop are names; a ProxyCommand without one is a route' => function () {
        sshReadTable([
            'ssh -J a,bob@b:22 nas' => ['nas+a+b'], 'ssh -o ProxyJump=hop nas' => ['nas+hop'], 'ssh -o ProxyJump=none nas' => ['nas'],
            'ssh -W target:22 bastion' => ['bastion+target'], 'ssh -o "ProxyCommand ssh -W %h:%p bastion" nas' => ['nas+bastion'],
        ]);
        check(sshReadNames('ssh -o "ProxyCommand nc %h %p" nas') === ['nas'] && sshReadRoutes('ssh -o "ProxyCommand nc %h %p" nas') === ['ProxyCommand nc %h %p'], 'nc ProxyCommand is a route');
        check(sshReadRoutes('ssh -F cfg nas') === ['-F cfg'] && sshReadRoutes('ssh -F /dev/null nas') === [], '-F');
        check(sshReadRoutes('sftp -S prog nas') === ['-S prog'] && sshReadRoutes('ssh -o ControlPath=/tmp/c nas') === ['-o ControlPath /tmp/c'], '-S and ControlPath');
    },
    'ssh reader: scp, rsync, sshfs operands; rsync -e; the rsync daemon forms are not SSH' => function () {
        sshReadTable([
            'scp file nas:/x' => ['nas'], 'scp -r bob@nas:/a ./b' => ['nas'], 'scp -P 22 a b' => [], 'scp scp://u@nas:22/p .' => ['nas'],
            'scp a [2001:db8::1]:/x' => ['2001:db8::1'], 'scp -o HostName=evil f nas:/x' => ['nas+evil'], 'scp -J hop f nas:/x' => ['nas+hop'],
            'rsync -av src/ nas:dst/' => ['nas'], 'rsync -av src/ nas::module' => [], 'rsync rsync://nas/mod .' => [],
            "rsync -e 'ssh -J hop' src nas:dst" => ['nas+hop'], "rsync -avze 'ssh -p 22' src nas:dst" => ['nas'], 'rsync -- a nas:b' => ['nas'],
            'sshfs nas:/data /mnt/x -o reconnect' => ['nas'], 'mosh nas' => ['nas'], 'mosh --ssh="ssh -J hop" nas' => ['nas+hop'],
            'autossh -M 0 -N nas' => ['nas'], 'ssh-copy-id -i k.pub nas' => ['nas'], 'ssh-keyscan nas,other' => ['nas', 'other'], 'sftp bob@nas:/dir' => ['nas'],
        ]);
        check(sshReadRoutes('rsync --rsh=rsh src nas:dst') === ['rsync --rsh rsh'] && sshReadRoutes('sshfs nas:/ /m -o ProxyCommand=x') === ['sshfs -o ProxyCommand=x'], 'rsh and sshfs routes');
    },
    'ssh reader: git remotes by URL or user@host:path; host:path only where git takes a repository; core.sshCommand is a route' => function () {
        sshReadTable([
            'git clone git@git.example.org:a/b.git' => ['git.example.org'], 'git clone nas:repo.git' => ['nas'], 'git clone --depth 1 nas:repo.git' => ['nas'],
            'git clone ssh://git@host:22/a.git' => ['host'], 'git fetch git+ssh://nas/r' => ['nas'], 'git push origin main:main' => [],
            'git show HEAD:README.md' => [], 'git status' => [], 'git log --format=%h:%s' => [],
        ]);
        check(sshReadRoutes("git -c core.sshCommand='ssh -J x' fetch") !== [] && sshReadRoutes("GIT_SSH_COMMAND='ssh -i k' git fetch") === ['GIT_SSH_COMMAND=ssh -i k'], 'git routes');
        check(sshReadRoutes('export RSYNC_RSH=x') === ['RSYNC_RSH=x'], 'RSYNC_RSH');
    },
    'ssh reader: wrappers, sh -c, su -c, eval, watch, substitutions, text fed to a shell and a nested remote ssh all count' => function () {
        sshReadTable([
            'sudo -u exampleuser ssh nas' => ['nas'], 'timeout 10 ssh nas' => ['nas'], 'env A=1 ssh nas' => ['nas'], 'nice -n 5 ssh nas' => ['nas'],
            'nohup ssh nas &' => ['nas'], 'watch -n 5 ssh nas uptime' => ['nas'], 'sshpass -p x ssh nas' => ['nas'], "env -S 'ssh -p 2' nas" => ['nas'],
            "bash -c 'ssh nas'" => ['nas'], 'sh -lc "ssh nas"' => ['nas'], "su -c 'ssh evil' bob" => ['evil'], 'eval "ssh evil"' => ['evil'],
            "echo 'ssh evil' | sh" => ['evil'], 'bash <<< "ssh evil"' => ['evil'], "ssh nas 'ssh evil'" => ['nas', 'evil'],
            'ls; ssh nas' => ['nas'], 'true && ssh nas' => ['nas'], 'echo $(ssh evil uptime)' => ['evil'], 'echo `ssh evil`' => ['evil'],
            'if true; then ssh nas; fi' => ['nas'], "cat <<EOF\nssh evil\nEOF" => ['evil'], 'ssh -i "$KEY" nas' => ['nas'],
        ]);
    },
    'ssh reader: a host from a variable, a substitution, a glob or brace, input, an unknown option or a stored program is unreadable' => function () {
        foreach (['ssh $HOST', 'ssh "$H"', 'ssh $(cat h)', 'ssh `cat h`', 'ssh nas{1,2}', 'ssh n*', 'xargs ssh', 'ssh -Z nas', 'X=ssh', 'alias s=ssh',
            'ssh -P tag', 'scp "$F" nas:/x', 'for h in a b; do ssh $h; done', 'f() { ssh "$@"; }', '/usr/bin/ss? nas', "ssh 'nas", 'ssh-keyscan -f hosts'] as $c) {
            check(sshReadNames($c) === 'unreadable', "$c must be unreadable: " . json_encode(sshReadNames($c)));
        }
    },
    'ssh reader: commands that only name ssh stay silent' => function () {
        foreach (['systemctl status ssh', 'journalctl -u ssh -n 50', 'cat ~/.ssh/config', 'echo "ssh nas"', 'which ssh', 'man ssh', 'ssh -V', 'ssh -G nas',
            'ssh-keygen -t ed25519', 'ssh-add -l', 'grep ssh README.md', 'command -v ssh', 'ssh -Q cipher', 'ls ~/.ssh'] as $c) {
            check(sshReadNames($c) === [], "$c must name no connection: " . json_encode(sshReadNames($c)));
        }
    },
    'ssh reader: a regex engine error is unreadable, never a silent no-match, in the reader and in SshLists::host' => function () {
        check(SshUnreadable::check(0) === 0 && SshUnreadable::check([]) === [], 'a real result passes through');
        foreach ([false, null] as $bad) {
            $threw = false;
            try {
                SshUnreadable::check($bad);
            } catch (SshUnreadable $e) {
                $threw = true;
            }
            check($threw, 'an engine error must throw: ' . var_export($bad, true));
        }
        $old = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $got = sshReadNames('ssh -o HostName=evil nas');
            $host = SshLists::host('nas');
        } finally {
            ini_set('pcre.backtrack_limit', $old[0]);
            ini_set('pcre.jit', $old[1]);
        }
        check($got === 'unreadable' && $host === null, 'with the engine failing: ' . json_encode([$got, $host]));
    },
];
