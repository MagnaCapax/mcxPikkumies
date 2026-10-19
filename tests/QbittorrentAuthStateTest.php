<?php
/**
 * tools/torrent-clients/qbittorrent: on PMSS, no WebUI hash chosen yet (no WebUI\Password_PBKDF2 line, or the panel's
 * all-zero placeholder) means no login is tried, by any verb or by `login`, and the next step is the panel's prompt.
 * Elsewhere nothing changes. Fake WebUI (tests/fixtures/qbittorrent/router.php), no real client.
 */
require_once __DIR__ . '/fixtures/qbittorrent/harness.php';

if (!function_exists('qbitConfHash')) {
    /** Rewrites the fixture's PBKDF2 line: null removes it, a string replaces it. */
    function qbitConfHash(array $fx, $value)
    {
        $f = $fx['home'] . '/.config/qBittorrent/qBittorrent.conf';
        $conf = preg_replace('/^WebUI\\\\Password_PBKDF2=.*\n/m', '', (string) file_get_contents($f));
        file_put_contents($f, $conf . ($value === null ? '' : "WebUI\\Password_PBKDF2=$value\n"));
    }
}

$zeros = '@ByteArray(AAAAAAAAAAAAAAAAAAAAAA==:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==)';

return [
    'qbittorrent auth state: the placeholder or no PBKDF2 line means no login request at all, and the panel prompt as the next step' => function () use ($zeros) {
        foreach ([$zeros, null] as $value) {
            qbitWith([], function ($fx) use ($value) {
                qbitConfHash($fx, $value);
                list($code, $out) = qbitRun($fx, ['stats']);
                check($code === 1 && strpos($out, 'no login was tried') !== false && strpos($out, 'start or restart in the Pulsed Media panel') !== false, 'stats: ' . $out);
                check(qbitState($fx)['logins'] === 0, 'a login request was sent while no WebUI hash exists: ' . qbitState($fx)['logins']);
                check(strpos($out, 'AAAAAAAAAAAA') === false && strpos($out, 'right-one') === false, 'the hash or the stored secret reached the output: ' . $out);
                $stored = json_decode((string) file_get_contents($fx['dir'] . '/secrets/qbittorrent'), true);
                check($stored['rejected'] === false, 'the stored secret was marked rejected without being tried');
                list($code, $out) = qbitRun($fx, ['login']);
                check($code === 1 && strpos($out, 'no login was tried') !== false && strpos($out, 'terminal, and stdin') === false, 'login prompted although nothing can log in: ' . $out);
            });
        }
    },
    'qbittorrent auth state: a real hash is tried as before; outside PMSS a missing line changes nothing' => function () use ($zeros) {
        qbitWith([], function ($fx) {
            list($code, $out) = qbitRun($fx, ['stats']);
            check($code === 0 && qbitState($fx)['logins'] === 1, 'a real hash, yet no login: ' . $out);
        });
        qbitWith([], function ($fx) {
            qbitConfHash($fx, null);
            list($code, $out) = qbitRun($fx, ['stats'], 'generic');
            check($code === 0 && qbitState($fx)['logins'] === 1, 'the PMSS rule ran on another host: ' . $out);
        });
        qbitWith([], function ($fx) use ($zeros) {
            qbitConfHash($fx, $zeros . 'x'); // not exactly the placeholder: a real (if odd) value, tried
            list($code, $out) = qbitRun($fx, ['stats']);
            check(qbitState($fx)['logins'] === 1, 'a value that only starts like the placeholder was treated as none: ' . $out);
        });
    },
];
