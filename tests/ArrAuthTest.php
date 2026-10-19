<?php
/** login-check and set-login of the Servarr units against the fake (tests/fixtures/arr/: config-routes.php, login.php). */
require_once __DIR__ . '/fixtures/arr/harness.php';

/** A PMSS fixture whose UrlBase is the installer's proxy path for this account, with the user's login in the credentials file. */
function arrAuthFixture($app, array $over = [], $line = ARR_LOGIN)
{
    $fx = arrFixture($app, $over + ['base' => ArrAuth::proxyPath($app)]);
    $k = MediaStackCredentials::appKey($app);
    if ($line !== null) {
        file_put_contents($fx['home'] . '/.media-stack-credentials.txt', "# PMSS media stack credentials\nOTHER_URL = https://host.example.com/x/\n"
            . "{$k}_USERNAME = exampleuser\n{$k}_PASSWORD = $line\n");
        chmod($fx['home'] . '/.media-stack-credentials.txt', 0600);
    }
    return $fx;
}

/** [cause => next] from a login-check run. */
function arrCauses(array $l)
{
    $out = [];
    foreach (array_slice($l, 0, -1) as $r) {
        $out[$r['cause']] = $r['next'];
    }
    return $out;
}

return [
    'arr login-check: a password in the file that works is reported with where it is, and nothing changes' => function () {
        $fx = arrAuthFixture('sonarr');
        try {
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            $st = $l[count($l) - 1];
            check($code === 0 && $st['login'] === 'works' && $st['next'] === 'none' && $st['password_file'] === '~/.media-stack-credentials.txt'
                && arrCauses($l) === ['none' => 'none'], 'works: ' . $out);
            check(arrState($fx)['logins'] === 1 && !arrCalls($fx, 'PUT', 'config/host/1'), 'one login, no change');
        } finally {
            arrDone($fx);
        }
    },
    'arr login-check: each cause is named with its next step (password, placeholder, missing line, auth off, bind, UrlBase)' => function () {
        $fx = arrAuthFixture('sonarr', [], 'Wrong-Value-0009');
        try {
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            check($code === 0 && arrCauses($l) === ['password-rejected' => 'set-login'] && $l[count($l) - 1]['next'] === 'set-login', 'rejected: ' . $out);
            check(strpos($out, 'Wrong-Value-0009') === false, 'the file password was printed');
        } finally {
            arrDone($fx);
        }
        $fx = arrAuthFixture('radarr', [], '[existing Radarr password preserved; not known to installer]');
        try {
            list(, $l, $out) = arrRun($fx, ['login-check']);
            check(arrCauses($l) === ['credentials-placeholder' => 'set-login'] && arrState($fx)['logins'] === 0, 'placeholder: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrAuthFixture('prowlarr', [], null);
        try {
            $host = arrState($fx)['host'];
            arrSet($fx, ['host' => array_merge($host, ['authenticationRequired' => 'disabledForLocalAddresses', 'bindAddress' => '*', 'urlBase' => ''])]);
            list(, $l, $out) = arrRun($fx, ['login-check']);
            check(arrCauses($l) === ['bind-not-loopback' => 'repair', 'urlbase-not-proxy' => 'repair',
                'auth-not-configured' => 'bash ~/install-media-stack.sh --secure-app=prowlarr', 'credentials-missing' => 'set-login'], 'pmss causes: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrAuthFixture('lidarr', ['where' => 'servarr'], null); // not installer-managed: --secure-app does not exist for it
        try {
            $host = arrState($fx)['host'];
            arrSet($fx, ['host' => array_merge($host, ['authenticationMethod' => 'none'])]);
            list(, $l, $out) = arrRun($fx, ['login-check']);
            check(arrCauses($l) === ['auth-not-configured' => 'set-login', 'credentials-missing' => 'set-login'], 'lidarr: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr login-check: an invalid config.xml, a stopped app and a failed installer seed are named, never thrown' => function () {
        $fx = arrAuthFixture('sonarr', ['config' => '']);
        try {
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            check($code === 0 && arrCauses($l) === ['config-invalid' => 'repair'], 'invalid: ' . $out);
        } finally {
            arrDone($fx);
        }
        $fx = arrAuthFixture('sonarr', ['start' => false]);
        try {
            file_put_contents(dirname($fx['config']) . '/sonarr-auth-seed.log', "[Fatal] Non-recoverable failure, waiting for user intervention...\n");
            list($code, $l, $out) = arrRun($fx, ['login-check']);
            $c = arrCauses($l);
            check($code === 0 && isset($c['not-answering']) && strpos($c['not-answering'], 'restart') !== false
                && $c['seed-failed'] === 'bash ~/install-media-stack.sh --secure-app=sonarr', 'stopped: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr set-login: file first (0600, other lines kept), forms login set through the API, checked by 401 and a real login, never printed' => function () {
        $fx = arrAuthFixture('sonarr', [], 'Old-Value-0007');
        try {
            arrFakeInstaller($fx);
            list($code, $l, $out) = arrRun($fx, ['set-login']);
            $f = $fx['home'] . '/.media-stack-credentials.txt';
            $vals = MediaStackCredentials::read($fx['home']);
            $new = $vals['SONARR_PASSWORD'];
            check($code === 0 && $l[0]['login'] === 'works' && $l[0]['keyless_api'] === 401 && $l[0]['username'] === 'exampleuser'
                && $l[0]['password_line'] === 'SONARR_PASSWORD' && $l[0]['update_mechanism'] === 'Script' && $l[0]['restart']['answered'] === true, 'set-login: ' . $out);
            check(strpos(file_get_contents($fx['config']), '<UpdateMechanism>Script</UpdateMechanism>') !== false
                && strpos(file_get_contents($fx['config']), 'BuiltIn') === false, 'UpdateMechanism not put back to Script: ' . file_get_contents($fx['config']));
            check(strlen($new) === 24 && preg_match('/^[A-HJ-NP-Za-km-z2-9_-]{24}$/', $new) && strpos($out, $new) === false, 'password shape or leak');
            check(arrState($fx)['userPassword'] === $new && strpos(file_get_contents($f), 'OTHER_URL = https://host.example.com/x/') !== false
                && (fileperms($f) & 0777) === 0600, 'stored');
            $log = (string) file_get_contents($fx['dir'] . '/arr-log/' . gmdate('Ymd') . '.jsonl');
            check(strpos($log, '"verb":"set-login","app":"sonarr","outcome":"set"') !== false && strpos($log, $new) === false, 'log: ' . $log);
            check(arrState($fx)['host']['apiKey'] === ARR_KEY, 'the API key changed');
        } finally {
            arrDone($fx);
        }
    },
    'arr set-login: a refused change restores the file exactly and the app\'s echo of the password is hidden' => function () {
        $fx = arrAuthFixture('radarr', ['failHostPut' => true], 'Old-Value-0008');
        try {
            $f = $fx['home'] . '/.media-stack-credentials.txt';
            $before = file_get_contents($f);
            list($code, $l, $out) = arrRun($fx, ['set-login']);
            $sent = arrState($fx)['lastPut'];
            check($code === 1 && strpos($out, 'nothing changed') !== false && strpos($out, 'REDACTED') !== false, 'refused: ' . $out);
            check(strlen($sent) === 24 && strpos($out, $sent) === false, 'the password the app echoed was printed');
            check(file_get_contents($f) === $before, "file not restored:\n" . file_get_contents($f));
        } finally {
            arrDone($fx);
        }
    },
    'arr set-login: a change the app takes but the login check fails keeps the new line and ends ok:false' => function () {
        $fx = arrAuthFixture('lidarr', ['where' => 'servarr', 'ignoreHostPut' => true], null);
        try {
            list($code, $l, $out) = arrRun($fx, ['set-login']);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 1 && strpos($out, 'the check failed') !== false && strlen($v['LIDARR_PASSWORD']) === 24
                && $v['LIDARR_USERNAME'] === 'exampleuser', 'unverified: ' . $out);
        } finally {
            arrDone($fx);
        }
    },
    'arr set-login: a config.xml that cannot be set back to Script ends ok:false, says the app may still update itself and names the installer rerun' => function () {
        if ((function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) === 0) {
            skip('root writes into a read-only folder; the refusal cannot be made here');
        }
        $fx = arrAuthFixture('sonarr', [], 'Old-Value-0010');
        $dir = dirname($fx['config']);
        try {
            arrFakeInstaller($fx);
            arrSet($fx, ['configPath' => '']); // the fake app keeps its own save out of the read-only folder
            chmod($dir, 0500);
            list($code, $l, $out) = arrRun($fx, ['set-login']);
            chmod($dir, 0700);
            $v = MediaStackCredentials::read($fx['home']);
            check($code === 1 && strpos($out, 'may still update itself') !== false && strpos($out, 'could not be set back to Script') !== false
                && strpos($out, 'install-media-stack.sh --dry-run') !== false && strpos($out, 'SONARR_PASSWORD; login check passed') !== false
                && strlen($v['SONARR_PASSWORD']) === 24 && strpos($out, $v['SONARR_PASSWORD']) === false, 'not restored: ' . $out);
            check(!file_exists($fx['dir'] . '/installer.log'), 'restarted although config.xml was not set back');
        } finally {
            @chmod($dir, 0700);
            arrDone($fx);
        }
    },
];