<?php
/**
 * Security-review fix (F1): Transmission RPC authentication is never refused into a "turn it off"
 * suggestion, a stored `login` credential is sent as HTTP Basic auth on every call, and a 401 marks
 * it rejected (never tried again until `login` runs again) -- the same discipline qBittorrent's
 * `login`/QbitSecret already has, now mirrored for Transmission.
 */
if (!function_exists('transmissionFixture')) {
    require __DIR__ . '/fixtures/transmission/helpers.php';
}

/** Every hint text this unit can produce for an auth-related refusal, from source, never live-grepped
 * against a running process: a static sweep of the shipped unit for the words a security reviewer
 * flagged. */
function transmissionAuthHintsNeverSuggestDisabling()
{
    $dir = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/transmission';
    $bad = [];
    foreach (glob("$dir/*.php") as $f) {
        // Sentence-scoped (split on . ! ? and newlines): a sentence that itself says "never" is the
        // fix's own reassurance, not an actual suggestion -- only an UNGUARDED sentence is a violation.
        foreach (preg_split('/(?<=[.!?\n])/', (string) file_get_contents($f)) as $sentence) {
            if (stripos($sentence, 'never') !== false) {
                continue;
            }
            if (preg_match('/turn[^.\n]*authentication[^.\n]*off/i', $sentence) || preg_match('/disable[^.\n]*authentication/i', $sentence)) {
                $bad[] = basename($f) . ': ' . trim($sentence);
            }
        }
    }
    return $bad;
}

return [
    'transmission auth: no source file suggests turning RPC authentication off (security review F1)' => function () {
        check(transmissionAuthHintsNeverSuggestDisabling() === [], 'a hint still suggests disabling authentication: '
            . json_encode(transmissionAuthHintsNeverSuggestDisabling()));
    },
    'transmission auth: no credential stored yet refuses naming `login` or the unix socket, never authentication-off' => function () {
        $fx = transmissionFixture([], ['rpc-authentication-required' => true, 'rpc-username' => 'alice']);
        try {
            // An isolated, guaranteed-empty secrets dir: the default (Config::localDir()) would read the
            // real repo's pikkumies/local/secrets, which other tests or the user's own login may have populated.
            list($code, , $out) = transmissionRunAuth($fx, ['list'], $fx['dir'] . '/empty-secrets');
            check($code === 1, "must refuse without a stored credential: $out");
            check(strpos($out, 'login') !== false || strpos($out, 'unix socket') !== false, 'the hint names neither login nor the unix socket: ' . $out);
            check(stripos($out, 'turn') === false && stripos($out, 'disable') === false, 'the hint still suggests turning authentication off: ' . $out);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission auth: a stored, correct credential is sent as HTTP Basic auth on every request' => function () {
        $fx = transmissionFixture(['auth' => ['username' => 'alice', 'secret' => 'correct-horse']],
            ['rpc-authentication-required' => true, 'rpc-username' => 'alice']);
        $secretDir = $fx['dir'] . '/secrets';
        @mkdir($secretDir, 0700, true);
        file_put_contents("$secretDir/transmission", json_encode(['secret' => 'correct-horse', 'rejected' => false]));
        try {
            list($code, $l, $out) = transmissionRunAuth($fx, ['list'], $secretDir);
            check($code === 0, "a correct stored credential must be accepted: $out");
            check(count($l) >= 1, 'no records came back with a correct credential: ' . $out);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission auth: a stored, wrong credential gets a 401, the hint says run login again (never disable), and it is marked rejected' => function () {
        $fx = transmissionFixture(['auth' => ['username' => 'alice', 'secret' => 'correct-horse']],
            ['rpc-authentication-required' => true, 'rpc-username' => 'alice']);
        $secretDir = $fx['dir'] . '/secrets';
        @mkdir($secretDir, 0700, true);
        file_put_contents("$secretDir/transmission", json_encode(['secret' => 'wrong-one', 'rejected' => false]));
        try {
            list($code, , $out) = transmissionRunAuth($fx, ['list'], $secretDir);
            check($code === 1 && strpos($out, '401') !== false, "a wrong credential must be refused with the 401: $out");
            check(strpos($out, 'login') !== false, 'the 401 hint does not point to login: ' . $out);
            check(stripos($out, 'turn') === false && stripos($out, 'disable') === false, 'the 401 hint still suggests turning authentication off: ' . $out);
            $stored = json_decode(file_get_contents("$secretDir/transmission"), true);
            check($stored['rejected'] === true, 'the wrong credential was not marked rejected: ' . json_encode($stored));
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission auth: login refuses when authentication is off on this account (nothing to store)' => function () {
        $fx = transmissionFixture(); // the default fixture: rpc-authentication-required false
        try {
            list($code, , $out) = transmissionRunAuth($fx, ['login'], $fx['dir'] . '/empty-secrets');
            check($code === 1 && strpos($out, 'does not require') !== false, 'login must refuse when auth is off: ' . $out);
        } finally {
            transmissionDone($fx);
        }
    },
    'transmission auth: login needs a terminal; nothing is stored without one' => function () {
        $fx = transmissionFixture([], ['rpc-authentication-required' => true, 'rpc-username' => 'alice']);
        $secretDir = $fx['dir'] . '/secrets';
        try {
            list($code, , $out) = transmissionRunAuth($fx, ['login'], $secretDir);
            check($code === 1 && strpos($out, 'terminal') !== false, 'login without a tty must refuse: ' . $out);
            check(!is_file("$secretDir/transmission"), 'login without a tty still stored something');
        } finally {
            transmissionDone($fx);
        }
    },
];
