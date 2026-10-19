<?php
/**
 * ArrLoginFix: the two changing login verbs of a Servarr app (tools/arr/README.md "Servarr apps: login and settings").
 * - set-login: a new random password for the app's existing user (the account name when it has none). It goes to
 *   ~/.media-stack-credentials.txt first (the old lines kept in memory), then to the app (PUT config/host: forms,
 *   enabled); a refused PUT puts the old lines back. Then checked by a real login: the API without the key must answer
 *   401/403 and a forms login with the new pair must work. The password is never printed or put on a command line.
 * - repair: the PMSS-safe host settings through the same API (listen on 127.0.0.1, forms login required, the
 *   installer's proxy UrlBase on PMSS), then the existing restart when the address or UrlBase changed. An empty or
 *   invalid config.xml is copied aside and the hint names the installer rerun; this tool never rewrites config.xml (only
 *   the UpdateMechanism tag, ArrUpdates) and never starts an app from a template of its own.
 */
class ArrLoginFix
{
    public static function setLogin(array $app, callable $locate, ArrClient $c, callable $fresh)
    {
        $id = $app['id'];
        $w = $locate();
        $h = ArrAuth::host($c);
        $user = (string) $h['username'] !== '' ? (string) $h['username'] : ArrAuth::account();
        $pw = ArrAuth::password();
        $k = MediaStackCredentials::appKey($id);
        $file = '~/' . MediaStackCredentials::FILE;
        $backup = MediaStackCredentials::set($w['home'], ["{$k}_USERNAME" => $user, "{$k}_PASSWORD" => $pw]);
        $h = array_merge($h, ['authenticationMethod' => 'forms', 'authenticationRequired' => 'enabled', 'username' => $user, 'password' => $pw]);
        try {
            ArrAuth::putHost($c, $h, [$pw]);
        } catch (CliError $e) {
            MediaStackCredentials::set($w['home'], $backup);
            ArrAuth::log('set-login', $id, 'refused');
            throw new CliError("{$app['name']} refused the new login, so nothing changed and $file is as it was: " . $e->getMessage(), $e->hint);
        }
        usleep(500000); // the installer waits a moment too before it checks (servarr_auth_seed)
        $keyless = ArrAuth::keyless($w, $app['api'], Arr::timeout());
        $login = ArrAuth::formsLogin($w, $user, $pw, Arr::timeout());
        $ok = in_array($keyless, [401, 403], true) && $login === 'works';
        ArrAuth::log('set-login', $id, $ok ? 'set' : 'set-unverified');
        $updates = ArrUpdates::restore($app, $locate, $fresh, "{$app['name']} took the new login and $file holds it ({$k}_PASSWORD; login check "
            . ($ok ? 'passed' : 'failed') . ')'); // after the check, as the installer converges after its own
        if (!$ok) {
            throw new CliError("{$app['name']} took the new login and $file holds it ({$k}_PASSWORD), but the check failed: "
                . "API without the key answered HTTP $keyless (401/403 expected), forms login: $login",
                'run `login-check` and do its next step; the new password stays in the file, readable only by this account');
        }
        $r = ['app' => $id, 'username' => $user, 'password_file' => $file, 'password_line' => "{$k}_PASSWORD", 'keyless_api' => $keyless,
            'login' => 'works', 'tell_user' => "the new password for user $user is in $file, readable only by you"];
        return $r + $updates;
    }

    public static function repair(array $app, callable $locate, callable $fresh)
    {
        $id = $app['id'];
        try {
            $w = $locate(true);
        } catch (CliError $e) {
            if (strpos($e->getMessage(), 'empty or invalid') === false) {
                throw $e;
            }
            throw new CliError($e->getMessage() . '; copied to ' . self::backup($app) . ', nothing else changed', $e->hint);
        }
        $c = new ArrClient($w, $locate, $app['api'], Arr::timeout()); // built after the lenient look, so a bad config.xml is reported above
        $h = ArrAuth::host($c);
        $want = ['bindAddress' => '127.0.0.1'];
        if ($w['source'] === 'pmss') {
            $want['urlBase'] = ArrAuth::proxyPath($id);
        }
        if (!ArrAuth::configured($h) || strtolower((string) $h['authenticationMethod']) !== 'forms') {
            if ((string) $h['username'] === '' || (string) $h['password'] === '') {
                throw new CliError("{$app['name']} has no login user yet, so a login cannot be required; nothing changed",
                    'run `set-login`: it makes the user and requires the login');
            }
            $want += ['authenticationMethod' => 'forms', 'authenticationRequired' => 'enabled'];
        }
        $changed = [];
        foreach ($want as $f => $v) {
            if ((string) (isset($h[$f]) ? $h[$f] : '') !== $v) {
                $changed[] = $f;
                $h[$f] = $v;
            }
        }
        if (!$changed) {
            return ['app' => $id, 'changed' => [], 'note' => 'nothing to repair: listen address, login and UrlBase are already as they should be'];
        }
        ArrAuth::putHost($c, $h);
        ArrAuth::log('repair', $id, 'set', ['fields' => $changed]);
        $r = ['app' => $id, 'changed' => $changed] + ArrUpdates::restore($app, $locate, $fresh, 'repair changed ' . implode(', ', $changed)); // restarts when it wrote config.xml
        if (!isset($r['restart']) && array_intersect($changed, ['bindAddress', 'urlBase'])) { // the app reads these on start
            try {
                $r['restart'] = ArrRestart::run($app, $locate(true), $fresh);
            } catch (CliError $e) {
                $r['restart'] = ['error' => $e->getMessage(), 'hint' => $e->hint];
            }
        }
        return $r;
    }

    /** Copies the bad config.xml to config.xml.pikkumies-<time> (0600) and returns that path with ~ for the home. */
    private static function backup(array $app)
    {
        list($home) = Arr::account();
        $family = Host::family(getenv('PIKKUMIES_PMSS_DIR') ?: '/etc/seedbox/config');
        foreach (ArrLocate::candidates($app, $home, $family) as $path) {
            if (is_file($path) && !is_link($path)) {
                $to = "$path.pikkumies-" . gmdate('YmdHis');
                if (!@copy($path, $to) || !@chmod($to, 0600)) {
                    throw new CliError("cannot copy $path aside", 'check that its folder is writable; nothing changed');
                }
                ArrAuth::log('repair', $app['id'], 'config-copied');
                return str_replace($home, '~', $to);
            }
        }
        throw new CliError("{$app['name']}'s config.xml could not be found again", 'run `login-check`');
    }
}
