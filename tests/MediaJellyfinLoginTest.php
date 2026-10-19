<?php
/** jellyfin login-check, set-login and config against the fake (tests/fixtures/media/mediabrowser.php, jellyfin.php). */
require_once __DIR__ . '/fixtures/media/units.php';
require_once __DIR__ . '/fixtures/media/jellyfin.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/lib/Mediabrowser.php';
require_once dirname(__DIR__) . '/pikkumies/tools/media-servers/jellyfin/JellyfinLogin.php';

/** [cause => next] of a login-check run. */
function jfCauses(array $l)
{
    $out = [];
    foreach (array_slice($l, 0, -1) as $r) {
        $out[$r['cause']] = $r['next'];
    }
    return $out;
}

/** The fixture's credentials file with the JELLYFIN_PASSWORD line replaced (null: no lines at all). */
function jfLine(array $fx, $value)
{
    $f = $fx['home'] . '/.media-stack-credentials.txt';
    file_put_contents($f, "# PMSS media stack credentials\nJELLYFIN_URL = https://host.example/x/\n"
        . ($value === null ? '' : "JELLYFIN_USERNAME = fake\nJELLYFIN_" . "PASSWORD = $value\n"));
    chmod($f, 0600);
}

/** jfRun with the change log in the fixture, never in pikkumies/local (ArrAuth::log reads PIKKUMIES_ARR_LOG_DIR). */
function jfLogRun(array $fx, array $args)
{
    putenv('PIKKUMIES_ARR_LOG_DIR=' . $fx['dir'] . '/arr-log');
    try {
        return jfRun($fx, $args);
    } finally {
        putenv('PIKKUMIES_ARR_LOG_DIR');
    }
}

