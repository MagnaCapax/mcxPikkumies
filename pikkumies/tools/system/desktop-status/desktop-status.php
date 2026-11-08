<?php
/**
 * desktop-status: read-only facts about the graphical side of this machine (ADR-0112).
 *
 * Three verbs: session, outputs, dns. Every value is read from this machine now; a value that
 * cannot be read is null with a `why`, never 0 and never a guess. Nothing here changes anything.
 */
require_once __DIR__ . '/../../../lib/Cli.php';
require_once __DIR__ . '/../../../lib/PathName.php';

const DESKTOP_STATUS_USAGE = "usage: desktop-status.php session|outputs|dns [--human]\n  session  the session type, the desktop name, and whether a graphical session is present at all\n  outputs  one record per connected output: identity from its own display data, status, mode count\n  dns      the name servers and search domains in use, and which link answers queries when the resolver reports it";

/** The value of an environment variable, or null. */
function desktopStatusEnv($name)
{
    $v = getenv($name);
    return ($v === false || $v === '') ? null : $v;
}

/** A file's trimmed contents, or null when it cannot be read. */
function desktopStatusRead($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $s = @file_get_contents($path);
    return $s === false ? null : trim($s);
}

/** Run a fixed, read-only command; at most $max bytes of stdout. Returns null when it cannot run. */
function desktopStatusRun($argv, $max = 65536)
{
    $exe = $argv[0];
    $found = null;
    foreach (['/usr/bin/', '/bin/', '/usr/sbin/', '/sbin/'] as $dir) {
        if (is_executable($dir . $exe)) {
            $found = $dir . $exe;
            break;
        }
    }
    if ($found === null) {
        return null;
    }
    $cmd = [$found];
    foreach (array_slice($argv, 1) as $a) {
        $cmd[] = escapeshellarg($a);
    }
    $p = @proc_open(implode(' ', $cmd), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return null;
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1], $max);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return $code === 0 ? $out : null;
}

/** The DRM card devices this machine has, as sorted paths. */
function desktopStatusCards()
{
    $cards = glob('/sys/class/drm/card[0-9]*');
    if ($cards === false) {
        return [];
    }
    $out = [];
    foreach ($cards as $c) {
        if (is_link($c) || is_dir($c)) {
            $out[] = $c;
        }
    }
    sort($out);
    return $out;
}

/** Decode the identifying fields of a raw extended-display-data blob. */
function desktopStatusEdid($raw)
{
    if (!is_string($raw) || strlen($raw) < 128 || substr($raw, 0, 8) !== "\x00\xff\xff\xff\xff\xff\xff\x00") {
        return null;
    }
    $vendor = chr(ord('@') + ((ord($raw[8]) >> 2) & 0x1f))
        . chr(ord('@') + (((ord($raw[8]) & 0x03) << 3) | ((ord($raw[9]) >> 5) & 0x07)))
        . chr(ord('@') + (ord($raw[9]) & 0x1f));
    $product = ord($raw[11]) * 256 + ord($raw[10]);
    // A serial number is stored little-endian at 0x0C when the descriptor is non-zero, else it is text at 0x5C.
    $serial = ord($raw[15]) * 16777216 + ord($raw[14]) * 65536 + ord($raw[13]) * 256 + ord($raw[12]);
    $text = '';
    if ($serial === 0 && ord($raw[0x5c]) === 0 && ord($raw[0x5d]) === 0 && ord($raw[0x5e]) === 0) {
        for ($i = 0x6e; $i <= 0x71; $i++) {
            if (ord($raw[$i]) === 10 || ord($raw[$i]) === 0) {
                break;
            }
            $text .= $raw[$i];
        }
    }
    return [
        'vendor' => $vendor,
        'product' => $product,
        'serial' => $serial > 0 ? (string) $serial : ($text !== '' ? $text : null),
    ];
}

