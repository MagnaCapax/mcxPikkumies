<?php
/** login-check, set-login and config of autobrr, Bazarr and Jackett against their fakes (tests/fixtures/arr/*-router.php, autobrrctl.php). */
require_once __DIR__ . '/fixtures/arr/autobrr-helpers.php';
require_once __DIR__ . '/fixtures/arr/jackett.php';

/** The credentials file of a fixture home with <APP>_USERNAME/_PASSWORD lines (null: no file). */
function otherCreds(array $fx, $app, $value)
{
    $f = $fx['home'] . '/.media-stack-credentials.txt';
    if ($value === null) {
        @unlink($f);
        return;
    }
    $k = strtoupper($app);
    file_put_contents($f, "# PMSS media stack credentials\nOTHER_URL = https://host.example.com/x/\n{$k}_USERNAME = exampleuser\n{$k}_" . "PASSWORD = $value\n");
    chmod($f, 0600);
}

/** The fake autobrrctl at ~/.bin/autobrr/autobrrctl, where the PMSS installer puts the real one. */
function otherCtl(array $fx)
{
    $ctl = $fx['home'] . '/.bin/autobrr/autobrrctl';
    @mkdir(dirname($ctl), 0700, true);
    file_put_contents($ctl, '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/fixtures/arr/autobrrctl.php'));
    chmod($ctl, 0700);
}

function otherCauses(array $l)
{
    $out = [];
    foreach (array_slice($l, 0, -1) as $r) {
        $out[$r['cause']] = $r['next'];
    }
    return $out;
}

/** Bazarr settings as GET system/settings answers them, with the login of the fixture user. */
function otherBazarrSettings($type = 'form')
{
    return ['general' => ['debug' => false, 'page_size' => 25, 'ip' => '127.0.0.1', 'postprocessing_cmd' => '', 'enabled_providers' => ['a']],
        'auth' => ['apikey' => ARR_KEY, 'type' => $type, 'username' => 'exampleuser', 'password' => md5(ARR_LOGIN)],
        'sonarr' => ['ip' => '127.0.0.1', 'apikey' => ARR_DECOY], 'anticaptcha' => ['anti_captcha_key' => 'cap' . 'tcha' . 'Key0042'],
        'addic7ed' => ['cookies' => 'sess' . 'Cookie0043', 'username' => 'x'], 'backup' => ['folder' => '/home/u/.config/bazarr/backup', 'retention' => 31]];
}

