<?php
/**
 * ServicesList: add, list, show, edit and remove entries of the services list (ADR-0084). Provider presets fill in
 * client endpoints only where a source says where they are; anything else stays unknown (null) rather than guessed.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';
require_once dirname(__DIR__, 3) . '/lib/Units.php';

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
     * Memory files about $nick: every lesson, fact sheet and research note whose frontmatter `service` array
     * (`save --service`, ADR-0104) names it, plus the pre-ADR-0104 convention still read for files that have no
     * usable `service` frontmatter (missing entirely, or present but not a JSON array — a hand-written
     * `service: pm1` line is text, not JSON) — a body line `service: <nick>` under memory/lessons/services/ or
     * memory/reference/ only (the two directories skill.md always named). Each record carries `categories` (the
     * memory's frontmatter, kept only as a list of valid tag strings, Services::NICK_RE; null for the body-text
     * convention, which never had one).
     */
    public static function memories($nick, $memoryDir)
    {
        $legacy = array_merge((array) glob("$memoryDir/lessons/services/*.md"), (array) glob("$memoryDir/reference/*.md"));
        $files = array_merge((array) glob("$memoryDir/lessons/*/*.md"), (array) glob("$memoryDir/reference/*.md"), (array) glob("$memoryDir/research/*.md"));
        $out = [];
        foreach ($files as $f) {
            $text = is_file($f) && !is_link($f) ? (string) @file_get_contents($f, false, null, 0, 65536) : '';
            if ($text === '') {
                continue;
            }
            $title = preg_match('/^# (.+)$/m', $text, $m) ? trim($m[1]) : PathName::base($f, '.md');
            $path = substr($f, strlen(dirname($memoryDir)) + 1);
            list($meta, ) = Units::frontmatter($text);
            $services = isset($meta['service']) ? json_decode($meta['service'], true) : null;
            if (is_array($services)) {
                if (in_array((string) $nick, $services, true)) {
                    $categories = isset($meta['categories']) ? json_decode($meta['categories'], true) : null;
                    // Keep only string elements that match Services::NICK_RE: a hand-edited file, or data from
                    // before the M1 fix, may hold a non-string (number, bool, nested array) or a malformed tag.
                    $categories = is_array($categories) ? array_values(array_filter($categories, function ($t) {
                        return is_string($t) && preg_match(Services::NICK_RE, $t);
                    })) : null;
                    $out[] = ['path' => $path, 'title' => $title, 'categories' => $categories];
                }
            } elseif (in_array($f, $legacy, true) && preg_match('/^service:\s*' . preg_quote($nick, '/') . '\s*$/mi', $text)) {
                // No `service` frontmatter at all, or a `service` key that is not a JSON array (the old convention
                // wrote it as plain body text, never JSON) — either way, fall through to the pre-ADR-0104 check
                // instead of silently dropping the file.
                $out[] = ['path' => $path, 'title' => $title, 'categories' => null];
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