$verbs = [
    'session' => function ($args, $opt) {
        $type = desktopStatusEnv('XDG_SESSION_TYPE');
        $wayland = desktopStatusEnv('WAYLAND_DISPLAY');
        $x11 = desktopStatusEnv('DISPLAY');
        $cards = desktopStatusCards();
        $connected = 0;
        foreach ($cards as $card) {
            foreach ((array) glob($card . '/card*-*') as $conn) {
                if (desktopStatusRead($conn . '/status') === 'connected') {
                    $connected++;
                }
            }
        }
        // A connected connector alone is not a desktop: a virtual machine or a headless host can report one.
        // The desktop exists when a display server is reachable, or the session is a graphical one.
        $graphical = ($wayland !== null) || ($x11 !== null)
            || ($type !== null && $type !== 'tty' && $type !== 'unspecified');
        $why = [];
        if ($type === null) {
            $why[] = 'the session type is not in this process\'s environment (a non-graphical or remote shell)';
        }
        return [[
            'desktop' => $graphical,
            'session_type' => $type,
            'desktop_name' => desktopStatusEnv('XDG_CURRENT_DESKTOP'),
            'session_name' => desktopStatusEnv('XDG_SESSION_DESKTOP'),
            'display_server_reachable' => $wayland !== null || $x11 !== null,
            'graphics_cards' => count($cards),
            'connected_outputs' => $connected,
            'why' => $why ? implode('; ', $why) : null,
        ]];
    },

    'outputs' => function ($args, $opt) {
        $records = [];
        $seen = 0;
        foreach (desktopStatusCards() as $card) {
            foreach ((array) glob($card . '/card*-*') as $conn) {
                $name = PathName::base($conn);
                if (preg_match('/^card[0-9]+-(.+)$/', $name, $m) !== 1) {
                    continue;
                }
                $seen++;
                $status = desktopStatusRead($conn . '/status');
                if ($status !== 'connected') {
                    continue;
                }
                $raw = @file_get_contents($conn . '/edid');
                $identity = desktopStatusEdid($raw === false ? '' : $raw);
                $modes = desktopStatusRead($conn . '/modes');
                $records[] = [
                    'connector' => $m[1],
                    'status' => $status,
                    'identity' => $identity,
                    'modes' => $modes === null ? null : count(preg_split('/\R/', $modes)),
                    'enabled' => desktopStatusRead($conn . '/enabled'),
                    // Layout position and scale are held by the running compositor, not by the kernel:
                    'position' => null,
                    'scale' => null,
                    'why' => 'position and scale live in the running compositor\'s own state, not in the kernel\'s output data',
                ];
            }
        }
        if ($records === []) {
            return [['connectors_seen' => $seen, 'connected' => 0, 'note' => 'no connected output is reported by the kernel; on a headless machine this is normal']];
        }
        return $records;
    },

    'dns' => function ($args, $opt) {
        $nameservers = [];
        $search = [];
        $resolv = desktopStatusRead('/etc/resolv.conf');
        if ($resolv !== null) {
            foreach (preg_split('/\R/', $resolv) as $line) {
                $line = trim(preg_replace('/#.*$/', '', $line));
                if (preg_match('/^nameserver\s+(\S+)/i', $line, $m) === 1) {
                    $nameservers[] = $m[1];
                } elseif (preg_match('/^search\s+(.+)$/i', $line, $m) === 1) {
                    $search = preg_split('/\s+/', trim($m[1]));
                }
            }
        }
        $owner = null;
        $why = null;
        $state = desktopStatusRun(['resolvectl', 'status']);
        if ($state !== null) {
            // "Link N (name)" opens a link's block; the block's "Default Route: yes" marks the one that answers.
            $current = null;
            foreach (preg_split('/\R/', $state) as $line) {
                if (preg_match('/^\s*Link \d+ \(([^)]+)\)/', $line, $m) === 1) {
                    $current = $m[1];
                } elseif ($current !== null && preg_match('/^\s*Default Route:\s*yes/i', $line) === 1) {
                    $owner = $current;
                }
            }
        } else {
            $why = 'the resolver\'s per-link state is only visible through its own status output, which is not available here';
        }
        return [[
            'nameservers' => $nameservers,
            'search_domains' => $search,
            'default_route_link' => $owner,
            'why' => $owner === null ? ($why === null ? 'the resolver reports no link as the default-route owner' : $why) : null,
        ]];
    },
];

Cli::run($argv, $verbs, DESKTOP_STATUS_USAGE);
