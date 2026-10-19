<?php
/**
 * SshOpenssh: the OpenSSH programs' arguments, for SshPrograms (ADR-0068): ssh, sftp, scp, ssh-copy-id and ssh-keyscan
 * with OpenSSH 10.0's option letters exactly (another letter is unreadable, since an option that takes a value would
 * shift where the host is read); ssh reads options after the destination too. -o HostName (%h filled in), ProxyJump, -J
 * and -W add names; a ProxyCommand adds its ssh hop or is a route, like -F <file>, -S and ControlPath; the remote command
 * and RemoteCommand are read again.
 */
class SshOpenssh
{

    /** The OpenSSH programs: options, then the destination, operands or hosts. */
    public static function openssh($program, array $args, $depth)
    {
        $st = ['none' => false, 'hops' => [], 'routes' => [], 'hostName' => [], 'proxy' => [], 'remote' => [], 'dashes' => false];
        $i = 0;
        self::options($program, $args, $i, $st);
        $operands = array_slice($args, $i);
        if ($program === 'ssh') {
            $operands = isset($args[$i]) ? [$args[$i++]] : [];
            if (!$st['dashes'] && $operands) {
                self::options($program, $args, $i, $st); // ssh reads options after the destination as well
            }
            $remote = array_column(array_slice($args, $i), 'text');
            $st['remote'][] = implode(' ', $remote);
        }
        if ($st['none']) {
            return SshPrograms::NONE; // -G, -V, -Q and -O print or talk to a running master; no new connection
        }
        $hosts = [];
        foreach ($operands as $w) {
            $t = SshPrograms::literal($w);
            if ($program === 'scp') {
                $h = SshUnreadable::matches('#^scp://#i', $t) ? SshPrograms::hop(explode('/', substr($t, 6))[0]) : SshPrograms::remoteHost($t);
                $hosts = $h === null ? $hosts : array_merge($hosts, [$h]);
            } elseif ($program === 'ssh-keyscan') {
                $hosts = array_merge($hosts, array_map(['SshPrograms', 'host'], explode(',', $t)));
            } elseif (!$hosts) {
                $hosts[] = SshPrograms::destination($program, $t);
            }
        }
        if (!$hosts && $program !== 'scp') {
            throw new SshUnreadable("$program names no host here (one taken from input or a variable cannot be checked)");
        }
        $found = SshPrograms::NONE;
        foreach ($hosts as $host) {
            $conn = ['program' => $program, 'names' => array_merge([$host], $st['hops']), 'routes' => $st['routes']];
            foreach ($st['hostName'] as $v) {
                $conn['names'][] = SshPrograms::host(self::percent($v, $host, true));
            }
            foreach ($st['proxy'] as $v) {
                $via = SshCommand::read(self::percent($v, $host, false), $depth + 1);
                foreach ($via['conns'] as $hop) {
                    $conn['names'] = array_merge($conn['names'], $hop['names']);
                }
                $conn['routes'] = array_merge($conn['routes'], $via['routes'], $via['conns'] ? [] : ["ProxyCommand $v"]);
            }
            $found['conns'][] = $conn;
        }
        foreach ($st['remote'] as $text) {
            SshCommand::merge($found, SshCommand::read($text, $depth + 1)); // ssh nas 'ssh other' connects to other too
        }
        return $found;
    }

    /** Consume options from $i; stops after -- or at the first word that is not an option. */
    private static function options($program, array $args, &$i, array &$st)
    {
        list($flags, $valued) = SshPrograms::OPTIONS[$program];
        for ($n = count($args); $i < $n; $i++) {
            $t = $args[$i]['text'];
            if ($t === '--') {
                list($i, $st['dashes']) = [$i + 1, true];
                return;
            }
            if (strlen($t) < 2 || $t[0] !== '-') {
                return;
            }
            for ($k = 1; $k < strlen($t); $k++) {
                if (strpos($flags, $t[$k]) !== false) {
                    $st['none'] = $st['none'] || ($program === 'ssh' && ($t[$k] === 'G' || $t[$k] === 'V'));
                    continue;
                }
                if (strpos($valued, $t[$k]) === false) {
                    throw new SshUnreadable("$program option -{$t[$k]} is not one this check knows");
                }
                $value = $k + 1 < strlen($t) ? substr($t, $k + 1) : (isset($args[$i + 1]) ? $args[++$i]['text'] : null);
                if ($value === null || ($args[$i]['dyn'] && strpos('oJWFSDf', $t[$k]) !== false)) {
                    throw new SshUnreadable("$program option -{$t[$k]} has no value it can read");
                }
                self::option($program, $t[$k], $value, $st);
                break;
            }
        }
    }

    private static function option($program, $c, $value, array &$st)
    {
        if ($c === 'o') {
            if (!SshUnreadable::matches('/^\s*([A-Za-z]+)\s*(?:=\s*|\s+)(.*)\z/s', $value, $m)) {
                return; // ssh refuses an option without a value
            }
            $key = strtolower($m[1]);
            $v = trim($m[2]);
            $map = ['hostname' => 'hostName', 'proxycommand' => 'proxy', 'remotecommand' => 'remote'];
            if (isset($map[$key])) {
                $st[$map[$key]][] = $v;
            } elseif ($key === 'proxyjump') {
                $st['hops'] = array_merge($st['hops'], SshPrograms::hops($v));
            } elseif ($key === 'controlpath' && strtolower($v) !== 'none') {
                $st['routes'][] = "-o ControlPath $v";
            }
        } elseif ($c === 'J') {
            $st['hops'] = array_merge($st['hops'], SshPrograms::hops($value));
        } elseif ($c === 'W' && $program === 'ssh') {
            $st['hops'][] = SshPrograms::hop($value);
        } elseif (($c === 'F' && $value !== '/dev/null') || ($c === 'S' && $value !== 'none') || ($c === 'D' && $program !== 'ssh')) {
            $st['routes'][] = "-$c $value"; // another config, a control socket or another program in between
        } elseif (($c === 'Q' || $c === 'O') && $program === 'ssh') {
            $st['none'] = true;
        } elseif ($c === 'f' && $program === 'ssh-keyscan') {
            throw new SshUnreadable('ssh-keyscan -f reads its hosts from a file');
        }
    }

    /** %h is the host typed and %% a percent sign; in a HostName any other token is unreadable, in a ProxyCommand kept. */
    private static function percent($value, $host, $strict)
    {
        return SshUnreadable::check(preg_replace_callback('/%(.)/s', function ($m) use ($host, $strict) {
            $known = ['h' => $host, 'n' => $host, '%' => '%', 'p' => '22', 'r' => 'user'];
            if (isset($known[$m[1]]) && (!$strict || $m[1] === 'h' || $m[1] === '%')) {
                return $known[$m[1]];
            }
            if ($strict) {
                throw new SshUnreadable("-o HostName holds %{$m[1]}, which this check cannot fill in");
            }
            return $m[0];
        }, $value));
    }
}
