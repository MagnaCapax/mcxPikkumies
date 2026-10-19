<?php
/**
 * SshPrograms: the hosts each SSH program connects to, read from its own arguments (ADR-0068): the OpenSSH programs in
 * SshOpenssh, rsync, sshfs and mosh in SshCopy, git in SshGit; and the readers they share. A host is read strictly
 * (SshLists::host): a word built from a variable or a command, or a name that is not plainly a host, is unreadable.
 */
require_once __DIR__ . '/SshGit.php';
require_once __DIR__ . '/SshOpenssh.php';
require_once __DIR__ . '/SshCopy.php';

class SshPrograms
{
    const NAMES = ['ssh', 'autossh', 'mosh', 'sftp', 'ssh-copy-id', 'ssh-keyscan', 'scp', 'rsync', 'sshfs', 'git'];
    /** Text that may hold one of them, or a library that speaks SSH: the hook's quick test before it loads anything else. */
    const MENTION_RE = '/ssh|scp|sftp|rsync|mosh|git|paramiko|fabric/i';
    /** program => [option letters without a value, letters with a value], from OpenSSH 10.0's usage texts. */
    const OPTIONS = ['ssh' => ['46AaCfGgKkMNnqsTtVvXxYy', 'BbcDEeFIiJLlmOoPpQRSWw'], 'sftp' => ['46AaCfNpqrv', 'BbcDFiJloPRSsX'],
        'scp' => ['346ABCOpqRrsTv', 'cDFiJloPSX'], 'ssh-copy-id' => ['fnsxh?', 'itFop'], 'ssh-keyscan' => ['46cDHqv', 'fOpTt']];
    const NONE = ['conns' => [], 'routes' => []];

    /** ['conns' => [['program', 'names', 'routes']], 'routes' => []] for $program run with the words $args. */
    public static function read($program, array $args, $depth)
    {
        if ($program === 'autossh') {
            for (; $args && SshUnreadable::matches('/^-([MfV])(.*)\z/s', $args[0]['text'], $m); array_shift($args)) {
                if ($m[1] === 'V') {
                    return self::NONE;
                }
                $m[1] === 'M' && $m[2] === '' ? array_shift($args) : null; // autossh -M port, before ssh's own options
            }
            $program = 'ssh';
        }
        if (isset(self::OPTIONS[$program])) {
            return SshOpenssh::openssh($program, $args, $depth);
        }
        return $program === 'git' ? SshGit::read($args) : SshCopy::copy($program, $args, $depth);
    }

    /** The host of a [user@]host:path operand (the colon before any slash), null for a local path or an rsync daemon (host::). */
    public static function remoteHost($t, $daemonForm = false)
    {
        if (!SshUnreadable::matches('#^(?:[^@/]*@)?(\[[^\]/]*\]|[^:/\[]*):#', $t, $m)) {
            return null;
        }
        return $daemonForm && substr($t, strlen($m[0]), 1) === ':' ? null : self::host($m[1]);
    }

    /** ssh's destination ([user@]host or ssh://[user@]host[:port]); sftp's may end in :path or be sftp://. */
    public static function destination($program, $t)
    {
        $scheme = $program === 'sftp' ? 'sftp' : 'ssh';
        if (SshUnreadable::matches("#^$scheme://([^/]*)#i", $t, $m)) {
            return self::hop($m[1]);
        }
        $at = strrpos($t, '@');
        $t = $at === false ? $t : substr($t, $at + 1);
        return $program === 'sftp' && SshUnreadable::matches('#^(\[[^\]]*\]|[^:]*):#', $t, $m) ? self::host($m[1]) : self::host($t);
    }

    /** Jump hosts: -J and ProxyJump take [user@]host[:port] or ssh:// URIs separated by commas; none is no jump. */
    public static function hops($value)
    {
        return strtolower(trim($value)) === 'none' ? [] : array_map([__CLASS__, 'hop'], explode(',', $value));
    }

    /** One [user@]host[:port] (also ssh://, [v6]:port or a bare IPv6 address). */
    public static function hop($s)
    {
        $s = SshUnreadable::check(preg_replace('#^ssh://#i', '', trim($s)));
        $at = strrpos($s, '@');
        $s = $at === false ? $s : substr($s, $at + 1);
        if (SshUnreadable::matches('/^(\[[^\]]*\])(:\d*)?\z/', $s, $m) || substr_count($s, ':') !== 1) {
            return self::host(isset($m[1]) ? $m[1] : $s);
        }
        return self::host(explode(':', $s)[0]);
    }

    /** A host normalised by SshLists::host(), or unreadable. */
    public static function host($raw)
    {
        $h = SshLists::host($raw);
        if ($h === null) {
            throw new SshUnreadable('the host "' . substr((string) $raw, 0, 80) . '" cannot be read as a host name or address');
        }
        return $h;
    }

    /** A word's text, unreadable when it holds a variable or a command (its value is only known when the shell runs it). */
    public static function literal(array $w)
    {
        if ($w['dyn']) {
            throw new SshUnreadable('"' . substr($w['text'], 0, 80) . '" is built from a variable or a command');
        }
        return $w['text'];
    }
}
