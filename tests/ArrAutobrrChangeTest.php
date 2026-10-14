<?php
/** autobrr: a failed toggle reports every filter, the PMSS installer's base path, restart without a stored key. */
require_once __DIR__ . '/fixtures/arr/autobrr-helpers.php';

return [
    'autobrr: toggle stops at the first failure and reports every filter: changed, failed, not_sent; ok:false' => function () {
        $fx = arrOtherFixture('autobrr', ['failPut' => [2]]);
        abLogin($fx);
        try {
            list($code, $l, $out) = abRun($fx, ['toggle', '1', '2', '--off']);
            $last = $l[count($l) - 1];
            check($code === 1 && $last['ok'] === false && strpos($last['error'], 'filter 1 (TV 1080p): changed, now off') !== false
                && strpos($last['error'], 'filter 2') !== false && strpos($last['error'], ': failed, the app failed (HTTP 500)') !== false
                && arrState($fx)['filters'][0]['enabled'] === false, 'second PUT 500: ' . $out);
            arrSet($fx, ['filters' => array_map(function ($f) {
                return ['enabled' => true] + $f;
            }, arrState($fx)['filters']), 'calls' => []]);
            list($code, $l, $out) = abRun($fx, ['toggle', '2', '1', '--off']);
            $err = $l[count($l) - 1]['error'];
            check($code === 1 && strpos($err, ': failed,') !== false && strpos($err, 'filter 1: not_sent, not sent') !== false
                && !arrCalls($fx, 'PUT', 'filters/1/enabled') && arrState($fx)['filters'][0]['enabled'] === true, 'not_sent: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: on PMSS the API is where the installer\'s environment puts it, whatever config.toml says' => function () {
        $fx = arrOtherFixture('autobrr');
        abLogin($fx);
        try {
            file_put_contents($fx['config'], "port = {$fx['port']}\nbaseUrl = \"/elsewhere/\"\n"); // legacy mode: /api/
            list($code, $l, $out) = abRun($fx, ['stats']);
            check($code === 0 && $l[0]['url_base'] === '/public-' . arrUser() . '/autobrr'
                && $l[0]['url_base_from'] === 'installer environment'
                && strpos($l[0]['url_base_note'], 'config.toml puts the API at /api/') !== false, 'PMSS: ' . $out);
            list($code, $l, $out) = abRun($fx, ['stats'], null, ['PIKKUMIES_PMSS_DIR' => $fx['dir'] . '/not-pmss']);
            check($code === 1 && strpos($out, 'HTTP 404') !== false && arrCalls($fx, 'GET', '/api/config'),
                'off PMSS config.toml is used: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr: PMSS restart with no key stored still sees autobrr answer (healthz needs no key)' => function () {
        $fx = arrOtherFixture('autobrr');
        try {
            $d = $fx['dir'];
            $serve = 'ARR_FAKE_STATE=' . escapeshellarg("$d/state.json") . ' nohup ' . escapeshellarg(PHP_BINARY)
                . " -S 127.0.0.1:{$fx['port']} " . escapeshellarg($fx['router']) . " >/dev/null 2>&1 & echo \$! > \"$d/server.pid\"";
            file_put_contents($fx['home'] . '/install-media-stack.sh', "#!/bin/bash\necho \"\$*\" >> \"$d/installer.log\"\n"
                . "case \"\$1\" in\n  --stop-app=*) kill \$(cat \"$d/server.pid\"); rm -f \"$d/tmux-up\"; sleep 0.5 ;;\n"
                . "  --start-app=*) $serve; touch \"$d/tmux-up\" ;;\nesac\n");
            file_put_contents($fx['home'] . '/.bashrc.custom', "alias autobrr='true'\n");
            list($code, $l, $out) = abRun($fx, ['restart'], null, ['PIKKUMIES_ARR_WAIT' => '10']);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['tmux'] === 'running'
                && !file_exists("$d/secrets/autobrr"), 'restart without a key: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
];
