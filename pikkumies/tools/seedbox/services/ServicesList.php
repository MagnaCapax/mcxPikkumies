<?php
/**
 * ServicesList: add, list, show, edit and remove entries of the services list (ADR-0084). Provider presets fill in
 * client endpoints only where a source says where they are; anything else stays unknown (null) rather than guessed.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class ServicesList
{
    /**
     * Client endpoints per provider, each with its source. pmss: PMSS etc/seedbox/config/template.rtorrent.rc:22,28
     * (directory.default.set = ~/data, network.scgi.open_local = ~/.rtorrent.socket) and scripts/lib/user/
     * transmissionSettings.php:51 ($home/.lighttpd/transmission.sock), github.com/MagnaCapax/PMSS. feral: the rtorrent
     * socket from https://www.feralhosting.com/wiki/path-reference ("Expected location of rtorrent's SCGI socket"); that
     * page names no data directory, so data stays null. whatbox, ultra, bytesized, seedhost, generic: no preset.
     */
    const PRESETS = [
        'pmss' => ['rtorrent' => ['socket' => '~/.rtorrent.socket', 'data' => '~/data'], 'transmission' => ['socket' => '~/.lighttpd/transmission.sock']],
        'feral' => ['rtorrent' => ['socket' => '~/private/rtorrent/.socket', 'data' => null]],
    ];
    const VALUED = ['host', 'user', 'port', 'provider', 'tags', 'transport', 'home', 'notes', 'clients', 'add-tag', 'remove-tag', 'tag'];

    /** The provider a host name points at (Host::TABLE suffixes), or generic. PMSS boxes have no fixed suffix: pass --provider pmss. */
    public static function providerOf($host)
    {
        foreach (Host::TABLE as $key => $row) {
            foreach ($row['type'] === 'provider' && in_array($key, Services::PROVIDERS, true) ? $row['suffixes'] : [] as $suffix) {
                $h = strtolower($host);
                if ($h === $suffix || substr($h, -strlen($suffix) - 1) === ".$suffix") {
                    return $key;
                }
            }
        }
        return 'generic';
    }

    public static function add(array $args, array $opt)
    {
        $nick = Services::nick(isset($args[0]) ? $args[0] : '');
        if (Services::get($nick, false) !== null) {
            throw new CliError("a service named $nick exists", "change it with `services edit $nick`, or pick another name");
        }
        if (!isset($opt['host'], $opt['user'])) {
            throw new CliError('add needs --host and --user', 'e.g. add hdd1 --host box.example.com --user xyzjohn --tags seedbox,backup');
        }
        $provider = isset($opt['provider']) ? (string) $opt['provider'] : self::providerOf((string) $opt['host']);
        $svc = self::apply(['nick' => $nick, 'provider' => $provider, 'clients' => isset(self::PRESETS[$provider]) ? self::PRESETS[$provider] : []], $opt);
        $svc = Services::put($svc);
        return ['records' => [self::shown($svc)], 'status' => ['verified' => Services::get($nick, false) === $svc,
            'next' => "set up its key: PIKKUMIES_REMOTE_PASSWORD='...' services setup $nick (or services pubkey $nick, then accept on that box)"]];
    }

    public static function edit(array $args, array $opt)
    {
        $svc = Services::get(isset($args[0]) ? $args[0] : '');
        $set = is_file(Services::secretDir($svc['nick']) . '/known_hosts');
        foreach (['host', 'port', 'user'] as $k) {
            if ($set && isset($opt[$k]) && (string) $opt[$k] !== (string) $svc[$k]) {
                throw new CliError("--$k cannot change on a service that is set up (its key and host key belong to the old one)", "remove {$svc['nick']} and add it again");
            }
        }
        $svc = Services::put(self::apply($svc, $opt));
        return ['records' => [self::shown($svc)], 'status' => ['verified' => Services::get($svc['nick'], false) === $svc]];
    }

    private static function apply(array $svc, array $opt)
    {
        foreach (['host', 'user', 'port', 'provider', 'transport', 'home', 'notes'] as $k) {
            if (isset($opt[$k])) {
                $svc[$k] = (string) $opt[$k];
            }
        }
        $words = function ($v) {
            return array_values(array_filter(array_map('trim', explode(',', strtolower((string) $v))), 'strlen'));
        };
        $tags = isset($opt['tags']) ? $words($opt['tags']) : (isset($svc['tags']) ? $svc['tags'] : []);
        $tags = array_diff(array_merge($tags, isset($opt['add-tag']) ? $words($opt['add-tag']) : []), isset($opt['remove-tag']) ? $words($opt['remove-tag']) : []);
        $svc['tags'] = array_values(array_unique($tags));
        if (isset($opt['clients'])) {
            $c = json_decode((string) $opt['clients'], true);
            if (!is_array($c)) {
                throw new CliError('--clients takes JSON', 'e.g. --clients \'{"qbittorrent":{"port":12345}}\'');
            }
            $svc['clients'] = $c;
        }
        if (!empty($opt['allow-delete']) || !empty($opt['no-allow-delete'])) {
            $svc['allow_delete'] = empty($opt['no-allow-delete']); // records what the key line on that box allows
        }
        return $svc;
    }

    public static function listing(array $opt)
    {
        $out = [];
        foreach (Services::all() as $s) {
            if (!isset($opt['tag']) || in_array((string) $opt['tag'], isset($s['tags']) ? (array) $s['tags'] : [], true)) {
                $out[] = self::shown($s);
            }
        }
        return $out;
    }

    /** One service for output: the record, whether it is set up, and the memories that name it (`service: <nick>`). */
    public static function show(array $args, $memoryDir)
    {
        $svc = Services::get(isset($args[0]) ? $args[0] : '');
        $pub = Services::secretDir($svc['nick']) . '/key.pub';
        $rec = self::shown($svc) + ['public_key' => is_file($pub) ? trim((string) file_get_contents($pub)) : null, 'memories' => self::memories($svc['nick'], $memoryDir)];
        return [$rec];
    }

    public static function shown(array $s)
    {
        $dir = Services::secretDir($s['nick']);
        return $s + ['set_up' => is_file("$dir/key") && is_file("$dir/known_hosts")];
    }

    /**
     * Memory files about $nick: lessons under memory/lessons/services/ and fact sheets under memory/reference/ that carry a
     * line `service: <nick>` (the convention in skill.md). A plain read of those two directories, no index.
     */
    public static function memories($nick, $memoryDir)
    {
        $out = [];
        foreach (array_merge((array) glob("$memoryDir/lessons/services/*.md"), (array) glob("$memoryDir/reference/*.md")) as $f) {
            $text = is_file($f) && !is_link($f) ? (string) @file_get_contents($f, false, null, 0, 65536) : '';
            if (preg_match('/^service:\s*' . preg_quote($nick, '/') . '\s*$/mi', $text)) {
                $out[] = ['path' => substr($f, strlen(dirname($memoryDir)) + 1), 'title' => preg_match('/^# (.+)$/m', $text, $m) ? trim($m[1]) : PathName::base($f, '.md')];
            }
        }
        return $out;
    }

    public static function remove(array $args)
    {
        $svc = Services::get(isset($args[0]) ? $args[0] : '');
        Services::remove($svc['nick']);
        $dir = Services::secretDir($svc['nick']);
        foreach (['key', 'key.pub', 'known_hosts'] as $f) {
            @unlink("$dir/$f");
        }
        @rmdir($dir);
        return ['records' => [['service' => $svc['nick'], 'removed' => true, 'next' => "on {$svc['host']} remove the line ending in pikkumies-"
            . ServicesKeys::label() . ' from ~/.ssh/authorized_keys: this box no longer has the key, but that line still names it']],
            'status' => ['verified' => Services::get($svc['nick'], false) === null && !is_dir($dir)]];
    }
}
