<?php
/**
 * ArrLoginCheck: `login-check`, why a Servarr app does not let the user in; it changes nothing. First one forms login
 * with the password in ~/.media-stack-credentials.txt: when that works, it says where the password is and stops. Else
 * it names every cause it finds (CAUSES, mirrored by tools/arr/README.md "Why a login fails") and `next`, the one step
 * to take. Passwords never reach the output; the seed log is matched, never printed.
 */
class ArrLoginCheck
{
    /** cause => next step ('set-login', 'repair', 'restart', 'secure-app', 'installer': hint text of the error). */
    const CAUSES = [
        'not-installed' => 'installer', 'config-invalid' => 'repair', 'not-answering' => 'restart', 'seed-failed' => 'installer',
        'auth-not-configured' => 'secure-app', 'username-missing' => 'set-login', 'bind-not-loopback' => 'repair',
        'urlbase-not-proxy' => 'repair', 'credentials-missing' => 'set-login', 'credentials-placeholder' => 'set-login',
        'password-rejected' => 'set-login',
    ];
    /** Lines the app writes to <app>-auth-seed.log when the installer's first start failed (servarr_seed_log_fatal). */
    const SEED_SIGNS = ['Non-recoverable failure'];

    public static function run(array $app, callable $locate)
    {
        $id = $app['id'];
        $file = '~/' . MediaStackCredentials::FILE;
        try {
            $w = $locate(true);
        } catch (CliError $e) {
            $invalid = strpos($e->getMessage(), 'empty or invalid') !== false; // ArrLocate's own words for a config.xml without </Config>
            return self::out($id, [self::cause($invalid ? 'config-invalid' : 'not-installed', $e->getMessage(), $e->hint)], $file);
        }
        $c = new ArrClient($w, $locate, $app['api'], Arr::timeout()); // built after the lenient look: a missing key is a cause below
        $seed = self::seedSign($w, $id);
        try {
            $h = ArrAuth::host($c);
        } catch (CliError $e) {
            $found = [self::cause('not-answering', $e->getMessage(), $e->hint)];
            if ($seed !== '') {
                $found[] = self::cause('seed-failed', "the installer's first start of the app failed: $seed", self::installer($w, $id));
            }
            return self::out($id, $found, $file);
        }
        $vals = MediaStackCredentials::read($w['home']);
        $key = MediaStackCredentials::appKey($id) . '_PASSWORD';
        $pw = MediaStackCredentials::value($vals, $key);
        $user = (string) $h['username'];
        $login = $pw !== null && $user !== '' ? ArrAuth::formsLogin($w, $user, $pw, Arr::timeout()) : '';
        $found = [];
        if (ArrAuth::bindOf($h) !== '127.0.0.1') {
            $found[] = self::cause('bind-not-loopback', 'the app listens on ' . ArrAuth::bindOf($h) . ', not only on 127.0.0.1');
        }
        if ($w['source'] === 'pmss' && rtrim((string) $h['urlBase'], '/') !== ArrAuth::proxyPath($id)) {
            $found[] = self::cause('urlbase-not-proxy', 'UrlBase is "' . $h['urlBase'] . '", the web address needs ' . ArrAuth::proxyPath($id));
        }
        if ($login === 'works') {
            return self::out($id, $found, $file, "the password in $file ($key) works for user $user on the app's own port"
                . ($found ? '' : '; if the web address still refuses it, the proxy in front of the app is the suspect: report it, never weaken the login'));
        }
        if (!ArrAuth::configured($h)) {
            $found[] = self::cause('auth-not-configured', 'the app asks for no login on every address (' . $h['authenticationMethod']
                . ', ' . (isset($h['authenticationRequired']) ? $h['authenticationRequired'] : '?') . ')'
                . ($seed !== '' ? "; the installer's first start failed: $seed" : ''));
        } elseif ($user === '') {
            $found[] = self::cause('username-missing', 'the app has a login method but no user name');
        }
        $state = MediaStackCredentials::state($vals, $key);
        if ($state === 'no-file' || $state === 'missing') {
            $found[] = self::cause('credentials-missing', "$file has no $key line" . ($state === 'no-file' ? ' (no such file)' : ''));
        } elseif ($state === 'placeholder') {
            $found[] = self::cause('credentials-placeholder', "$key in $file is the installer's placeholder: the password is not known");
        } elseif ($login !== '' && ArrAuth::configured($h)) {
            $found[] = self::cause('password-rejected', $login === 'refused' ? "the app refused user $user with the password in $file"
                : "the login could not be tried: $login");
        }
        $found = array_map(function ($f) use ($w, $id) {
            return $f['next'] === 'secure-app' ? array_merge($f, ['next' => self::secureApp($w, $id)]) : $f;
        }, $found);
        return self::out($id, $found, $file);
    }

    private static function cause($cause, $detail, $hint = null)
    {
        $next = self::CAUSES[$cause];
        return ['cause' => $cause, 'detail' => $detail, 'next' => in_array($next, ['installer', 'restart'], true) && $hint ? $hint : $next];
    }

    /** --secure-app only for the apps the installer secures (media_stack_secure_app_id_valid); set-login otherwise. */
    private static function secureApp(array $w, $id)
    {
        return $w['source'] === 'pmss' && in_array($id, ['sonarr', 'radarr', 'prowlarr'], true)
            ? "bash ~/install-media-stack.sh --secure-app=$id" : 'set-login';
    }

    private static function installer(array $w, $id)
    {
        return $w['source'] === 'pmss' ? "bash ~/install-media-stack.sh --secure-app=$id" : 'start it the way the provider documents (or `restart`)';
    }

    /** The first known failure line in the last 64 KiB of ~/.config/<app>/<app>-auth-seed.log, or ''. */
    private static function seedSign(array $w, $id)
    {
        $log = dirname($w['config']) . "/$id-auth-seed.log";
        $size = is_file($log) && !is_link($log) ? (int) filesize($log) : 0;
        $tail = $size > 0 ? (string) @file_get_contents($log, false, null, max(0, $size - 65536)) : '';
        foreach (self::SEED_SIGNS as $sign) {
            if (strpos($tail, $sign) !== false) {
                return "\"$sign\" in ~/" . ltrim(str_replace($w['home'], '', $log), '/');
            }
        }
        return '';
    }

    /** One record per cause (or one `login: works` record); status `next` is the first cause's step. */
    private static function out($id, array $found, $file, $works = '')
    {
        $records = $found;
        if ($works !== '') {
            array_unshift($records, ['cause' => 'none', 'detail' => $works, 'next' => $found ? 'repair' : 'none']);
        }
        return ['records' => $records, 'status' => ['app' => $id, 'login' => $works !== '' ? 'works' : 'fails',
            'next' => $records ? $records[0]['next'] : 'set-login', 'password_file' => $file]];
    }
}
