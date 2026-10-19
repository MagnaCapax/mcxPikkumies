<?php
/**
 * ChangeVerbs: which shell commands change data, by verb (ADR-0067), for lib/ChangeClassify.php. verdict() answers
 * 'read' (only reads), 'write' (changes files, services, processes or packages) or 'unknown' (cannot be told from the
 * text: a shell, an interpreter, a verb not listed). Its own tables, kept apart from the guards' (guard-destructive,
 * config-change, fileops-check), which decide something else and fail closed.
 */
class ChangeVerbs
{
    const READ = 'ls ll la dir vdir cat tac head tail less more grep egrep fgrep zgrep rg ag ack wc cut tr nl fold fmt column paste join comm cmp diff od hexdump xxd strings file stat du df free uptime w who whoami id groups uname arch nproc lscpu lsblk lsusb lspci lsmod blkid findmnt ps pgrep pstree top htop iostat vmstat mpstat sar ss netstat lsof dig host nslookup ping traceroute tracepath mtr getent which whereis type realpath readlink basename dirname pwd echo printf true false test [ [[ : printenv locale tty sleep wait cd pushd popd export unset set alias unalias declare local readonly shift exit return read let md5sum sha1sum sha256sum sha512sum b2sum cksum sum base64 jq xmllint zcat xzcat bzcat zstdcat zless zmore tree lsattr getfacl namei quota last lastlog dmesg seq expr bc cal numfmt shuf rev ffprobe mediainfo history help man hash';
    const WRITE = 'rm rmdir mv cp dd truncate shred ln mkdir touch install chmod chown chgrp chattr setfacl rename unlink mkfifo mknod split csplit patch kill pkill killall useradd userdel usermod groupadd groupdel passwd chpasswd mkfs fdisk parted wipefs reboot shutdown poweroff halt scp umount swapon swapoff ed ex vi vim nano emacs';
    /** Verbs with subcommands: [read subcommands, write subcommands]; any other subcommand is unknown. */
    const SUB = [
        'git' => ['status log diff show blame grep ls-files ls-tree ls-remote rev-parse rev-list describe shortlog cat-file whatchanged help version',
            'add commit push pull reset checkout switch restore clean rm mv merge rebase cherry-pick revert init clone am apply gc prune'],
        'systemctl' => ['status show cat list-units list-unit-files list-timers list-sockets is-active is-enabled is-failed is-system-running get-default help',
            'start stop restart reload try-restart reload-or-restart enable disable mask unmask daemon-reload kill reset-failed edit set-property preset isolate link revert'],
        'docker' => ['ps images logs inspect stats version info top port history events diff search',
            'run create start stop restart kill rm rmi pull push build commit cp update rename pause unpause prune'],
        'apt' => ['list show search policy depends rdepends madison changelog', 'install remove purge upgrade full-upgrade dist-upgrade autoremove update clean autoclean'],
        'pip' => ['list show freeze search', 'install uninstall download'],
        'npm' => ['list ls view info outdated search why', 'install uninstall remove update ci link publish add'],
        'composer' => ['show info outdated search why licenses', 'install update require remove'],
        'hostnamectl' => ['status', 'set-hostname set-chassis set-icon-name'],
        'timedatectl' => ['status show list-timezones', 'set-time set-timezone set-ntp set-local-rtc'],
    ];
    const ALIAS = ['podman' => 'docker', 'apt-get' => 'apt', 'dnf' => 'apt', 'yum' => 'apt', 'pip3' => 'pip', 'pnpm' => 'npm', 'yarn' => 'npm'];
    /** Options of a verb that take the next word as their value, so that word is never read as the subcommand. */
    const VALUED = ['git' => ['-C', '-c', '--git-dir', '--work-tree'], 'systemctl' => ['-H', '-M', '-t', '-p', '--type', '--property'],
        'docker' => ['--context', '-H', '--host', '--config', '-c'], 'npm' => ['--prefix'], 'composer' => ['-d', '--working-dir']];

