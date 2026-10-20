<?php
/**
 * FindRemote::run() (tools/torrent-clients/find/FindRemote.php) against fake $fanout/$get/$jsonl callables --
 * never against lib/Services.php, which this unit does not require to exist.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/find/FindRemote.php';

/** A fanout fake close to the real Services::fanout contract: runs $fn per nick, a throw becomes an error entry. */
function findRemoteFanout(array $nicks, callable $fn, $parallel)
{
    $out = [];
    foreach ($nicks as $nick) {
        try {
            $out[$nick] = $fn($nick);
        } catch (Throwable $e) {
            $out[$nick] = ['error' => $e->getMessage()];
        }
    }
    return $out;
}

return [
    'findremote: run() asks $get for each nick\'s clients, then $jsonl once per client, grouped by nick' => function () {
        $get = function ($nick) {
            return ['nick' => $nick, 'clients' => $nick === 'hdd1' ? ['rtorrent' => [], 'deluge' => []] : ['qbittorrent' => []]];
        };
        $calls = [];
        $jsonl = function ($nick, array $argv) use (&$calls) {
            $calls[] = [$nick, $argv];
            return [['service' => $nick, 'id' => str_repeat('1', 40)], ['service' => $nick, 'ok' => true]];
        };
        $out = FindRemote::run(['hdd1', 'hdd2'], 'findRemoteFanout', $get, $jsonl, 4);
        check(array_keys($out) === ['hdd1', 'hdd2'], 'both nicks must be in the result: ' . json_encode($out));
        check(array_keys($out['hdd1']) === ['rtorrent', 'deluge'], 'hdd1 must be asked for both its configured clients: ' . json_encode($out['hdd1']));
        check(array_keys($out['hdd2']) === ['qbittorrent'], 'hdd2 must be asked only for its own configured client: ' . json_encode($out['hdd2']));
        check(count($calls) === 3, 'exactly one jsonl call per (nick, client) pair: ' . json_encode($calls));
        check(in_array(['hdd1', ['tool', 'torrent-clients/rtorrent', 'list']], $calls, true), 'the exact remote argv the serve whitelist expects: ' . json_encode($calls));
    },
    'findremote: a service with no configured clients is asked for nothing, never an error' => function () {
        $out = FindRemote::run(['empty'], 'findRemoteFanout', function () {
            return ['nick' => 'empty', 'clients' => []];
        }, function () {
            check(false, 'jsonl must never be called for a client-less service');
        });
        check($out === ['empty' => []], 'an empty client list must still appear, just with nothing under it: ' . json_encode($out));
    },
    'findremote: an empty nick list never calls $get, $jsonl or $fanout at all' => function () {
        $out = FindRemote::run([], function () {
            check(false, 'fanout must not be called for an empty nick list');
        }, function () {
            check(false, 'get must not be called for an empty nick list');
        }, function () {
            check(false, 'jsonl must not be called for an empty nick list');
        });
        check($out === [], 'no nicks means no remote work at all');
    },
    'findremote: a failing service (the fanout\'s own error entry) never aborts the others' => function () {
        $get = function ($nick) {
            if ($nick === 'down') {
                throw new CliError('ssh refused', 'check the service');
            }
            return ['nick' => $nick, 'clients' => ['rtorrent' => []]];
        };
        // $get runs before fanout, so a throwing $get is this call's own failure, not a per-nick one (it is
        // never asked to search a service it could not even look up); only "up" reaches $jsonl.
        try {
            FindRemote::run(['down', 'up'], 'findRemoteFanout', $get, function ($nick) {
                return [['service' => $nick, 'id' => str_repeat('2', 40)], ['ok' => true]];
            });
            check(false, 'an unreachable service must still surface as a failure, not be silently skipped');
        } catch (CliError $e) {
            check($e->getMessage() === 'ssh refused', 'the real CliError must reach the caller: ' . $e->getMessage());
        }
    },
];
