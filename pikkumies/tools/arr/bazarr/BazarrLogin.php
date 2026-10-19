<?php
/**
 * BazarrLogin: `login-check` and `set-login` of Bazarr's web login (tools/arr/README.md "Login of the other apps",
 * ADR-0078 amendment 2026-10-19), through Bazarr's own API with its own key, at upstream bfe4593b (2026-10-11):
 * - GET and POST system/settings carry @authenticate, which accepts the auth.apikey in the X-API-KEY header
 *   (bazarr/api/system/settings.py:24-130, api/utils.py:100-115), so the key a tool already uses can set the login, as
 *   Bazarr's own Settings page does; no privilege is added. POST takes form fields settings-<section>-<key>
 *   (app/config.py save_settings :690); settings-auth-password goes as plain text and Bazarr stores its md5 (:768-770);
 *   auth.type is one of None, basic, form (:186-187); 204 on success, 406 with the message on a validation error.
 * - POST system/account action=login, username, password, no key: 204 on success, 403 refused, 500 when auth.type is
 *   neither form nor basic (api/system/account.py:15-50, utilities/helper.py:17-30).
 * The new one goes to ~/.media-stack-credentials.txt first (BAZARR_USERNAME, BAZARR_PASSWORD: the installer's file and
 * KEY convention, lib/MediaStackCredentials.php; the PMSS installer itself does not manage Bazarr), then to Bazarr;
 * a refused change puts the file back. Bazarr's API always needs the key whatever auth.type says, so the check is the
 * login itself plus auth.type and username read back.
 */
class BazarrLogin
{
    const RUN = 'run `login-check` and do its next step';
    /** cause => next step (README.md "Why a Bazarr login fails", checked by the contract test). */
    const CAUSES = ['not-installed' => 'installer', 'config-invalid' => 'installer', 'not-answering' => 'restart',
        'auth-not-configured' => 'set-login', 'credentials-missing' => 'set-login', 'credentials-placeholder' => 'set-login',
        'password-rejected' => 'set-login'];

