<?php
/**
 * MediaServers: the verbs every media-server unit shares (tools/media-servers/README.md). A unit
 * implements MediaServer and calls MediaServers::run(); argument resolution, "never all", viewer
 * addresses only with --addresses, and bounded, redacted logs live here once.
 */
$mediaLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Host', 'Http'] as $mediaClass) {
    require_once "$mediaLib/$mediaClass.php";
}
foreach (['MediaServer', 'MediaHttp', 'MediaSecret', 'MediaExec', 'MediaDocker', 'MediaLocal', 'MediaRestart',
    'MediaEndStream'] as $mediaClass) {
    require_once __DIR__ . "/$mediaClass.php";
}

class MediaServers
{
    const VERBS = ['stats', 'libraries', 'scan', 'sessions', 'end-stream', 'recent', 'logs', 'restart', 'login'];
    /** Session fields that hold a viewer's network address. */
    const ADDRESS_FIELDS = ['address', 'public_address'];
    /** Most recently added items one call reads: far more than 16 KiB of output holds; --offset pages up to it. */
    const RECENT_CAP = 500;

    /**
     * $connect: fn(array $opt, bool $auth) => MediaServer, called only when a verb needs the server; $auth false
     * (restart) loads no credential and sends none. $login: the unit's login verb. $options: the unit's own options that
     * take a value (plex: --log), which its $connect reads from $opt. $extra: verbs only this unit has (jellyfin: login-check,
     * set-login, config; README.md of this category, "Jellyfin only").
     */
    public static function run(array $argv, callable $connect, callable $login, $usage, array $options = [], array $extra = [])
    {
        $server = null;
        $s = function ($opt, $auth = true) use (&$server, $connect) {
            return $server !== null ? $server : ($server = $connect($opt, $auth));
        };
        $scrub = function ($records) use (&$server) {
            return $server !== null ? self::scrub($records, $server->secrets()) : $records;
        };
        Cli::run($argv, [
            'stats' => function ($args, $opt) use ($s, $scrub) {
                return $scrub([$s($opt)->stats()]);
            },
            'libraries' => function ($args, $opt) use ($s, $scrub) {
                return $scrub($s($opt)->libraries());
            },
            'scan' => function ($args, $opt) use ($s, $scrub) {
                $all = $s($opt)->libraries();
                if (!empty($opt['all'])) {
                    if ($args) {
                        throw new CliError('give library names or --all, not both; nothing was scanned', 'scan Movies Shows | scan --all');
                    }
                    return $scrub($s($opt)->scan($all));
                }
                $hint = 'scan <library>... (ids or names from `libraries`) | scan --all';
                return $scrub($s($opt)->scan(self::pick($all, $args, 'library', $hint)));
            },
            'sessions' => function ($args, $opt) use ($s, $scrub) {
                return $scrub(self::addresses($s($opt)->sessions(), !empty($opt['addresses'])));
            },
            'end-stream' => function ($args, $opt) use ($s, $scrub) {
                foreach ($args as $a) {
                    if (strtolower($a) === 'all' || $a === '*') {
                        throw new CliError('end-stream never ends every stream; nothing was ended',
                            'name the session ids from `sessions`, one or more');
                    }
                }
                $targets = self::pick($s($opt)->sessions(), $args, 'session', 'end-stream <session-id>... (ids from `sessions`)', false);
                $reason = isset($opt['reason']) && is_string($opt['reason']) ? substr($opt['reason'], 0, 200) : '';
                $done = $s($opt)->endStream($targets, $reason);
                $done = MediaEndStream::readBack($done, function () use ($s, $opt) {
                    return $s($opt)->sessions(); // read back: gone from the live list
                });
                return $scrub(self::addresses(MediaEndStream::finish($done), false));
            },
            'recent' => function ($args, $opt) use ($s, $scrub) {
                $got = $s($opt)->recent($need = self::need($opt));
                $items = isset($got['items']) ? $got['items'] : $got;
                // The whole list's size: the server's own count, else exact only when fewer came than were asked for.
                $total = array_key_exists('total', $got) ? $got['total'] : (count($items) < $need ? count($items) : null);
                $capped = count($items) >= self::RECENT_CAP;
                $note = 'read the ' . self::RECENT_CAP . ' most recently added items, the most one call reads; older ones: '
                    . 'the server\'s own web UI';
                return ['records' => $scrub($items), 'status' => ['total' => $total] + ($capped ? ['note' => $note] : [])];
            },
            'logs' => function ($args, $opt) use ($s) {
                $n = isset($opt['lines']) ? (int) $opt['lines'] : 50;
                if ($n < 1 || $n > 1000) {
                    throw new CliError('--lines takes 1 to 1000', 'logs --lines 100');
                }
                $log = $s($opt)->logs($n);
                list($lines, $dropped) = MediaLocal::redact($log['lines'], $s($opt)->secrets(), !empty($opt['addresses']));
                $record = self::scrub(['file' => $log['file'], 'lines' => $lines], $s($opt)->secrets());
                return [$record + ($dropped ? ['older_lines_cut' => $dropped] : [])];
            },
            'restart' => function ($args, $opt) use ($s, $scrub) {
                return $scrub([$s($opt, false)->restart()]); // public status and the platform's own restart: no credential
            },
            'login' => $login,
        ] + $extra, $usage, ['all', 'addresses'], array_merge(['reason', 'lines', 'url'], $options));
    }

