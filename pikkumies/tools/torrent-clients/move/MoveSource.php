<?php
/**
 * MoveSource: reads on the box the data comes from, through its client units (MoveRunner::unit): find a torrent by
 * id or name (one match or a refusal naming the candidates), export its .torrent (the unit's `export` verb, paged at
 * the 16 KiB answer budget, then checked: length, SHA-256 and the info-hash recomputed against the id), and list its
 * files with the delete preview's `expect` (`show --files`). Read-only.
 */
class MoveSource
{
    /** Units that answer `export` (their Export.php). */
    const CLIENTS = ['rtorrent', 'qbittorrent', 'deluge'];
    const FIELDS = 'id,name,size,done,progress,state,label,private,path';
    /** What serve takes as a tool argument (ServeGate ARG_RE): a word outside it cannot be sent. */
    const TOKEN = '/^[A-Za-z0-9._:@%+=,-]+$/';

    private $run;

    public function __construct(MoveRunner $run)
    {
        $this->run = $run;
    }

    /** The clients to look in: the service's own list, else every exporting client. */
    public static function clients(array $svc)
    {
        $named = isset($svc['clients']) && is_array($svc['clients']) ? array_values(array_intersect(self::CLIENTS, array_keys($svc['clients']))) : [];
        return $named ?: self::CLIENTS;
    }

    /** The one torrent $query names on $nick: a 40-hex id, an 8+ hex prefix, an exact name or a part of one. */
    public function find($nick, array $clients, $query)
    {
        $query = trim((string) $query);
        $found = [];
        $errors = [];
        $hex = preg_match('/^[0-9a-fA-F]{8,40}$/', $query) === 1;
        foreach ($clients as $c) {
            try {
                if ($hex) {
                    $r = $this->run->unit($nick, $c, ['show', strtolower($query), '--fields', self::FIELDS]);
                    $rows = array_slice($r['records'], 0, 1);
                } else {
                    $rows = $this->byName($nick, $c, $query);
                }
            } catch (CliError $e) {
                if (strpos($e->getMessage(), 'not found:') === false) {
                    $errors[] = $e->getMessage();
                }
                continue;
            }
            foreach ($rows as $row) {
                $found[] = ['client' => $c] + $row;
            }
        }
        if (count($found) === 1) {
            return $found[0];
        }
        if (!$found) {
            throw new CliError("nothing planned: no torrent on $nick matches $query" . ($errors ? ' (' . implode('; ', $errors) . ')' : ''),
                'give the id from `find` or the client\'s `list` on that box');
        }
        throw new CliError("nothing planned: $query matches " . count($found) . " torrents on $nick: " . implode('; ', array_map(function ($f) {
            return "{$f['client']} {$f['id']} {$f['name']} ({$f['size']} bytes)";
        }, array_slice($found, 0, 5))), 'name the one meant by its id; compare size and files (`show <id> --files`) and ask the user once if still unsure');
    }

    /** Exact name matches, else the names that contain the query (case-insensitive). */
    private function byName($nick, $client, $query)
    {
        $words = array_filter(preg_split('/\s+/', $query), function ($w) {
            return preg_match(self::TOKEN, $w) === 1;
        });
        usort($words, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $args = ['list', '--fields', self::FIELDS];
        if ($words) {
            $args = array_merge($args, ['--search', $words[0]]); // narrowed by the client, filtered exactly here
        }
        $rows = $this->run->pages($nick, $client, $args)['records'];
        $exact = array_values(array_filter($rows, function ($r) use ($query) {
            return isset($r['name']) && $r['name'] === $query;
        }));
        return $exact ?: array_values(array_filter($rows, function ($r) use ($query) {
            return isset($r['name']) && stripos($r['name'], $query) !== false;
        }));
    }

    /** [raw .torrent, MoveMeta::parse] of $id, checked against the id the client lists. */
    public function export($nick, $client, $id)
    {
        $recs = $this->run->pages($nick, $client, ['export', $id, 'base64'])['records'];
        $head = null;
        $parts = [];
        foreach ($recs as $r) {
            if (isset($r['part'], $r['b64'])) {
                $parts[(int) $r['part']] = (string) $r['b64'];
            } elseif (isset($r['sha256'], $r['bytes'])) {
                $head = $r;
            }
        }
        ksort($parts);
        $raw = $head === null ? false : base64_decode(implode('', $parts), true);
        if ($raw === false || count($parts) !== (int) $head['parts'] || strlen($raw) !== (int) $head['bytes'] || hash('sha256', $raw) !== $head['sha256']) {
            throw new CliError("the .torrent of $id from $nick arrived incomplete or changed", 'run the same command again');
        }
        $meta = MoveMeta::parse($raw);
        if (MoveMeta::idFor($meta, $client) !== strtolower($id)) {
            throw new CliError("the .torrent $nick exported does not hash to $id", 'the client\'s saved .torrent does not match the torrent; nothing was changed');
        }
        return [$raw, $meta];
    }

    /** ['files' => [id => [[path, bytes, skip], ...]], 'status' => show --files status (expect, refused...)]. */
    public function files($nick, $client, array $ids)
    {
        $r = $this->run->pages($nick, $client, array_merge(['show'], $ids, ['--files', '--fields', 'torrent,file,bytes,skip']));
        $out = [];
        foreach ($r['records'] as $f) {
            if (isset($f['torrent'], $f['file'])) {
                $out[$f['torrent']][] = [(string) $f['file'], isset($f['bytes']) ? (int) $f['bytes'] : 0, isset($f['skip'])];
            }
        }
        return ['files' => $out, 'status' => $r['status']];
    }
}
