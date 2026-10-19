<?php
/**
 * AutobrrLogin: `login-check` and `set-login` of autobrr's web login (tools/arr/README.md "Login of the other apps",
 * ADR-0078 amendment 2026-10-19). autobrr has no API for its own password that the API key may call: PATCH
 * auth/user/{username} sits behind a login session, not the key (internal/http/auth.go:66-72, upstream c6b78bdd). Its
 * own tool does it: `autobrrctl --config <dir> change-password <user>` reads the new password from stdin when stdin is
 * not a terminal (cmd/autobrrctl/main.go:173-235, readPassword :401-430; exit 0 on success, log.Fatalf exit 1 on any
 * error), the same tool and the same stdin form the PMSS installer uses for create-user (install-media-stack.sh:1942).
 * The password goes to ~/.media-stack-credentials.txt first (AUTOBRR_USERNAME, AUTOBRR_PASSWORD: :1700-1701), then to
 * autobrrctl's stdin, never argv or the environment; a failed run puts the file back. Then a real login: POST
 * auth/login {username, password}, 204 on success, 403 on bad credentials (auth.go:75-111). GET auth/onboard answers
 * 200 only while no user exists (auth.go:137-168). The installer's --secure-app is never used on an existing autobrr: its
 * create-user fails there and it writes the placeholder over the file (install-media-stack.sh:1942-1950, :2306-2323).
 */
class AutobrrLogin
{
    const RUN = 'run `login-check` and do its next step';
    /** cause => next step (README.md "Why an autobrr login fails", checked by the contract test). */
    const CAUSES = ['not-installed' => 'installer', 'config-invalid' => 'installer', 'not-answering' => 'restart',
        'no-user' => 'secure-app', 'credentials-missing' => 'set-login', 'credentials-placeholder' => 'set-login',
        'password-rejected' => 'set-login', 'ctl-missing' => 'autobrrctl'];

