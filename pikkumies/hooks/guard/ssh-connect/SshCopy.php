<?php
/**
 * SshCopy: rsync, sshfs and mosh, for SshPrograms (ADR-0068). rsync and sshfs connect to every [user@]host:path operand
 * (rsync host::module and rsync:// are the rsync daemon, not SSH); rsync -e and mosh --ssh name the ssh command, read as
 * one with the host after it; sshfs -o items that change how it connects are routes; mosh runs its command remotely.
 */
class SshCopy
{

    /** scp-like copies (rsync, sshfs) and mosh: every remote operand, with the ssh command rsync -e or mosh --ssh names. */
    public static function copy($program, array $args, $depth)
    {
        $rsh = null;
        $routes = [];
        $hosts = [];
        $rest = [];
        $valued = ['rsync' => 'eBfMT@', 'sshfs' => 'opF', 'mosh' => 'p'][$program];
        $operandsOnly = false;
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if ($program === 'mosh' && $hosts) {
                $rest = array_slice($args, $i); // mosh host command...: the command runs on that host
                break;
            }
            if ($t === '--' && !$operandsOnly) {
                $operandsOnly = true;
                continue;
            }
            if ($operandsOnly || $t === '' || $t[0] !== '-' || $t === '-') {
                $t = SshPrograms::literal($args[$i]);
                $h = $program === 'mosh' ? SshPrograms::destination('ssh', $t) : (SshUnreadable::matches('#^rsync://#i', $t) ? null : SshPrograms::remoteHost($t, $program === 'rsync'));
                if ($h !== null) {
                    $hosts[] = $h;
                }
                continue;
            }
            if (SshUnreadable::matches('/^--(rsh|ssh)(=?)(.*)\z/s', $t, $m)) {
                $rsh = $m[2] === '=' ? $m[3] : (isset($args[$i + 1]) ? $args[++$i]['text'] : '');
                continue;
            }
            if (strncmp($t, '--', 2) === 0) {
                $i += $program === 'mosh' && in_array(substr($t, 2), ['client', 'server', 'predict', 'family', 'port', 'bind-server', 'experimental-remote-ip'], true) ? 1 : 0;
                continue;
            }
            for ($k = 1; $k < strlen($t) && strpos($valued, $t[$k]) === false; $k++) {
            }
            if ($k < strlen($t)) {
                $value = $k + 1 < strlen($t) ? substr($t, $k + 1) : (isset($args[$i + 1]) ? $args[++$i]['text'] : '');
                $rsh = $t[$k] === 'e' && $program === 'rsync' ? $value : $rsh;
                $routes = array_merge($routes, self::sshfsOption($program, $t[$k], $value));
            }
        }
        $found = SshPrograms::NONE;
        foreach ($hosts as $host) {
            $via = $rsh === null ? SshPrograms::NONE : SshCommand::read("$rsh $host", $depth + 1);
            $found['conns'] = array_merge($found['conns'], $via['conns'] ?: [['program' => $program, 'names' => [$host],
                'routes' => array_merge($routes, $rsh === null ? [] : ["$program --rsh $rsh"])]]);
        }
        if ($program === 'mosh') {
            SshCommand::merge($found, SshCommand::read(implode(' ', array_column($rest, 'text')), $depth + 1));
        }
        return $found;
    }

    /** sshfs -o items that change how or where it connects, and -F with a config file: routes. */
    private static function sshfsOption($program, $c, $value)
    {
        if ($program !== 'sshfs' || !in_array($c, ['o', 'F'], true)) {
            return [];
        }
        if ($c === 'F') {
            return $value === '/dev/null' ? [] : ["sshfs -F $value"];
        }
        return SshUnreadable::matches('/(^|,)\s*(ssh_command|proxycommand|proxyjump|hostname|directport|passive|controlpath)\s*=/i', $value) ? ["sshfs -o $value"] : [];
    }
}
