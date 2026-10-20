<?php
/**
 * BalanceGather tested entirely with fakes: no real Services class, no real local torrent client, no
 * subprocess. tests/run.php has already required every pikkumies/lib/*.php (CliError lives there).
 */
require_once dirname(__DIR__) . '/pikkumies/tools/seedbox/balance/BalanceGather.php';

/** One JSONL "page" as a verb would really print it: $records then the status line. */
function bgtJsonl(array $records, array $status)
{
    $lines = [];
    foreach ($records as $r) {
        $lines[] = json_encode($r);
    }
    $lines[] = json_encode(array_merge(['ok' => true], $status));
    return implode("\n", $lines) . "\n";
}

/** A fake 'account' record exactly as `system-status account` would answer (StatusAccount::record() shape). */
function bgtAccountRecord($used, $soft, $upload30d = null, $trafficLimit = null)
{
    return ['family' => 'pmss', 'disk' => ['used' => $used, 'soft' => $soft],
        'traffic' => ['upload_30d' => $upload30d, 'limit' => $trafficLimit]];
}

return [
    'BalanceGather::decodeJsonl splits data records from the one status line that carries "ok", ignoring blank lines and junk' => function () {
        $out = BalanceGather::decodeJsonl("\n" . json_encode(['id' => 'a']) . "\nnot json at all\n" . json_encode(['ok' => true, 'count' => 1]) . "\n");
        check(count($out['records']) === 1 && $out['records'][0]['id'] === 'a', 'the data record was lost or miscounted: ' . json_encode($out));
        check($out['status']['ok'] === true && $out['status']['count'] === 1, 'the status line was not picked out: ' . json_encode($out));
    },

    'BalanceGather::decodeJsonl with no status line at all reports ok=false rather than silently succeeding' => function () {
        $out = BalanceGather::decodeJsonl(json_encode(['id' => 'a']));
        check($out['status']['ok'] === false, 'a missing status line must never be treated as success: ' . json_encode($out));
    },

    'BalanceGather::normalizeAccount reads disk.used/soft and traffic.upload_30d/limit, free = soft - used, and null limit/0 soft means unlimited' => function () {
        $n = BalanceGather::normalizeAccount(bgtAccountRecord(300, 1000, 50, 500));
        check($n['quota'] === ['used' => 300, 'limit' => 1000, 'free' => 700, 'reported_by' => 'local'], 'quota normalization is wrong: ' . json_encode($n));
        check($n['traffic'] === ['upload_30d' => 50, 'limit' => 500], 'traffic normalization is wrong: ' . json_encode($n));
        $u = BalanceGather::normalizeAccount(bgtAccountRecord(300, 0));
        check($u['quota']['limit'] === null && $u['quota']['free'] === null, 'a soft limit of 0 (no quota) must normalize to limit=null, free=null: ' . json_encode($u));
        $missing = BalanceGather::normalizeAccount([]);
        check($missing['quota'] === ['used' => null, 'limit' => null, 'free' => null, 'reported_by' => 'local'], 'a record missing "disk" entirely must give all-null quota, never a guess: ' . json_encode($missing));
    },

    'BalanceGather::normalizeAccount(..., "remote") marks the quota as remote-reported; a negative or absurd disk/traffic figure is rejected rather than trusted (H2)' => function () {
        $remote = BalanceGather::normalizeAccount(bgtAccountRecord(300, 1000), 'remote');
        check($remote['quota']['reported_by'] === 'remote', 'the remote tag was lost: ' . json_encode($remote));
        $negative = BalanceGather::normalizeAccount(bgtAccountRecord(-500, 1000, -1), 'remote');
        check($negative['quota']['used'] === null, 'a negative "used" figure from a remote must never be trusted into the ranking: ' . json_encode($negative));
        check($negative['traffic']['upload_30d'] === null, 'a negative traffic figure must be rejected the same way: ' . json_encode($negative));
        $absurd = BalanceGather::normalizeAccount(bgtAccountRecord(10, PHP_INT_MAX), 'remote');
        check($absurd['quota']['limit'] === null, 'an absurdly large quota limit must be rejected, not trusted: ' . json_encode($absurd));
    },

    'BalanceGather::services always gathers "local" even with no tagged services, through the injected local_run seam' => function () {
        $seams = ["local_run" => function ($unit, array $args) {
            if ($unit === 'system/system-status') {
                return BalanceGather::decodeJsonl(bgtJsonl([bgtAccountRecord(100, 1000)], []));
            }
            if ($unit === 'torrent-clients/rtorrent') {
                return BalanceGather::decodeJsonl(bgtJsonl([['id' => 'aa', 'name' => 'n', 'size' => 10, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 5]], []));
            }
            return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false])); // every other client: not present
        }];
        $out = BalanceGather::services([], $seams);
        check(array_keys($out) === ['local'], 'with no tagged services only "local" should be gathered: ' . json_encode(array_keys($out)));
        check($out['local']['quota'] === ['used' => 100, 'limit' => 1000, 'free' => 900, 'reported_by' => 'local'], 'local quota was not normalized from the fake account record: ' . json_encode($out['local']));
        check(count($out['local']['torrents']) === 1 && $out['local']['torrents'][0]['client'] === 'rtorrent', 'the fake rtorrent torrent did not come through: ' . json_encode($out['local']));
        check($out['local']['up_rate_total'] === 5.0, 'up_rate_total was not summed: ' . json_encode($out['local']));
    },

    'BalanceGather::services pages a client\'s "list" with --offset until the status line stops saying truncated' => function () {
        $calls = [];
        $seams = ["local_run" => function ($unit, array $args) use (&$calls) {
            if ($unit === 'system/system-status') {
                return BalanceGather::decodeJsonl(bgtJsonl([bgtAccountRecord(0, 1000)], []));
            }
            if ($unit !== 'torrent-clients/rtorrent') {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            }
            $calls[] = $args;
            $offset = (int) $args[array_search('--offset', $args) + 1];
            if ($offset === 0) {
                return BalanceGather::decodeJsonl(bgtJsonl([['id' => 'a1', 'name' => 'a', 'size' => 1, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0]],
                    ['truncated' => true, 'next_offset' => 1]));
            }
            return BalanceGather::decodeJsonl(bgtJsonl([['id' => 'a2', 'name' => 'b', 'size' => 2, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0]], []));
        }];
        $out = BalanceGather::services([], $seams);
        check(count($calls) === 2, 'expected exactly two pages read: ' . json_encode($calls));
        check(count($out['local']['torrents']) === 2, 'the second page of torrents was lost: ' . json_encode($out['local']['torrents']));
        $ids = array_column($out['local']['torrents'], 'id');
        check($ids === ['a1', 'a2'], 'pages were not concatenated in order: ' . json_encode($ids));
    },

    'BalanceGather::services filters registered services by --tag (default "seedbox") and never asks the registry for "local" again' => function () {
        $seams = [
            "local_run" => function ($unit, array $args) {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
            "services_all" => function () {
                return [
                    ['nick' => 'box1', 'tags' => ['seedbox']],
                    ['nick' => 'box2', 'tags' => ['backup']],
                    ['nick' => 'local', 'tags' => ['seedbox']], // a service literally named "local": never a second gather of it
                ];
            },
            "fanout" => function (array $nicks, $fn, $parallel) {
                $out = [];
                foreach ($nicks as $n) {
                    $out[$n] = $fn($n);
                }
                return $out;
            },
            "remote_run" => function ($unit, array $args) {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
        ];
        $out = BalanceGather::services(['tag' => 'seedbox'], $seams);
        check(array_keys($out) === ['local', 'box1'], 'expected "local" plus only the seedbox-tagged "box1": ' . json_encode(array_keys($out)));
    },

    'BalanceGather::services with the registry forced unavailable (an explicit "fanout" seam of false) gathers "local" only and leaves a clear note' => function () {
        // Forces the "no registry" branch through the seam rather than through class_exists('Services'):
        // the real Services class may or may not be loaded in this process (it is owner A's unit, which
        // was not part of the build this test was first written against, and has since landed) -- the
        // seam makes this test hold true either way, which is the whole point of array_key_exists() over
        // isset() in BalanceGather::services() (a seam of null/false must win outright, never silently
        // fall back to whatever the real class happens to be in this run).
        $seams = [
            "local_run" => function ($unit, array $args) {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
            "services_all" => function () {
                return [['nick' => 'box1', 'tags' => ['seedbox']]];
            },
            "fanout" => false,
        ];
        $out = BalanceGather::services(['tag' => 'seedbox'], $seams);
        check(array_keys($out) === ['local'], 'with no fanout available, only "local" should come back: ' . json_encode(array_keys($out)));
        check(strpos(implode(' ', $out['local']['notes']), 'not available') !== false, 'a clear note about the missing registry was expected: ' . json_encode($out['local']['notes']));
    },

    'BalanceGather::services falls back to the real Services class when no "fanout" seam is given at all, and the real class is loaded in this process' => function () {
        check(class_exists('Services', false), 'this test is only meaningful once the real Services class (owner A\'s unit) is loaded in this process');
        $seams = [
            "local_run" => function ($unit, array $args) {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
            "services_all" => function () {
                return [];
            },
            // No 'fanout' key at all here (contrast with the test above, which passes one explicitly):
            // with no tagged services this never actually calls Services::fanout(), but it proves the
            // default-selection branch (class_exists('Services', false) ? ['Services', 'fanout'] : null)
            // runs against the real class without a fatal error or a type mismatch.
        ];
        $out = BalanceGather::services(['tag' => 'seedbox'], $seams);
        check(array_keys($out) === ['local'], 'with no tagged services, only "local" should come back: ' . json_encode(array_keys($out)));
    },

    'BalanceGather::services never aborts the whole gather when one remote service errors' => function () {
        $seams = [
            "local_run" => function ($unit, array $args) {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
            "services_all" => function () {
                return [['nick' => 'dies', 'tags' => ['seedbox']], ['nick' => 'lives', 'tags' => ['seedbox']]];
            },
            "fanout" => function (array $nicks, $fn, $parallel) {
                $out = [];
                foreach ($nicks as $n) {
                    $out[$n] = $fn($n);
                }
                return $out;
            },
            "remote_run" => function ($unit, array $args) {
                static $calls = 0;
                $calls++;
                if ($calls <= 2) { // "dies" fails on both its calls (account, then the first client it tries)
                    throw new Exception('ssh: connection refused');
                }
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            },
        ];
        $out = BalanceGather::services(['tag' => 'seedbox'], $seams);
        check(array_keys($out) === ['local', 'dies', 'lives'], 'both services must still appear even though one failed: ' . json_encode(array_keys($out)));
        check($out['dies']['quota']['used'] === null, 'a failed service must report unknown quota, never a guess: ' . json_encode($out['dies']));
        check(strpos(implode(' ', $out['dies']['notes']), 'connection refused') !== false, 'the failure reason should be recorded in notes: ' . json_encode($out['dies']['notes']));
    },

    // --- Security review 2026-10-20 (/tmp/cw85a/REPORT.md, "Patch H"): H1-H4, each with a test that fails on the old code ---

    'H1 (CWE-77/78, tested): a torrent id that is not the client\'s own hex shape is dropped, never carried into a torrent record' => function () {
        $evil = ['id' => 'x; touch /tmp/pwn #', 'name' => 'a', 'size' => 10, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0];
        $good = ['id' => 'deadbeef', 'name' => 'b', 'size' => 20, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0];
        $seams = ["local_run" => function ($unit, array $args) use ($evil, $good) {
            if ($unit === 'system/system-status') {
                return BalanceGather::decodeJsonl(bgtJsonl([bgtAccountRecord(0, 1000)], []));
            }
            if ($unit !== 'torrent-clients/rtorrent') {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            }
            return BalanceGather::decodeJsonl(bgtJsonl([$evil, $good], []));
        }];
        $out = BalanceGather::services([], $seams);
        $ids = array_column($out['local']['torrents'], 'id');
        check($ids === ['deadbeef'], 'the id with shell metacharacters must be dropped, and the well-formed one kept: ' . json_encode($ids));
        check(strpos(implode(' ', $out['local']['notes']), 'hex id shape') !== false, 'dropping a bad id must leave a note, not a silent loss: ' . json_encode($out['local']['notes']));
        check(strpos(implode(' ', $out['local']['notes']), 'touch') === false, 'the dropped id\'s own bytes must never be echoed back into a note: ' . json_encode($out['local']['notes']));
    },

    'H2 (CWE-20, tested): a negative or absurd per-torrent size/progress is rejected to null, which excludes it from every eligibility check downstream' => function () {
        $torrents = [
            ['id' => 'aaaa0001', 'name' => 'a', 'size' => -500, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0],
            ['id' => 'aaaa0002', 'name' => 'b', 'size' => 10, 'progress' => 5.0, 'state' => 'seeding', 'up_rate' => 0], // > 100%
            ['id' => 'aaaa0003', 'name' => 'c', 'size' => 10, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => -99],
        ];
        $seams = ["local_run" => function ($unit, array $args) use ($torrents) {
            if ($unit === 'system/system-status') {
                return BalanceGather::decodeJsonl(bgtJsonl([bgtAccountRecord(0, 1000)], []));
            }
            if ($unit !== 'torrent-clients/rtorrent') {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            }
            return BalanceGather::decodeJsonl(bgtJsonl($torrents, []));
        }];
        $out = BalanceGather::services([], $seams);
        $byId = [];
        foreach ($out['local']['torrents'] as $t) {
            $byId[$t['id']] = $t;
        }
        check($byId['aaaa0001']['size'] === null, 'a reported size of -500 must never be accepted as a real byte count: ' . json_encode($byId['aaaa0001']));
        check($byId['aaaa0002']['progress'] === null, 'a progress of 5.0 (500%) must be rejected, not clamped or guessed: ' . json_encode($byId['aaaa0002']));
        check($byId['aaaa0003']['up_rate'] === 0.0, 'a negative up_rate must never be trusted (and must not go negative into the service total)');
        check($out['local']['up_rate_total'] === 0.0, 'the negative up_rate must not have been added into the total: ' . $out['local']['up_rate_total']);
    },

    'H3 (silent truncation, tested): list truncated at MAX_PAGES (20) leaves a note naming the client and the page count' => function () {
        $seams = ["local_run" => function ($unit, array $args) {
            if ($unit === 'system/system-status') {
                return BalanceGather::decodeJsonl(bgtJsonl([bgtAccountRecord(0, 1000)], []));
            }
            if ($unit !== 'torrent-clients/rtorrent') {
                return BalanceGather::decodeJsonl(bgtJsonl([], ['ok' => false]));
            }
            // Every single page says truncated: true, so paging never ends on its own -- it must stop
            // at BalanceGather::MAX_PAGES and say so, not silently return a list that looks complete.
            return BalanceGather::decodeJsonl(bgtJsonl([['id' => 'cccc0001', 'name' => 'x', 'size' => 1, 'progress' => 1.0, 'state' => 'seeding', 'up_rate' => 0]],
                ['truncated' => true, 'next_offset' => 1]));
        }];
        $out = BalanceGather::services([], $seams);
        $notes = implode(' ', $out['local']['notes']);
        check(strpos($notes, 'truncated after 20 pages') !== false, 'expected a note naming the exact page cap: ' . json_encode($out['local']['notes']));
        check(strpos($notes, 'rtorrent') !== false, 'the note must name which client was truncated: ' . json_encode($out['local']['notes']));
        check(count($out['local']['torrents']) === 20, 'expected exactly MAX_PAGES (20) records (one per page): ' . count($out['local']['torrents']));
    },

    'H4 (CWE-1427, tested): an error from a remote service is labeled untrusted in notes; the same error from "local" is not (it is not remote-reported)' => function () {
        $seams = [
            "local_run" => function ($unit, array $args) {
                throw new Exception('something went wrong locally');
            },
            "services_all" => function () {
                return [['nick' => 'box1', 'tags' => ['seedbox']]];
            },
            "fanout" => function (array $nicks, $fn, $parallel) {
                $out = [];
                foreach ($nicks as $n) {
                    $out[$n] = $fn($n);
                }
                return $out;
            },
            "remote_run" => function ($unit, array $args) {
                throw new Exception('something went wrong remotely');
            },
        ];
        $out = BalanceGather::services(['tag' => 'seedbox'], $seams);
        check(strpos(implode(' ', $out['local']['notes']), '[remote-reported text, untrusted]') === false,
            '"local"\'s own error is not remote-reported text and must not carry that label: ' . json_encode($out['local']['notes']));
        check(strpos(implode(' ', $out['box1']['notes']), '[remote-reported text, untrusted]') !== false,
            'a remote service\'s error text must be labeled untrusted: ' . json_encode($out['box1']['notes']));
    },
];