return [
    'jellyfin login-check: the file password that works is reported with where it is; nothing is printed or changed' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfLogRun($fx, ['login-check']);
            $st = $l[count($l) - 1];
            check($code === 0 && $st['login'] === 'works' && jfCauses($l) === ['none' => 'none'] && $st['password_file'] === '~/.media-stack-credentials.txt', 'works: ' . $out);
            check(mediaState($fx)['logins'] === 1 && !in_array('POST /Users/Password?userId=u1', mediaCalls($fx), true), 'one login, no change');
            check(jfSecret($fx)['session'] === JF_SESSION, 'the session of the check is kept for the next verbs');
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin login-check: placeholder, missing lines, a refused password (marked, then not tried again) and an unfinished wizard' => function () {
        $fx = jfFixture();
        try {
            jfLine($fx, '[existing Jellyfin password preserved; not known to installer]');
            list(, $l, $out) = jfLogRun($fx, ['login-check']);
            check(array_keys(jfCauses($l)) === ['credentials-placeholder'] && mediaState($fx)['logins'] === 0, 'placeholder: ' . $out);
            jfLine($fx, null);
            list(, $l, $out) = jfLogRun($fx, ['login-check']);
            check(array_keys(jfCauses($l)) === ['credentials-missing'] && strpos($out, 'skill.md') !== false, 'missing: ' . $out);
            jfLine($fx, JF_WRONG_LOGIN);
            list($code, $l, $out) = jfLogRun($fx, ['login-check']);
            check($code === 0 && array_keys(jfCauses($l)) === ['password-rejected'] && !empty(jfSecret($fx)['pw_rejected']), 'rejected: ' . $out);
            list(, $l, $out) = jfLogRun($fx, ['login-check']);
            check(array_keys(jfCauses($l)) === ['password-rejected'] && mediaState($fx)['logins'] === 1 && strpos($out, 'not tried again') !== false, 'tried twice: ' . $out);
            check(strpos($out, JF_WRONG_LOGIN) === false, 'the file value was printed');
        } finally {
            mediaDone($fx);
        }
        $fx = jfFixture(['wizard_open' => true]);
        try {
            list(, $l, $out) = jfLogRun($fx, ['login-check']);
            check(jfCauses($l) === ['startup-not-complete' => 'bash ~/install-media-stack.sh --secure-app=jellyfin'] && mediaState($fx)['logins'] === 0, 'wizard: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin set-login: file first (0600, other lines kept), changed with the current one, checked by a real login, never printed' => function () {
        $fx = jfFixture();
        try {
            list($code, $l, $out) = jfLogRun($fx, ['set-login']);
            $f = $fx['home'] . '/.media-stack-credentials.txt';
            $new = MediaStackCredentials::read($fx['home'])['JELLYFIN_PASSWORD'];
            check($code === 0 && $l[0]['login'] === 'works' && $l[0]['username'] === 'fake' && $l[0]['password_line'] === 'JELLYFIN_PASSWORD', 'set-login: ' . $out);
            check(strlen($new) === 24 && $new !== JF_LOGIN && strpos($out, $new) === false && mediaState($fx)['password'] === $new, 'value, change or leak');
            check(strpos(file_get_contents($f), 'JELLYFIN_URL = https://host.example/public-fake/jellyfin/web/') !== false && (fileperms($f) & 0777) === 0600, 'file');
            check(in_array('POST /Users/Password?userId=u1', mediaCalls($fx), true) && mediaState($fx)['logins'] === 2, 'calls: ' . implode(', ', mediaCalls($fx)));
            $log = (string) file_get_contents($fx['dir'] . '/arr-log/' . gmdate('Ymd') . '.jsonl');
            check(strpos($log, '"verb":"set-login","app":"jellyfin","outcome":"set"') !== false && strpos($log, $new) === false, 'log: ' . $log);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin set-login: a refused change puts the file back exactly; one Jellyfin takes but cannot log in with ends ok:false keeping the new line' => function () {
        $fx = jfFixture(['pw_fail' => true]);
        try {
            $f = $fx['home'] . '/.media-stack-credentials.txt';
            $before = file_get_contents($f);
            list($code, , $out) = jfLogRun($fx, ['set-login']);
            check($code === 1 && strpos($out, 'is as it was') !== false && file_get_contents($f) === $before && mediaState($fx)['password'] === JF_LOGIN, 'refused: ' . $out);
        } finally {
            mediaDone($fx);
        }
        $fx = jfFixture(['pw_ignore' => true]);
        try {
            list($code, , $out) = jfLogRun($fx, ['set-login']);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 1 && strpos($out, 'the login check failed') !== false && strlen($v['JELLYFIN_PASSWORD']) === 24
                && strpos($out, $v['JELLYFIN_PASSWORD']) === false, 'unverified: ' . $out);
        } finally {
            mediaDone($fx);
        }
        $fx = jfFixture();
        try {
            jfLine($fx, JF_WRONG_LOGIN);
            $before = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            list($code, , $out) = jfLogRun($fx, ['set-login']);
            check($code === 1 && strpos($out, 'nothing changed') !== false && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before
                && !in_array('POST /Users/Password?userId=u1', mediaCalls($fx), true), 'wrong current: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin config: show hides secrets and skips lists; set keeps the type, posts the rest back unchanged and reads it back; login, network and paths are refused' => function () {
        $raw = '{"ServerName":"fake","MinResumePct":5,"EnableLegacyAuthorization":false,"QuickConnectAvailable":true,"CachePath":null,'
            . '"RemoteLoginToken":"' . 'tok' . 'Value9876' . '","CorsHosts":["*"],"TrickplayOptions":{"Interval":10},"Empty":{}}';
        $fx = jfFixture(['config_raw' => $raw]);
        try {
            list($code, $l, $out) = jfLogRun($fx, ['config', 'show']);
            $v = array_column(array_slice($l, 0, -1), 'value', 'field');
            check($code === 0 && $v['ServerName'] === 'fake' && $v['RemoteLoginToken'] === 'hidden' && !isset($v['CorsHosts']) && !isset($v['TrickplayOptions'])
                && strpos($out, 'Value9876') === false, 'show: ' . $out);
            list($code, $l, $out) = jfLogRun($fx, ['config', 'set', 'MinResumePct', '7']);
            $sent = json_decode(mediaState($fx)['config_raw']);
            check($code === 0 && $l[0]['old'] === 5 && $l[0]['value'] === 7 && $sent->MinResumePct === 7 && $sent->ServerName === 'fake', 'set: ' . $out);
            check(strpos(mediaState($fx)['config_raw'], '"Empty":{}') !== false && strpos(mediaState($fx)['config_raw'], '"TrickplayOptions":{"Interval":10}') !== false, 'posted back changed: ' . mediaState($fx)['config_raw']);
            foreach ([['EnableLegacyAuthorization', 'true'], ['QuickConnectAvailable', 'false'], ['CachePath', '/tmp/x'], ['MinResumePct', 'many'],
                ['RemoteLoginToken', 'x']] as $bad) {
                $posts = count(array_keys(mediaCalls($fx), 'POST /System/Configuration'));
                list($code, , $out) = jfLogRun($fx, ['config', 'set', $bad[0], $bad[1]]);
                check($code === 1 && count(array_keys(mediaCalls($fx), 'POST /System/Configuration')) === $posts, "{$bad[0]} was sent: $out");
            }
            mediaSetState($fx, ['cfg_ignore' => true]);
            list($code, , $out) = jfLogRun($fx, ['config', 'set', 'ServerName', 'other']);
            check($code === 1 && strpos($out, 'reads back') !== false, 'not read back: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: only the changing verbs may send the password and configuration writes; the network configuration stays refused' => function () {
        $fx = mediaFixture('mediabrowser', ['kind' => 'jellyfin', 'base' => '', 'version' => '12.2.0', 'tokens' => ['k'], 'libraries' => [], 'sessions' => []]);
        try {
            $uid = posix_geteuid();
            $plain = new MediaHttp('Jellyfin', 'http://127.0.0.1:' . $fx['port'], Mediabrowser::ALLOW, $uid, 5);
            $change = new MediaHttp('Jellyfin', 'http://127.0.0.1:' . $fx['port'], JellyfinLogin::allow(), $uid, 5);
            foreach ([[$plain, 'POST', '/Users/Password'], [$plain, 'POST', '/System/Configuration'], [$plain, 'GET', '/System/Configuration'],
                [$change, 'POST', '/System/Configuration/network'], [$change, 'POST', '/Users/u1/Policy'], [$change, 'POST', '/Startup/User']] as $c) {
                check($c[0]->refusal($c[1], $c[2]) !== '', "not refused: {$c[1]} {$c[2]}");
            }
            check($change->refusal('POST', '/Users/Password') === '' && $change->refusal('GET', '/Sessions') === '', 'the change list lost a route');
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin: the README cause table mirrors JellyfinLogin::CAUSES; the skill points to login-check; USAGE lists the three verbs' => function () {
        $dir = dirname(__DIR__) . '/pikkumies/tools/media-servers/jellyfin';
        $readme = (string) file_get_contents("$dir/README.md");
        $c = strpos($readme, '### Why a Jellyfin login fails');
        check($c !== false, 'README lacks the cause table');
        preg_match_all('/^\| ([a-z-]+) \|/m', substr($readme, $c, strpos($readme, "\n## ", $c) - $c), $m);
        $doc = array_values(array_diff($m[1], ['cause']));
        check($doc === array_keys(JellyfinLogin::CAUSES), 'README causes ' . implode(',', $doc) . ' but the code has ' . implode(',', array_keys(JellyfinLogin::CAUSES)));
        $skill = (string) file_get_contents("$dir/skill.md");
        check(strpos($skill, 'run `login-check` and do its `next`') !== false && strpos($skill, 'never print the password') !== false
            && strpos($skill, '`~/.media-stack-credentials.txt`') !== false && strpos($skill, 'Jellyfin login not known') !== false, 'skill.md lacks the pointer');
        $verbs = array_column(Units::verbs((string) file_get_contents("$dir/USAGE.md")), 'verb');
        check(array_slice($verbs, -3) === ['login-check', 'set-login', 'config'], 'USAGE: ' . implode(',', $verbs));
    },
    'jellyfin set-login with no answer never loses the new password: new works keeps it, only the old works restores, neither keeps both' => function () {
        $f = 'JELLYFIN_' . 'PASSWORD';
        $fx = jfFixture(['pw_hang' => true]); // Jellyfin took it, then the answer never came
        try {
            list($code, $l, $out) = jfRun($fx, ['set-login'], ['timeout' => 2]);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 0 && $l[0]['login'] === 'works' && mediaState($fx)['password'] === $v[$f] && !isset($v[$f . '_PREVIOUS']), 'took it: ' . $out);
        } finally {
            mediaDone($fx);
        }
        $fx = jfFixture(['pw_hang' => true, 'pw_ignore' => true]); // no answer, and the old one still works
        try {
            $before = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            list($code, , $out) = jfRun($fx, ['set-login'], ['timeout' => 2]);
            check($code === 1 && strpos($out, 'still takes the old password') !== false
                && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before, 'old kept: ' . $out);
        } finally {
            mediaDone($fx);
        }
        $fx = jfFixture(['pw_hang' => true, 'pw_down' => true]); // took it and went down: neither can be checked
        try {
            list($code, , $out) = jfRun($fx, ['set-login'], ['timeout' => 2]);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 1 && strpos($out, 'keeps both') !== false && $v[$f . '_PREVIOUS'] === JF_LOGIN && strlen($v[$f]) === 24
                && mediaState($fx)['password'] === $v[$f] && strpos($out, $v[$f]) === false, 'both: ' . $out);
            $text = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            check(strpos($text, "$f = ") < strpos($text, "{$f}_PREVIOUS = "), 'the old line comes after the active one: ' . str_replace([JF_LOGIN, $v[$f]], 'X', $text));
            file_put_contents($fx['dir'] . '/power', 'up');
            mediaSetState($fx, ['pw_hang' => false]);
            list($code, , $out) = jfRun($fx, ['set-login']);
            check($code === 0 && !isset(MediaStackCredentials::read($fx['home'])[$f . '_PREVIOUS']), 'a later set-login that works drops the old line: ' . $out);
        } finally {
            mediaDone($fx);
        }
    },
    'jellyfin takes its home from Host::home: HOME only when this uid owns that folder, else the passwd entry' => function () {
        $uid = posix_geteuid();
        $pw = posix_getpwuid($uid)['dir'];
        $own = testTempDir('pk-home');
        try {
            check(Host::home($uid, $own) === $own, 'an own HOME was not taken');
            check(Host::home($uid, "$own/missing") === rtrim($pw, '/'), 'a HOME that does not exist was taken');
            if ($uid !== 0) {
                check(Host::home($uid, '/') === rtrim($pw, '/'), 'a HOME another account owns (/) was taken');
            }
        } finally {
            testRmTree($own);
        }
        $code = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/media-servers/jellyfin/jellyfin.php');
        check(strpos($code, '$jfHome = Host::home($jfUid)') !== false && strpos($code, "getenv('HOME')") === false, 'jellyfin.php reads HOME raw');
    },
];