    public static function check(callable $locate, $timeout)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        try {
            $w = $locate('key');
        } catch (CliError $e) {
            $m = $e->getMessage();
            $c = strpos($m, 'not installed') !== false ? 'not-installed' : (strpos($m, 'not usable') !== false ? 'config-invalid' : 'not-answering');
            return ArrLoginCheck::out('autobrr', [self::cause($c, $m, $e->hint)], $file);
        }
        $onboard = self::request($w, 'GET', 'auth/onboard', null, $timeout);
        if ($onboard['code'] === 0) {
            return ArrLoginCheck::out('autobrr', [self::cause('not-answering', 'autobrr does not answer: ' . $onboard['error'], 'restart')], $file);
        }
        if ($onboard['code'] === 200) {
            $next = $w['source'] === 'pmss' ? 'bash ~/install-media-stack.sh --secure-app=autobrr'
                : 'the user creates the login in autobrr\'s web page now: until then anyone on this machine can';
            return ArrLoginCheck::out('autobrr', [['cause' => 'no-user', 'next' => $next,
                'detail' => 'autobrr has no user yet, so its first-user page is open to anyone on this machine']], $file);
        }
        list($user, $known, $state) = self::credentials($w['home']);
        $found = [];
        if ($state === 'placeholder') {
            $found[] = self::cause('credentials-placeholder', "AUTOBRR_PASSWORD in $file is the installer's placeholder: the password is not known");
        } elseif ($state !== 'present') {
            $found[] = self::cause('credentials-missing', "$file has no AUTOBRR_PASSWORD line" . ($state === 'no-file' ? ' (no such file)' : ''));
        } else {
            $login = self::login($w, $user, $known, $timeout);
            if ($login === 'works') {
                return ArrLoginCheck::out('autobrr', [], $file, "the password in $file (AUTOBRR_PASSWORD) works for user $user");
            }
            $found[] = self::cause('password-rejected', $login === 'refused' ? "autobrr refused user $user with the password in $file" : "the login could not be tried: $login");
        }
        if (self::ctl($w) === '') {
            $found[] = self::cause('ctl-missing', 'set-login needs autobrr\'s own autobrrctl, and it was not found next to autobrr or in PATH');
            $found[count($found) - 1]['next'] = 'the user runs `autobrrctl --config <autobrr config folder> change-password ' . $user . '` in their own terminal';
        }
        return ArrLoginCheck::out('autobrr', $found, $file);
    }

    public static function set(callable $locate, $timeout)
    {
        $w = $locate('key');
        $file = '~/' . MediaStackCredentials::FILE;
        $ctl = self::ctl($w);
        if ($ctl === '') {
            throw new CliError('autobrrctl was not found next to autobrr (~/.bin/autobrr/autobrrctl) or in PATH; nothing changed', self::RUN);
        }
        list($user) = self::credentials($w['home']);
        $new = ArrAuth::password();
        $backup = MediaStackCredentials::set($w['home'], ['AUTOBRR_USERNAME' => $user, 'AUTOBRR_PASSWORD' => $new]);
        list($code, , $err) = ArrShell::run([$ctl, '--config', dirname($w['config']), 'change-password', $user], [], null, $new . "\n");
        if ($code !== 0) {
            MediaStackCredentials::set($w['home'], $backup);
            ArrAuth::log('set-login', 'autobrr', 'refused');
            throw new CliError("autobrrctl did not change the login (exit $code), so $file is as it was: "
                . ArrRecords::cut(ArrAuth::redact(trim($err), [$new]), 300), self::RUN);
        }
        $login = self::login($w, $user, $new, $timeout);
        ArrAuth::log('set-login', 'autobrr', $login === 'works' ? 'set' : 'set-unverified');
        if ($login !== 'works') {
            throw new CliError("autobrr took the new login and $file holds it (AUTOBRR_PASSWORD), but the login check failed: $login", self::RUN);
        }
        return ['app' => 'autobrr', 'username' => $user, 'password_file' => $file, 'password_line' => 'AUTOBRR_PASSWORD', 'login' => 'works',
            'tell_user' => "$file holds the new autobrr login of user $user, readable only by you"];
    }

    /** autobrrctl next to autobrr (the installer's ~/.bin/autobrr, a regular file this account owns), else in PATH; '' when none. Docker: none. */
    private static function ctl(array $w)
    {
        if ($w['source'] === 'docker') {
            return '';
        }
        $own = $w['home'] . '/.bin/autobrr/autobrrctl';
        clearstatcache(true, $own);
        if (is_file($own) && !is_link($own) && is_executable($own) && (int) @fileowner($own) === (int) $w['uid']) {
            return $own;
        }
        return ArrShell::has('autobrrctl') ? 'autobrrctl' : '';
    }

    /** 'works', 'refused' or why it could not be tried. One attempt. */
    private static function login(array $w, $user, $secret, $timeout)
    {
        $r = self::request($w, 'POST', 'auth/login', json_encode(['username' => $user, 'password' => $secret]), $timeout);
        if ($r['code'] === 204) {
            return 'works';
        }
        return $r['code'] === 403 || $r['code'] === 401 ? 'refused'
            : 'no login answer (HTTP ' . $r['code'] . ($r['error'] !== '' ? ', ' . ArrAuth::redact($r['error'], [$secret]) : '') . ')';
    }

    private static function request(array $w, $method, $route, $body, $timeout)
    {
        return Http::request($method, 'http://127.0.0.1:' . $w['port'] . $w['apiBase'] . $route, ['timeout' => $timeout,
            'headers' => ['Content-Type: application/json', 'Accept: application/json'], 'body' => $body]);
    }

    /** [user, password, state]: the user is AUTOBRR_USERNAME, else the account name (the installer's $USERNAME, :2417). */
    private static function credentials($home)
    {
        $v = MediaStackCredentials::read($home);
        $user = MediaStackCredentials::value($v, 'AUTOBRR_USERNAME');
        return [$user !== null ? $user : ArrAuth::account(), MediaStackCredentials::value($v, 'AUTOBRR_PASSWORD'),
            MediaStackCredentials::state($v, 'AUTOBRR_PASSWORD')];
    }

    private static function cause($cause, $detail, $hint = null)
    {
        return ArrLoginCheck::named(self::CAUSES, $cause, $detail, $hint);
    }
}