    public static function check(callable $locate, callable $c, $timeout)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        try {
            $w = $locate();
            $auth = self::auth($c());
        } catch (CliError $e) {
            $m = $e->getMessage();
            $cause = strpos($m, 'not installed') !== false ? 'not-installed' : (strpos($m, 'empty or not') !== false ? 'config-invalid' : 'not-answering');
            return ArrLoginCheck::out('bazarr', [self::cause($cause, $m, $e->hint)], $file);
        }
        $found = [];
        if (!in_array($auth['type'], ['form', 'basic'], true)) {
            $found[] = self::cause('auth-not-configured', 'Bazarr asks for no login (auth.type ' . ($auth['type'] === '' ? 'none' : $auth['type'])
                . '): its web page is open to anyone on this machine');
        }
        list($user, $known, $state) = self::credentials($w['home'], $auth);
        if ($state === 'placeholder') {
            $found[] = self::cause('credentials-placeholder', "BAZARR_PASSWORD in $file holds a placeholder, not a usable value");
        } elseif ($state !== 'present') {
            $found[] = self::cause('credentials-missing', "$file has no BAZARR_PASSWORD line" . ($state === 'no-file' ? ' (no such file)' : ''));
        } elseif (!$found) {
            $login = self::login($w, $user, $known, $timeout);
            if ($login === 'works') {
                return ArrLoginCheck::out('bazarr', [], $file, "the password in $file (BAZARR_PASSWORD) works for user $user");
            }
            $found[] = self::cause('password-rejected', $login === 'refused' ? "Bazarr refused user $user with the password in $file" : "the login could not be tried: $login");
        }
        return ArrLoginCheck::out('bazarr', $found, $file);
    }

    public static function set(callable $locate, ArrClient $c, $timeout)
    {
        $w = $locate();
        $file = '~/' . MediaStackCredentials::FILE;
        list($user) = self::credentials($w['home'], self::auth($c));
        if (ctype_digit($user)) { // save_settings casts a digits-only value to int (config.py:726-731), which no login then matches
            throw new CliError("the user name $user is digits only, which Bazarr would store as a number; nothing changed", 'set the login in Bazarr\'s own Settings page');
        }
        $new = ArrAuth::password();
        $backup = MediaStackCredentials::set($w['home'], ['BAZARR_USERNAME' => $user, 'BAZARR_PASSWORD' => $new]);
        try {
            $c->call('POST', 'system/settings', [], null, ['text' => true,
                'form' => ['settings-auth-type' => 'form', 'settings-auth-username' => $user, 'settings-auth-password' => $new]]);
        } catch (CliError $e) {
            self::unanswered($w, $user, $new, $backup, $e, $timeout);
        }
        $auth = self::auth($c);
        $login = self::login($w, $user, $new, $timeout);
        $ok = $auth['type'] === 'form' && $auth['username'] === $user && $login === 'works';
        ArrAuth::log('set-login', 'bazarr', $ok ? 'set' : 'set-unverified');
        if (!$ok) {
            throw new CliError("Bazarr took the new login and $file holds it (BAZARR_PASSWORD), but the check failed: auth.type "
                . ($auth['type'] === '' ? 'none' : $auth['type']) . ", login: $login", self::RUN);
        }
        return ['app' => 'bazarr', 'username' => $user, 'password_file' => $file, 'password_line' => 'BAZARR_PASSWORD', 'login' => 'works',
            'tell_user' => "$file holds the new Bazarr login of user $user, readable only by you"];
    }

    /**
     * After a failed POST: an HTTP answer is a refusal (the old line back). No answer (ArrClient's own words) may still
     * have landed, so one login with the new pair first: it works, set-login goes on; refused, the old line back; no
     * answer either, the file keeps both (the new as BAZARR_PASSWORD, the old as BAZARR_PASSWORD_PREVIOUS). Returns
     * only when the new login works.
     */
    private static function unanswered(array $w, $user, $new, array $backup, CliError $e, $timeout)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        $why = ArrAuth::redact($e->getMessage(), [$new]);
        $login = preg_match('/\(HTTP [0-9]+\)/', $why) ? 'refused' : self::login($w, $user, $new, $timeout);
        if ($login === 'works') {
            return;
        }
        if ($login === 'refused') {
            MediaStackCredentials::set($w['home'], $backup);
            ArrAuth::log('set-login', 'bazarr', 'refused');
            throw new CliError("Bazarr did not take the new login, so nothing changed and $file is as it was: $why", ArrAuth::redact($e->hint, [$new]));
        }
        if ($backup['BAZARR_PASSWORD'] !== null) {
            MediaStackCredentials::set($w['home'], ['BAZARR_PASSWORD_PREVIOUS' => $backup['BAZARR_PASSWORD']]);
        }
        ArrAuth::log('set-login', 'bazarr', 'unknown');
        throw new CliError("Bazarr gave no answer, so whether it took the new login is unknown: $file keeps BAZARR_PASSWORD (the new one: try it first)"
            . ($backup['BAZARR_PASSWORD'] !== null ? ' and BAZARR_PASSWORD_PREVIOUS (the old one)' : ''), 'once Bazarr answers, run `login-check`');
    }

    /** auth.type and auth.username from GET system/settings (raw: it also holds the key and the stored hash; never printed). */
    private static function auth(ArrClient $c)
    {
        $s = $c->call('GET', 'system/settings', [], null, ['raw' => true]);
        $a = isset($s['auth']) && is_array($s['auth']) ? $s['auth'] : null;
        if ($a === null) {
            throw new CliError('Bazarr answered system/settings without its auth section', 'run `stats`');
        }
        return ['type' => isset($a['type']) && is_string($a['type']) ? strtolower($a['type']) : '',
            'username' => isset($a['username']) && is_scalar($a['username']) ? (string) $a['username'] : ''];
    }

    /** 'works', 'refused' or why it could not be tried. One attempt, no key (account.py has no @authenticate). */
    private static function login(array $w, $user, $secret, $timeout)
    {
        $r = Http::request('POST', 'http://127.0.0.1:' . $w['port'] . $w['apiBase'] . 'system/account', ['timeout' => $timeout,
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body' => http_build_query(['action' => 'login', 'username' => $user, 'password' => $secret], '', '&', PHP_QUERY_RFC3986)]);
        if ($r['code'] === 204) {
            return 'works';
        }
        return $r['code'] === 403 ? 'refused' : 'no login answer (HTTP ' . $r['code'] . ($r['error'] !== '' ? ', ' . ArrAuth::redact($r['error'], [$secret]) : '') . ')';
    }

    /** [user, password, state]: the user is Bazarr's own auth.username, else BAZARR_USERNAME, else the account name. */
    private static function credentials($home, array $auth)
    {
        $v = MediaStackCredentials::read($home);
        $file = MediaStackCredentials::value($v, 'BAZARR_USERNAME');
        $user = $auth['username'] !== '' ? $auth['username'] : ($file !== null ? $file : ArrAuth::account());
        return [$user, MediaStackCredentials::value($v, 'BAZARR_PASSWORD'), MediaStackCredentials::state($v, 'BAZARR_PASSWORD')];
    }

    private static function cause($cause, $detail, $hint = null)
    {
        return ArrLoginCheck::named(self::CAUSES, $cause, $detail, $hint);
    }
}
