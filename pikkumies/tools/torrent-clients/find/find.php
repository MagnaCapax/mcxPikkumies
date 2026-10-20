<?php
/**
 * find: one torrent across every local client this account has, and, with --service/--all, remote services
 * too (tools/torrent-clients/find/README.md). `php find.php find <query> [--service <nick>|--all]
 * [--limit N] [--human]`. Local clients run concurrently (FindLocal); remote services go through the
 * Services contract (lib/Services.php, owner A) behind an injectable seam (FindRemote), so this unit and
 * its tests never need that file to exist. Query, candidate names and tracker text are untrusted data:
 * never interpreted, only carried through to the records (lib/Cli.php redacts tracker secrets the same way
 * every other unit does).
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once "$lib/ToolProc.php";
require_once "$lib/PathName.php";
foreach (['FindMatch', 'FindLocal', 'FindRemote', 'FindStatus', 'FindOutput'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$usage = <<<'TXT'
usage: php find.php find <query> [--service <nick>|--all] [--limit N] [--human]
  find a torrent by its 40-hex info-hash, its exact name, or words from its name.
  Always searches every local client this account has (rtorrent, qbittorrent, deluge, ...).
  --service <nick>  also search one configured remote service (tools/seedbox/services)
  --all             also search every configured remote service
Records: service ("local" or a service nick), client, id, name, size, progress, state, path, label,
message, match: infohash|exact|substring|words, and score (0..1). The last line adds unique,
ambiguous_reason, next, and untrusted (which record fields are the client's own unparsed text).
See USAGE.md.
TXT;

/**
 * One client's `list` answer (already-decoded JSONL lines): a client this account does not have
 * ("... is not installed ...", RtorrentLocate/DelugeLocate::absent/QbitEndpoint::readConfig's own words)
 * is dropped silently -- it was never there to search. Any other failure (not running, not enabled, a
 * refusal) is kept in $issues for the status line. A clean answer turns every row that still matches
 * $query into a find candidate, scored by FindMatch; a row missing id or name is not a torrent record
 * (most likely the trailing status line read twice) and is skipped.
 */
function findAbsorb(array &$candidates, array &$issues, $service, $client, array $lines, $query)
{
    if (!$lines) {
        return;
    }
    $last = end($lines);
    if (!is_array($last) || !array_key_exists('ok', $last)) {
        $issues["$service/$client"] = 'did not answer with a valid status line';
        return;
    }
    if ($last['ok'] === false) {
        $error = isset($last['error']) ? (string) $last['error'] : 'unknown error';
        if (stripos($error, 'not installed') === false) {
            $issues["$service/$client"] = $error;
        }
        return;
    }
    foreach (array_slice($lines, 0, -1) as $row) {
        if (!is_array($row) || !isset($row['id'], $row['name'])) {
            continue;
        }
        $m = FindMatch::classify($query, $row['name'], $row['id']);
        if ($m === null) {
            continue;
        }
        $candidates[] = ['service' => $service, 'client' => $client, 'id' => $row['id'], 'name' => $row['name'],
            'size' => isset($row['size']) ? $row['size'] : null, 'progress' => isset($row['progress']) ? $row['progress'] : null,
            'state' => isset($row['state']) ? $row['state'] : null, 'path' => isset($row['path']) ? $row['path'] : null,
            'label' => isset($row['label']) ? $row['label'] : null, 'message' => isset($row['message']) ? $row['message'] : null,
            'match' => $m['match'], 'score' => $m['score']];
    }
}

// Test seams: a test may set these before including this file (the qbittorrent.php pattern). Production
// reads the real local units and (when present) the real Services class; nothing here is read from the
// environment except the timeout, so a test can still override it with a plain variable.
$findRoot = isset($findRoot) ? $findRoot : dirname(__DIR__);
$findTimeout = isset($findTimeout) ? $findTimeout : (int) getenv('PIKKUMIES_FIND_TIMEOUT');
$findTimeout = $findTimeout > 0 && $findTimeout <= 60 ? $findTimeout : 8;
$findFanout = isset($findFanout) ? $findFanout : (class_exists('Services') ? ['Services', 'fanout'] : null);
$findGet = isset($findGet) ? $findGet : (class_exists('Services') ? ['Services', 'get'] : null);
$findAll = isset($findAll) ? $findAll : (class_exists('Services') ? ['Services', 'all'] : null);
$findJsonl = isset($findJsonl) ? $findJsonl : (class_exists('Services') ? ['Services', 'jsonl'] : null);

Cli::run($argv, ['find' => function ($args, $opt) use ($findRoot, $findTimeout, $findFanout, $findGet, $findAll, $findJsonl) {
    if (count($args) !== 1 || trim((string) $args[0]) === '') {
        throw new CliError('find needs exactly one query (an info-hash, an exact name, or a few words)', 'php find.php find "<query>" [--service <nick>|--all]');
    }
    if (isset($opt['service']) && isset($opt['all'])) {
        throw new CliError('--service and --all cannot both be given', 'name one remote service with --service, or search all of them with --all');
    }
    if (isset($opt['service']) && !is_string($opt['service'])) {
        throw new CliError('--service needs a service nick', '--service <nick> (the nicks tools/seedbox/services knows about), or --all for every one of them');
    }
    $query = $args[0];
    $candidates = [];
    $issues = [];
    $units = FindLocal::discover($findRoot, ['lib', PathName::base(__DIR__)]);
    $local = FindLocal::run($units, $findTimeout);
    foreach ($local['rows'] as $client => $lines) {
        findAbsorb($candidates, $issues, 'local', $client, $lines, $query);
    }
    if (isset($opt['all']) || isset($opt['service'])) {
        if ($findFanout === null || $findGet === null || $findJsonl === null || (isset($opt['all']) && $findAll === null)) {
            throw new CliError('remote search needs the Services list (lib/Services.php); it is not available on this box', 'search local clients only (leave out --service/--all), or try again once Services.php lands');
        }
        $nicks = isset($opt['service']) && is_string($opt['service']) ? [$opt['service']] : array_column((array) call_user_func($findAll), 'nick');
        foreach (FindRemote::run($nicks, $findFanout, $findGet, $findJsonl) as $nick => $byClient) {
            foreach ((array) $byClient as $client => $lines) {
                findAbsorb($candidates, $issues, $nick, $client, (array) $lines, $query);
            }
        }
    }
    usort($candidates, function ($a, $b) {
        $cmp = $b['score'] <=> $a['score'];
        return $cmp !== 0 ? $cmp : strcmp($a['service'] . $a['client'] . $a['id'], $b['service'] . $b['client'] . $b['id']);
    });
    $status = FindStatus::build($query, $candidates);
    if ($local['busy']) {
        $status['busy'] = $local['busy'];
    }
    if ($issues) {
        $status['issues'] = $issues;
    }
    $status['untrusted'] = FindOutput::UNTRUSTED_FIELDS;
    return ['records' => $candidates, 'status' => FindOutput::capStatus($status)];
}], $usage, ['all'], ['service']);