    /**
     * How many items `recent` reads so Cli::emit can apply --limit, --offset and its output budget as for every tool:
     * offset + limit + 1 (one more than it may print, so Cli marks `truncated` with `next_offset`), RECENT_CAP for
     * --limit 0 ("as many as fit", never one item) and at most RECENT_CAP. The same rule as tools/arr/lib/ArrPages.php.
     */
    public static function need(array $opt)
    {
        $limit = isset($opt['limit']) ? max(0, (int) $opt['limit']) : Cli::LIMIT;
        $offset = isset($opt['offset']) ? max(0, (int) $opt['offset']) : 0;
        return $limit > 0 ? min(self::RECENT_CAP, $offset + $limit + 1) : self::RECENT_CAP;
    }

    /**
     * The records $args name, by exact id or (libraries only) by name, case-insensitive. One unknown or
     * ambiguous argument fails the whole call, so a batch never half-runs. Never returns an empty list.
     */
    public static function pick(array $all, array $args, $noun, $hint, $byName = true)
    {
        if (!$args) {
            throw new CliError("name at least one $noun", $hint);
        }
        $out = [];
        $bad = [];
        foreach (array_unique($args) as $a) {
            $m = array_values(array_filter($all, function ($r) use ($a, $byName) {
                return (string) $r['id'] === (string) $a || ($byName && strcasecmp((string) $r['name'], (string) $a) === 0);
            }));
            if (count($m) !== 1) {
                $bad[] = $m ? "$a: matches " . count($m) . " {$noun}s" : "$a: no such $noun right now";
                continue;
            }
            $out[(string) $m[0]['id']] = $m[0];
        }
        if ($bad) {
            throw new CliError('nothing changed: ' . implode('; ', $bad), $hint);
        }
        return array_values($out);
    }

    /** Session records without viewer addresses unless the user asked for them (rule 7). */
    public static function addresses(array $records, $keep)
    {
        return $keep ? $records : array_map(function ($r) {
            return array_diff_key($r, array_flip(self::ADDRESS_FIELDS));
        }, $records);
    }

    /** Every string in $records with the literal credential values replaced. */
    public static function scrub($records, array $secrets)
    {
        $secrets = array_values(array_filter($secrets, function ($v) {
            return is_string($v) && strlen($v) >= 4;
        }));
        if (!$secrets) {
            return $records;
        }
        array_walk_recursive($records, function (&$v) use ($secrets) {
            $v = is_string($v) ? str_replace($secrets, 'REDACTED', $v) : $v;
        });
        return $records;
    }
}
