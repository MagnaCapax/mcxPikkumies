<?php
/**
 * SshLists: the person's SSH allow and deny lists (ssh.allow, ssh.deny, ssh.blockUnknown; ADR-0068), shared by the
 * ssh-connect hook and backup remote. An entry is a host name or address, or a pattern over one with * and ? (`*` alone is
 * every host), matched against the whole name as ssh would use it: never a substring, never a user or a port. The deny
 * list wins; a connection is allowed only when every name it uses (the host typed, -o HostName, each jump host) is on the
 * allow list. Read from the defaults and local/config.php without the PIKKUMIES_CONFIG overlay, as the config-change hook
 * judges changes. A safety prompt, not security: the agent's user can edit local/config.php (AGENTS.md).
 */
require_once __DIR__ . '/Config.php';

class SshLists
{
    const DENY = 'deny';
    const ALLOW = 'allow';
    const UNKNOWN = 'unknown';
    /** A host name: labels of letters, digits, _ and -; at most 253 characters (checked separately). */
    const NAME_RE = '/^[a-z0-9_][a-z0-9_-]*(\.[a-z0-9_][a-z0-9_-]*)*\z/';
    /** What inet_aton (and so ssh) reads as an IPv4 address: numbers in decimal, octal or hex, one to four parts. */
    const NUMERIC_RE = '/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+)){0,3}\z/';

    /**
     * $h as ssh connects to it: lowercase, one trailing dot dropped, IPv6 without brackets in inet_ntop form, an IPv4-mapped
     * IPv6 address as IPv4. null when it cannot be read: a numeric form other than a canonical dotted quad (3232235777,
     * 127.1, 0300.0250.1.1 all reach an address the lists would not recognise), a zone id, or a character outside a name.
     */
    public static function host($h)
    {
        $h = strtolower((string) $h);
        if (strlen($h) > 2 && $h[0] === '[' && substr($h, -1) === ']') {
            $h = substr($h, 1, -1);
        }
        $h = substr($h, -1) === '.' ? substr($h, 0, -1) : $h;
        if (strpos($h, ':') !== false) {
            $bin = @inet_pton($h);
            if ($bin === false || strlen($bin) !== 16) {
                return null;
            }
            $h = inet_ntop($bin);
            $mapped = preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)\z/', $h, $m);
            return $mapped === false ? null : ($mapped ? $m[1] : $h); // a regex engine error never passes a name unread
        }
        $numeric = preg_match(self::NUMERIC_RE, $h);
        if ($numeric === false) {
            return null;
        }
        if ($numeric) {
            $bin = @inet_pton($h);
            return $bin !== false && strlen($bin) === 4 && inet_ntop($bin) === $h ? $h : null;
        }
        return strlen($h) <= 253 && preg_match(self::NAME_RE, $h) === 1 ? $h : null;
    }

    /** A list entry in the form names are compared in: a literal host normalised like host(), a pattern only lowercased. */
    public static function pattern($p)
    {
        $p = strtolower(trim((string) $p));
        if (strpbrk($p, '*?[') !== false) {
            return substr($p, -1) === '.' ? substr($p, 0, -1) : $p;
        }
        $h = self::host($p);
        return $h === null ? $p : $h;
    }

    /** deny, allow or unknown for a connection that uses $names (each already normalised by host()). */
    public static function decide(array $config, array $names)
    {
        $lists = isset($config['ssh']) && is_array($config['ssh']) ? $config['ssh'] : [];
        foreach ($names as $name) {
            if (self::listed($lists, 'deny', $name)) {
                return self::DENY;
            }
        }
        foreach ($names as $name) {
            if (!self::listed($lists, 'allow', $name)) {
                return self::UNKNOWN;
            }
        }
        return $names ? self::ALLOW : self::UNKNOWN;
    }

    /** True when ssh.blockUnknown is set: a host on neither list is refused instead of asked about. */
    public static function blockUnknown(array $config)
    {
        return !empty($config['ssh']['blockUnknown']);
    }

    /** The lists as the person's settings file has them (not an overlay): Config::fileConfig(). */
    public static function config($file = null)
    {
        return Config::fileConfig($file);
    }

    /**
     * True when $name matches an entry of ssh.<list>. A hand-written string counts as a one-entry list; anything else is
     * skipped. On the allow list an address pattern (10.0.0.*, 2001:db8::*) matches only an address: * also matches dots, and
     * anyone can name a host 10.0.0.7.example.net. The deny list matches more, never less.
     */
    private static function listed(array $lists, $list, $name)
    {
        foreach (isset($lists[$list]) ? (array) $lists[$list] : [] as $entry) {
            $pattern = is_string($entry) ? self::pattern($entry) : '';
            $v4 = preg_match('/^[0-9.*?]*[0-9][0-9.*?]*\z/', $pattern);
            $address = $v4 === 1 ? '/^\d+\.\d+\.\d+\.\d+\z/' : (strpos($pattern, ':') !== false ? '/:/' : '');
            if ($list === 'allow' && ($v4 === false || ($address !== '' && preg_match($address, $name) !== 1))) {
                continue; // a regex engine error never allows
            }
            if ($pattern !== '' && fnmatch($pattern, $name, FNM_NOESCAPE)) {
                return true;
            }
        }
        return false;
    }
}
