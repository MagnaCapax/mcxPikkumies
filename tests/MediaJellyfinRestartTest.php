<?php
/** The jellyfin unit's restart on a fake PMSS account (fixtures/media/jellyfin.php): the panel's installer flags only, no credential. */
require_once __DIR__ . '/fixtures/media/harness.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';

/** The installer's arguments, one per call. */
function jfInstaller(array $fx)
{
    return file_exists($fx['dir'] . '/installer.log') ? file($fx['dir'] . '/installer.log', FILE_IGNORE_NEW_LINES) : [];
}

return [
    'jellyfin: restart refuses while ~/.jellyfinDisable exists and runs the installer only with --stop-app/--start-app' => function () {
        $fx = jfFixture();
        try {
            touch($fx['home'] . '/.jellyfinDisable');
            list($code, , $out) = jfRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'turned off') !== false && jfInstaller($fx) === [], 'disabled: ' . $out);
            unlink($fx['home'] . '/.jellyfinDisable');
            list($code, $l, $out) = jfRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && strpos($l[0]['how'], '--stop-app=jellyfin') !== false, 'restart: ' . $out);
            check(jfInstaller($fx) === ['--stop-app=jellyfin', '--start-app=jellyfin'],
                'installer arguments: ' . json_encode(jfInstaller($fx)));
            check(!preg_grep('#System/Restart#', mediaCalls($fx)), 'the API restart was used');
            unlink($fx['home'] . '/.bashrc.custom');
            list($code, , $out) = jfRun($fx, ['restart']);
            check($code === 1 && strpos($out, 'launch aliases') !== false && count(jfInstaller($fx)) === 2, 'no aliases: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: restart of a stopped server waits on the configured BaseUrl, not the bare path that answers 302' => function () {
        $fx = jfFixture();
        try {
            jfRun($fx, ['stats']); // a session is stored
            file_put_contents($fx['dir'] . '/power', 'down'); // stopped or starting: the prefix answers 503, the bare path 302
            list($code, , $out) = jfRun($fx, ['stats']);
            check($code === 1 && strpos($out, 'still starting up') !== false, 'stats while starting: ' . $out);
            list($code, $l, $out) = jfRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true && $l[0]['state'] === 'running', 'restart: ' . $out);
            $calls = mediaCalls($fx);
            check(end($calls) === 'GET /System/Info/Public' && jfInstaller($fx) === ['--stop-app=jellyfin', '--start-app=jellyfin'],
                'polls: ' . implode(', ', array_slice($calls, -4)));
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: restart needs no credential: no stored session, the server stopped or gone, and the installer still runs' => function () {
        $fx = jfFixture();
        try {
            file_put_contents($fx['dir'] . '/power', 'down');
            list($code, $l, $out) = jfRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === true, 'stopped: ' . $out);
            check(mediaState($fx)['logins'] === 0 && !preg_grep('#AuthenticateByName#', mediaCalls($fx))
                && !isset(jfSecret($fx)['session']),
                'a login was sent: ' . implode(', ', mediaCalls($fx)));
            proc_terminate($fx['proc']);
            proc_close($fx['proc']);
            $fx['proc'] = null; // nothing listens now
            list($code, $l, $out) = jfRun($fx, ['restart']);
            check($code === 0 && $l[0]['answered'] === false && count(jfInstaller($fx)) === 4, 'gone: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: a server still answering after --stop-app is "stop not confirmed", and --start-app is not run' => function () {
        $fx = jfFixture();
        try {
            $log = escapeshellarg($fx['dir'] . '/installer.log');
            file_put_contents($fx['home'] . '/install-media-stack.sh', "printf '%s\\n' \"\$*\" >> $log\n"); // records, never stops
            list($code, , $out) = jfRun($fx, ['restart'], ['stop_wait' => 2]);
            check($code === 1 && strpos($out, 'stop not confirmed') !== false
                && jfInstaller($fx) === ['--stop-app=jellyfin'], 'unconfirmed: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
];
