<?php
/**
 * ArrAuth: the login of a Servarr app through its own API with its own key (tools/arr/README.md "Servarr apps: login
 * and settings"). The key already allows PUT config/host, which the PMSS installer itself uses to seed the login
 * (etc/skel/install-media-stack.sh, servarr_auth_payload_write and servarr_auth_seed), so no privilege is added.
 * - host(): GET config/host whole (raw: it carries the API key and the stored password or its hash; never printed);
 * - putHost(): PUT it back. passwordConfirmation is always the password sent (HostConfigController IsMatchingPassword:
 *   Sonarr sends "********", the others the stored hash, both accepted back unchanged); updateMechanism "script" with no
 *   script path is sent as "builtIn", as the installer does, because the app refuses that pair (UpdateScriptPath rule),
 *   and ArrUpdates then puts config.xml back to Script and restarts, as the installer does after it;
 * - password(): 24 characters of the installer's alphabet (media_stack_password_generate), from random_bytes, in-process;
 * - formsLogin()/keyless(): a POST <UrlBase>/login (AuthenticationController: success redirects to the UrlBase, a
 *   failure to login?...loginFailed=true) and an API call without the key (401/403 when a login is required).
 * Every text a call returns has the password replaced before it is shown (redact).
 */
class ArrAuth
{
    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
    const APPS = ['sonarr', 'radarr', 'lidarr', 'prowlarr', 'whisparr']; // readarr: read verbs only
    /** Methods the installer counts as a login (servarr_config_auth_configured); oidc is the app's own newer one. */
    const METHODS = ['forms', 'basic', 'external', 'oidc'];

    /** The login and settings verbs of the Servarr apps in APPS (README "Servarr apps: login and settings"). */
    public static function verbs(array $app, callable $c, callable $locate, callable $fresh)
    {
        if (!in_array($app['id'], self::APPS, true)) {
            return [];
        }
        return [
            'login-check' => function ($args, $opt) use ($app, $locate) {
                return ArrLoginCheck::run($app, $locate);
            },
            'set-login' => function ($args, $opt) use ($app, $locate, $c, $fresh) {
                return [ArrLoginFix::setLogin($app, $locate, $c(), $fresh)];
            },
            'repair' => function ($args, $opt) use ($app, $locate, $fresh) {
                return [ArrLoginFix::repair($app, $locate, $fresh)];
            },
            'config' => function ($args, $opt) use ($app, $c, $locate, $fresh) {
                return ArrSettings::run($app, $c(), $args, $locate, $fresh);
            },
        ];
    }

    public static function host(ArrClient $c)
    {
        $h = $c->call('GET', 'config/host', [], null, ['raw' => true]);
        if (!is_array($h) || !array_key_exists('authenticationMethod', $h)) {
            throw new CliError('the app answered config/host without its settings', 'run `stats`; this tool reads the app\'s own API only');
        }
        return $h;
    }

    /** PUT config/host/<id>. "script" updates with no script path go as builtIn; ArrUpdates::restore puts config.xml back to Script. */
    public static function putHost(ArrClient $c, array $h, array $secrets = [])
    {
        if (isset($h['updateMechanism']) && strtolower((string) $h['updateMechanism']) === 'script' && empty($h['updateScriptPath'])) {
            $h['updateMechanism'] = 'builtIn';
        }
        $h['passwordConfirmation'] = isset($h['password']) ? $h['password'] : '';
        try {
            $c->call('PUT', 'config/host/' . (isset($h['id']) ? (int) $h['id'] : 1), [], $h, ['raw' => true]);
        } catch (CliError $e) {
            throw new CliError(self::redact($e->getMessage(), $secrets), self::redact($e->hint, $secrets));
        }
    }

    /** True when the host settings ask for a login on every address (installer: a login method AND required = enabled). */
    public static function configured(array $h)
    {
        return in_array(strtolower((string) $h['authenticationMethod']), self::METHODS, true)
            && strtolower((string) (isset($h['authenticationRequired']) ? $h['authenticationRequired'] : '')) === 'enabled';
    }

    /** The address the app listens on, as host config says ('*' when it says nothing). */
    public static function bindOf(array $h)
    {
        return isset($h['bindAddress']) && $h['bindAddress'] !== '' ? (string) $h['bindAddress'] : '*';
    }

    public static function password()
    {
        $out = '';
        $n = strlen(self::ALPHABET); // 58: bytes >= 232 are thrown away so every character is equally likely
        while (strlen($out) < 24) {
            foreach (str_split(random_bytes(32)) as $b) {
                if (ord($b) < 232 && strlen($out) < 24) {
                    $out .= self::ALPHABET[ord($b) % $n];
                }
            }
        }
        return $out;
    }

    /** 'works', 'refused' or an error text (the app did not answer as a login page does). One attempt. */
    public static function formsLogin(array $w, $user, $password, $timeout)
    {
        $r = Http::request('POST', 'http://127.0.0.1:' . $w['port'] . $w['urlBase'] . '/login', ['timeout' => $timeout,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query(['username' => $user, 'password' => $password, 'rememberMe' => 'off'], '', '&', PHP_QUERY_RFC3986)]);
        $to = isset($r['headers']['location'][0]) ? $r['headers']['location'][0] : '';
        if ($r['code'] === 302 && $to !== '') {
            return strpos($to, 'loginFailed=true') !== false ? 'refused' : 'works';
        }
        return $r['code'] === 401 ? 'refused' : 'no login answer (HTTP ' . $r['code'] . ($r['error'] !== '' ? ', ' . self::redact($r['error'], [$password]) : '') . ')';
    }

    /** HTTP code of GET system/status without the key: 401/403 when the app requires a login. */
    public static function keyless(array $w, $api, $timeout)
    {
        return Http::request('GET', 'http://127.0.0.1:' . $w['port'] . $w['urlBase'] . "/api/$api/system/status",
            ['timeout' => $timeout, 'headers' => ['Accept: application/json']])['code'];
    }

    /** This account's name (the installer's $USERNAME, whoami). */
    public static function account()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        return is_array($pw) && isset($pw['name']) ? (string) $pw['name'] : (string) get_current_user();
    }

    /** The installer's proxy path for an app: /public-<account>/<app> (servarr_config_xml_converge). */
    public static function proxyPath($app)
    {
        return '/public-' . self::account() . '/' . $app;
    }

    public static function redact($s, array $secrets)
    {
        foreach ($secrets as $x) {
            if (is_string($x) && strlen($x) >= 4) {
                $s = str_replace($x, 'REDACTED', (string) $s);
            }
        }
        return (string) $s;
    }

    /** One line in local/logs/arr/YYYYMMDD.jsonl: verb, app, outcome; never a value. A log that cannot be written never fails the verb. */
    public static function log($verb, $app, $outcome, array $more = [])
    {
        $dir = getenv('PIKKUMIES_ARR_LOG_DIR') ?: Config::localDir() . '/logs/arr'; // the variable is a test seam
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $old = umask(0077);
        @file_put_contents("$dir/" . gmdate('Ymd') . '.jsonl', json_encode(['ts' => time(), 'verb' => $verb, 'app' => $app,
            'outcome' => $outcome] + $more, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        umask($old);
    }
}
