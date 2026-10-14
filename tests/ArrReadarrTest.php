<?php
/** The readarr unit (read verbs only) against the fake Servarr API (tests/fixtures/arr/, seed-v1.php). */
require_once __DIR__ . '/fixtures/arr/harness.php';
require_once __DIR__ . '/fixtures/arr/phase2.php';

return [
    'readarr: only the read verbs exist; add, lookup, remove and search are usage errors; nothing changing is sent' => function () {
        list(, $out) = runPhp(dirname(__DIR__) . '/pikkumies/tools/arr/readarr/readarr.php', ['--verbs']);
        check(array_values(array_filter(explode("\n", $out))) === ['list', 'show', 'stats', 'health', 'history', 'restart', 'queue', 'wanted'],
            'readarr verbs: ' . $out);
        $fx = arrFixture('readarr', ['where' => 'servarr']);
        try {
            $changing = [['add', 'x'], ['lookup', 'x'], ['remove', '1'], ['search', '1'], ['show', '1', '--files'], ['remove', '1', '--with-data']];
            foreach ($changing as $args) {
                list($code) = arrRun($fx, $args);
                check($code === 2, implode(' ', $args) . ' was not a usage error');
            }
            check(!array_filter(arrState($fx)['calls'], function ($c) {
                return $c['method'] !== 'GET';
            }), 'readarr sent a changing request: ' . json_encode(arrState($fx)['calls']));
        } finally {
            arrDone($fx);
        }
    },
    'readarr: list, show, stats, queue, wanted and history read authors and books; every boolean the spec declares is sent' => function () {
        $fx = arrFixture('readarr', ['where' => 'servarr']);
        try {
            list($code, $l, $out) = arrRun($fx, ['list']);
            check($code === 0 && $l[0]['title'] === 'Author B' && $l[0]['books'] === 2 && $l[0]['books_total'] === 5
                && $l[0]['meta_id'] === ''
                && $l[1]['size'] === 500, 'list: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['show', '2']);
            check($code === 0 && $l[0]['title'] === 'Author C' && $l[0]['overview'] === 'An author.', 'show: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['wanted']);
            check($code === 0 && $l[0]['title'] === 'Book X' && $l[0]['item'] === 1
                && $l[0]['released_at'] === 1557100800, 'wanted: ' . $out);
            foreach ([['stats'], ['queue'], ['wanted', '--cutoff'], ['history'], ['history', '--since', '2026-10-01']] as $args) {
                list($code, $l, $out) = arrRun($fx, $args);
                check($code === 0, implode(' ', $args) . ': ' . $out);
            }
            $q = arrCalls($fx, 'GET', 'queue')[0]['query'];
            check(strpos($q, 'includeUnknownAuthorItems=false&includeAuthor=false&includeBook=false') !== false,
                'queue params');
        } finally {
            arrDone($fx);
        }
    },
    'readarr: the key never reaches the output (401, a 500 that echoes it, a dead port), never in the URL' => function () {
        arrPhase2KeyOracle('readarr', ['where' => 'servarr'], 'author');
    },
    'readarr: no PMSS lowercase folder; restart is the user\'s own Docker container only; a foreign port is refused' => function () {
        $app = ArrApps::get('readarr');
        check(array_keys(ArrLocate::candidates($app, '/h', 'pmss')) === ['servarr', 'docker'], 'readarr candidates');
        $fx = arrFixture('readarr', ['where' => 'servarr', 'start' => false]);
        try {
            arrPhase2OwnerRefusal(function ($tables) use ($app, $fx) {
                return ArrLocate::find($app, $fx['home'], 'pmss', arrUid(), [], false, $tables);
            }, $fx['port']);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('readarr', ['where' => 'servarr']);
        try {
            file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"\$HOME/../installer.log\"\n");
            file_put_contents($fx['home'] . '/.bashrc.custom', "alias readarr='true'\n");
            list($code, $l, $out) = arrRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'done by the provider') !== false && !file_exists($fx['dir'] . '/installer.log')
                && !arrCalls($fx, 'POST', 'system/restart'), 'restart outside Docker: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrFixture('readarr', ['where' => 'docker']);
        try {
            $sock = arrP2Docker($fx, "0.0.0.0:{$fx['port']}\n");
            list($code, $l, $out) = arrRun($fx, ['restart'], ['DOCKER_HOST' => 'unix://' . $fx['dir'] . '/docker.sock']);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['started_at'] === 1760000060
                && $l[0]['previous_started_at'] === 1760000000
                && strpos((string) file_get_contents($fx['dir'] . '/docker.log'), 'restart readarr') !== false
                && !arrCalls($fx, 'POST', 'system/restart'), 'docker restart: ' . $out);
            fclose($sock);
        } finally {
            arrDone($fx);
        }
    },
];