    /** 'read', 'write' or 'unknown' for one simple command: $verb (a base name) and its argument words. */
    public static function verdict($verb, array $args)
    {
        $verb = isset(self::ALIAS[$verb]) ? self::ALIAS[$verb] : $verb;
        if (in_array($verb, explode(' ', self::READ), true)) {
            return 'read';
        }
        if (in_array($verb, explode(' ', self::WRITE), true)) {
            return 'write';
        }
        if (isset(self::SUB[$verb])) {
            return self::sub($verb, $args);
        }
        $opt = function ($re) use ($args) {
            return (bool) preg_grep($re, $args);
        };
        $positional = array_values(array_filter($args, function ($a) {
            return $a === '-' || $a === '' || $a[0] !== '-';
        }));
        switch ($verb) {
            case 'sed':
                return $opt('/^(-[a-zA-Z]*i|--in-place)/') ? 'write' : 'read';
            case 'awk': case 'gawk': case 'mawk': case 'nawk':
                return $opt('/^(-i|--include=?)inplace$/') || (in_array('-i', $args, true) && in_array('inplace', $args, true)) ? 'write' : 'read';
            case 'perl': case 'ruby':
                return $opt('/^-[a-zA-Z]*i/') ? 'write' : 'unknown';
            case 'sort':
                return $opt('/^(-[a-zA-Z]*o|--output)/') ? 'write' : 'read';
            case 'uniq':
                return count($positional) >= 2 ? 'write' : 'read';
            case 'tee':
                return array_diff($positional, ['/dev/null', '-']) ? 'write' : 'read';
            case 'yq':
                return $opt('/^(-[a-zA-Z]*i|--inplace)/') ? 'write' : 'read';
            case 'curl':
                return self::curl($args);
            case 'wget':
                return $opt('/^--spider$/') || preg_match('/(^| )(-[a-zA-Z]*O ?-|--output-document=?-)( |$)/', implode(' ', $args)) ? 'read' : 'write';
            case 'tar':
                $mode = isset($args[0]) && $args[0] !== '' && $args[0][0] !== '-' ? '-' . $args[0] : implode(' ', preg_grep('/^-/', $args));
                return preg_match('/--(extract|create|append|update|delete|concatenate|get)\b|(^| )-[a-zA-Z]*[xcruA]/', $mode) ? 'write'
                    : (preg_match('/--list\b|(^| )-[a-zA-Z]*t/', $mode) ? 'read' : 'unknown');
            case 'unzip':
                return $opt('/^-[a-zA-Z]*[ltvZp]/') ? 'read' : 'write';
            case 'zip':
                return 'write';
            case 'gzip': case 'gunzip': case 'bzip2': case 'bunzip2': case 'xz': case 'unxz': case 'zstd': case 'unzstd': case 'lz4':
                return $opt('/^(-[a-zA-Z]*[clt]|--(stdout|list|test))/') ? 'read' : 'write';
            case 'rsync':
                return $opt('/^(-[a-zA-Z]*n[a-zA-Z]*|--dry-run|--list-only)$/') ? 'read' : 'write';
            case 'crontab':
                return $opt('/^-[a-zA-Z]*l/') && !$opt('/^-[a-zA-Z]*[er]/') ? 'read' : 'write';
            case 'hostname':
                return $positional ? 'write' : 'read';
            case 'date':
                return $opt('/^(-[a-zA-Z]*s|--set)/') ? 'write' : 'read';
            case 'mount':
                return $positional ? 'write' : 'read';
            case 'ip':
                return preg_grep('/^(add|del|delete|set|change|replace|flush|append)$/', $args) ? 'write' : 'read';
            case 'iptables': case 'ip6tables': case 'nft': case 'ufw':
                return $opt('/^(-[a-zA-Z]*[LS]|--list)/') || preg_grep('/^(list|status|show)$/', $args) ? 'read' : 'write';
            case 'journalctl':
                return $opt('/^--(vacuum|rotate|flush|sync|relinquish)/') ? 'write' : 'read';
            case 'service':
                return preg_grep('/^(status|--status-all)$/', $args) ? 'read' : 'write';
            case 'dpkg':
                return $opt('/^(-[iraP]|--(install|remove|purge|configure|unpack|set-selections))/') ? 'write'
                    : ($opt('/^(-[lLsSp]|--(list|listfiles|status|search|get-selections|print))/') ? 'read' : 'unknown');
            case 'command':
                return $opt('/^-[vV]$/') ? 'read' : 'unknown';
            case 'env':
                return $args ? 'unknown' : 'read';
        }
        return 'unknown';
    }

    /** A subcommand verb: the first word that is not an option or an option's value. */
    private static function sub($verb, array $args)
    {
        $valued = isset(self::VALUED[$verb]) ? self::VALUED[$verb] : [];
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $a = $args[$i];
            if (in_array($a, $valued, true)) {
                $i++;
                continue;
            }
            if ($a === '' || $a[0] === '-') {
                continue;
            }
            $rest = array_slice($args, $i + 1);
            if ($verb === 'git' && in_array($a, ['branch', 'tag', 'remote', 'stash', 'config', 'worktree', 'submodule'], true)) {
                return self::gitList($a, $rest);
            }
            list($read, $write) = self::SUB[$verb];
            return in_array($a, explode(' ', $read), true) ? 'read' : (in_array($a, explode(' ', $write), true) ? 'write' : 'unknown');
        }
        return $verb === 'git' || $verb === 'systemctl' ? 'read' : 'unknown'; // bare `git` / `systemctl` print help or the unit list
    }

    /** git's listing subcommands: a read only in their listing forms. */
    private static function gitList($sub, array $rest)
    {
        $plain = array_values(array_filter($rest, function ($a) {
            return $a !== '' && $a[0] !== '-';
        }));
        $flags = ' ' . implode(' ', $rest) . ' ';
        switch ($sub) {
            case 'config':
                return preg_match('/ (--get\S*|--list|-l) /', $flags) ? 'read' : 'write';
            case 'stash':
            case 'worktree':
            case 'submodule':
                return isset($plain[0]) && in_array($plain[0], ['list', 'show', 'status'], true) ? 'read' : 'write';
            case 'remote':
                return !$plain || in_array($plain[0], ['show', 'get-url'], true) ? 'read' : 'write';
        }
        // branch, tag: listing unless a name is given or a delete/move/copy flag is
        return preg_match('/ (-[a-zA-Z]*[dDmMcCf]|--(delete|move|copy|force)) /', $flags) || ($plain && !preg_match('/ (--list|-l|-a|-r|--contains|--merged) /', $flags)) ? 'write' : 'read';
    }

    /** curl only reads unless it saves to a file, uploads or sends data with a method other than GET or HEAD. */
    private static function curl(array $args)
    {
        foreach ($args as $i => $a) {
            if (preg_match('/^--(output|remote-name|upload-file|data|json|form|remote-name-all)/', $a)) {
                return 'write';
            }
            if (preg_match('/^--request(?:=(.*))?$/', $a, $m) || preg_match('/^-[a-zA-Z]*X(.*)$/', $a, $m)) {
                $method = isset($m[1]) && $m[1] !== '' ? $m[1] : (isset($args[$i + 1]) ? $args[$i + 1] : '');
                if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
                    return 'write';
                }
            } elseif (preg_match('/^-[a-zA-Z]*[oOTdF]/', $a) && strpos($a, '--') !== 0) {
                return 'write';
            }
        }
        return 'read';
    }
}