return [
    'autobrr login-check: a working login, no user yet (first-user page open), a placeholder and a missing autobrrctl are named' => function () {
        $fx = arrOtherFixture('autobrr', ['abUser' => 'exampleuser', 'abPassword' => ARR_LOGIN]);
        try {
            otherCreds($fx, 'autobrr', ARR_LOGIN);
            list($code, $l, $out) = abRun($fx, ['login-check']);
            check($code === 0 && $l[count($l) - 1]['login'] === 'works' && otherCauses($l) === ['none' => 'none'], 'works: ' . $out);
            otherCreds($fx, 'autobrr', '[existing Autobrr password preserved; not known to installer]');
            list(, $l, $out) = abRun($fx, ['login-check']);
            $c = otherCauses($l);
            check(array_keys($c) === ['credentials-placeholder', 'ctl-missing'] && strpos($c['ctl-missing'], 'change-password exampleuser') !== false, 'placeholder: ' . $out);
            otherCreds($fx, 'autobrr', 'Wrong-Value-0003');
            otherCtl($fx);
            list(, $l, $out) = abRun($fx, ['login-check']);
            check(otherCauses($l) === ['password-rejected' => 'set-login'] && strpos($out, 'Wrong-Value-0003') === false, 'rejected: ' . $out);
            arrSet($fx, ['abUser' => null]);
            $s = arrState($fx);
            unset($s['abUser']);
            file_put_contents($fx['dir'] . '/state.json', json_encode($s));
            list(, $l, $out) = abRun($fx, ['login-check']);
            check(otherCauses($l) === ['no-user' => 'bash ~/install-media-stack.sh --secure-app=autobrr'], 'no user: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'autobrr set-login: file first, autobrrctl with the password on stdin (never argv), a real login, never printed; a failed run restores the file' => function () {
        $fx = arrOtherFixture('autobrr', ['abUser' => 'exampleuser', 'abPassword' => ARR_LOGIN]);
        try {
            otherCreds($fx, 'autobrr', ARR_LOGIN);
            otherCtl($fx);
            list($code, $l, $out) = abRun($fx, ['set-login']);
            $new = MediaStackCredentials::read($fx['home'])['AUTOBRR_PASSWORD'];
            $ctlLog = (string) file_get_contents($fx['dir'] . '/ctl.log');
            check($code === 0 && $l[0]['login'] === 'works' && $l[0]['username'] === 'exampleuser' && strlen($new) === 24 && strpos($out, $new) === false, 'set: ' . $out);
            check(arrState($fx)['abPassword'] === $new && strpos($ctlLog, $new) === false && strpos($ctlLog, '"change-password","exampleuser"') !== false
                && arrState($fx)['abConfigDir'] === $fx['home'] . '/.config/autobrr', 'ctl: ' . $ctlLog);
            check(strpos((string) file_get_contents($fx['home'] . '/.media-stack-credentials.txt'), 'OTHER_URL = https://host.example.com/x/') !== false, 'other lines');
            $log = (string) file_get_contents($fx['dir'] . '/arr-log/' . gmdate('Ymd') . '.jsonl');
            check(strpos($log, '"verb":"set-login","app":"autobrr","outcome":"set"') !== false && strpos($log, $new) === false, 'log: ' . $log);
            arrSet($fx, ['ctlFail' => true]);
            $before = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            list($code, , $out) = abRun($fx, ['set-login']);
            check($code === 1 && strpos($out, 'is as it was') !== false && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before
                && arrState($fx)['abPassword'] === $new, 'ctl failed: ' . $out);
            unlink($fx['home'] . '/.bin/autobrr/autobrrctl');
            list($code, , $out) = abRun($fx, ['set-login']);
            check($code === 1 && strpos($out, 'nothing changed') !== false && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before, 'no ctl: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr login-check and set-login: auth off is named; the login is set through the key-authorised settings API, file first, checked by a real login' => function () {
        $fx = arrOtherFixture('bazarr', ['settings' => otherBazarrSettings('')]);
        try {
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            check($code === 0 && array_keys(otherCauses($l)) === ['auth-not-configured', 'credentials-missing'], 'auth off: ' . $out);
            otherCreds($fx, 'bazarr', 'Old-Value-0004');
            list($code, $l, $out) = arrRun($fx, ['set-login']);
            $new = MediaStackCredentials::read($fx['home'])['BAZARR_PASSWORD'];
            $auth = arrState($fx)['settings']['auth'];
            check($code === 0 && $l[0]['login'] === 'works' && strlen($new) === 24 && strpos($out, $new) === false, 'set: ' . $out);
            check($auth['type'] === 'form' && $auth['username'] === 'exampleuser' && $auth['password'] === md5($new) && $auth['apikey'] === ARR_KEY, 'stored: ' . json_encode($auth));
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            check($code === 0 && $l[count($l) - 1]['login'] === 'works', 'works after: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr set-login: a refused change (that echoes the value) restores the file and hides the value; one not stored ends ok:false' => function () {
        $fx = arrOtherFixture('bazarr', ['settings' => otherBazarrSettings(), 'settingsFail' => true]);
        try {
            otherCreds($fx, 'bazarr', ARR_LOGIN);
            $before = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            list($code, , $out) = arrRun($fx, ['set-login']);
            check($code === 1 && strpos($out, 'nothing changed') !== false && strpos($out, 'REDACTED') !== false
                && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before, 'refused: ' . $out);
            arrSet($fx, ['settingsFail' => false, 'settingsIgnore' => true]);
            list($code, , $out) = arrRun($fx, ['set-login']);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 1 && strpos($out, 'the check failed') !== false && strlen($v['BAZARR_PASSWORD']) === 24 && strpos($out, $v['BAZARR_PASSWORD']) === false, 'ignored: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr config: show hides keys and skips lists; set keeps the type and reads back; auth, addresses, commands and keys are refused' => function () {
        $fx = arrOtherFixture('bazarr', ['settings' => otherBazarrSettings()]);
        try {
            list($code, $l, $out) = arrRun($fx, ['config', 'show']);
            $v = [];
            foreach (array_slice($l, 0, -1) as $r) {
                $v[$r['section'] . '.' . $r['field']] = $r['value'];
            }
            check($code === 0 && $v['general.page_size'] === 25 && $v['anticaptcha.anti_captcha_key'] === 'hidden' && $v['auth.password'] === 'hidden'
                && !isset($v['general.enabled_providers']) && strpos($out, 'Key0042') === false, 'show: ' . $out);
            check($v['addic7ed.cookies'] === 'hidden' && strpos($out, 'Cookie0043') === false && $v['backup.retention'] === 31, 'cookies shown: ' . $out);
            list($code, $l, $out) = arrRun($fx, ['config', 'set', 'general', 'debug', 'true']);
            check($code === 0 && $l[0]['value'] === true && arrState($fx)['settings']['general']['debug'] === true, 'set: ' . $out);
            foreach ([['auth', 'type', 'form'], ['sonarr', 'ip', '127.0.0.2'], ['general', 'postprocessing_cmd', 'touch x'], ['general', 'page_size', 'many'],
                ['anticaptcha', 'anti_captcha_key', 'x'], ['general', 'ip', '0.0.0.0'], ['addic7ed', 'cookies', 'x=1'], ['backup', 'folder', '/tmp/b']] as $bad) {
                $posts = count(arrCalls($fx, 'POST', 'system/settings'));
                list($code, , $out) = arrRun($fx, ['config', 'set', $bad[0], $bad[1], $bad[2]]);
                check($code === 1 && count(arrCalls($fx, 'POST', 'system/settings')) === $posts, implode('.', $bad) . ' was sent: ' . $out);
            }
        } finally {
            arrP2Done($fx);
        }
    },
    'jackett login-check: no admin password (dashboard open), one set (the tool cannot check it) and external access are named; nothing changes' => function () {
        $fx = arrJackettFixture();
        try {
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            check($code === 0 && array_keys(otherCauses($l)) === ['password-unknown'] && strpos($out, str_repeat('ab', 8)) === false, 'set: ' . $out);
            $j = json_decode(file_get_contents($fx['config']), true);
            file_put_contents($fx['config'], json_encode(['AdminPassword' => null, 'AllowExternal' => true] + $j));
            list(, $l, $out) = arrRun($fx, ['login-check']);
            check(array_keys(otherCauses($l)) === ['external-access', 'no-admin-password'], 'open: ' . $out);
            check(!array_filter(arrState($fx)['calls'], function ($c) {
                return $c['method'] !== 'GET';
            }), 'jackett was sent a change');
        } finally {
            arrP2Done($fx);
        }
    },
    'bazarr set-login with no answer tries the new login before restoring: it works, set-login goes on; it does not, the old line comes back' => function () {
        $fx = arrOtherFixture('bazarr', ['settings' => otherBazarrSettings(), 'settingsHang' => true]);
        try {
            otherCreds($fx, 'bazarr', ARR_LOGIN);
            list($code, $l, $out) = arrRun($fx, ['set-login'], ['PIKKUMIES_ARR_TIMEOUT' => '2']);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 0 && $l[0]['login'] === 'works' && arrState($fx)['settings']['auth']['password'] === md5($v['BAZARR_' . 'PASSWORD']), 'took it: ' . $out);
            arrSet($fx, ['settingsIgnore' => true]);
            $before = file_get_contents($fx['home'] . '/.media-stack-credentials.txt');
            list($code, , $out) = arrRun($fx, ['set-login'], ['PIKKUMIES_ARR_TIMEOUT' => '2']);
            check($code === 1 && strpos($out, 'did not take the new login') !== false && file_get_contents($fx['home'] . '/.media-stack-credentials.txt') === $before, 'not taken: ' . $out);
        } finally {
            arrP2Done($fx);
        }
    },
];
