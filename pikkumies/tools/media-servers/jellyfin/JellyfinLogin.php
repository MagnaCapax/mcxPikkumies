<?php
/**
 * JellyfinLogin: `login-check` and `set-login` of the user's own Jellyfin (README.md "Login and settings", ADR-0078
 * amendment 2026-10-19). The login lives in ~/.media-stack-credentials.txt as JELLYFIN_USERNAME and JELLYFIN_PASSWORD,
 * the names the PMSS installer writes (etc/skel/install-media-stack.sh:1704-1705, media_stack_credentials_app_write
 * :2213-2228), through lib/MediaStackCredentials.php. Jellyfin calls, at upstream tag v12.2 (the same routes in v10.10.7
 * and v10.11.0; OpenAPI 12.2.0, sha256 bd31bb19...):
 * - POST /Users/AuthenticateByName {Username, Pw}: Jellyfin.Api/Controllers/UserController.cs:209-212;
 * - POST /Users/Password?userId=<own id> {CurrentPw, NewPw}: UserController.cs:270-319. With userId set to the session's
 *   own user Jellyfin checks CurrentPw even for an administrator (:297-306), answers 204, and revokes every other token of
 *   that user (:313-315): the user's other sessions sign out. 403 when the current one is wrong.
 * - GET /System/Info/Public: StartupWizardCompleted false means no administrator exists yet.
 * One login attempt per call, as JellyfinAuth: a refused one gets the pw_rejected mark and is not tried again. Jellyfin's
 * own Forgot Password (DefaultPasswordResetProvider) is not used by the tool: it is the user's step (skill.md).
 */
class JellyfinLogin
{
    /** What the changing verbs may send on top of Mediabrowser::ALLOW; /System/Configuration/<name> (network) stays refused. */
    const ALLOW = ['GET' => ['#^/System/Configuration$#'], 'POST' => ['#^/Users/Password$#', '#^/System/Configuration$#']];
    const FORGOT = 'Jellyfin asks for the current one before a change, so first the user\'s own step (skill.md "Jellyfin login not known"), then set-login';
    /** cause => next step (README.md "Why a Jellyfin login fails", checked by the contract test). */
    const CAUSES = ['not-installed' => 'installer', 'not-answering' => 'restart', 'startup-not-complete' => 'secure-app',
        'credentials-missing' => self::FORGOT, 'credentials-placeholder' => self::FORGOT, 'password-rejected' => self::FORGOT];

    public static function allow()
    {
        return array_merge_recursive(Mediabrowser::ALLOW, self::ALLOW);
    }

    /** $connect: fn() => [Mediabrowser, found, family]. Changes nothing but the tool's own stored session. */
    public static function check(callable $connect, MediaSecret $secret, $home)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        try {
            list($mb, , $family) = $connect();
            $mb->http->reachable();
        } catch (CliError $e) {
            $c = strpos($e->getMessage(), 'was not found') !== false ? 'not-installed' : 'not-answering';
            return ArrLoginCheck::out('jellyfin', [self::cause($c, $e->getMessage(), $e->hint)], $file);
        }
        $info = $mb->publicInfo();
        if ($info === null) {
            return ArrLoginCheck::out('jellyfin', [self::cause('not-answering', 'Jellyfin does not answer its public status (stopped, starting or busy)', 'restart')], $file);
        }
        if (isset($info['StartupWizardCompleted']) && $info['StartupWizardCompleted'] === false) {
            $next = $family === 'pmss' ? 'bash ~/install-media-stack.sh --secure-app=jellyfin'
                : 'the user finishes the setup wizard in Jellyfin\'s web page now: until then anyone on this machine can make the administrator';
            return ArrLoginCheck::out('jellyfin', [['cause' => 'startup-not-complete', 'next' => $next,
                'detail' => 'Jellyfin\'s setup wizard was never finished: there is no administrator yet']], $file);
        }
        list($user, $known, $state) = self::credentials($home);
        if ($state !== 'present') {
            $c = $state === 'placeholder' ? 'credentials-placeholder' : 'credentials-missing';
            return ArrLoginCheck::out('jellyfin', [self::cause($c, $state === 'placeholder' ? "the line in $file is the installer's placeholder: it kept a Jellyfin login it did not know"
                : "$file has no JELLYFIN_USERNAME and JELLYFIN_PASSWORD lines" . ($state === 'no-file' ? ' (no such file)' : ''))], $file);
        }
        if (!empty($secret->load()['pw_rejected'])) {
            return ArrLoginCheck::out('jellyfin', [self::cause('password-rejected', "Jellyfin refused the password in $file before; not tried again (repeated failures can lock the account)")], $file);
        }
        try {
            $a = $mb->authenticate($user, $known);
        } catch (MediaAuthRejected $e) {
            $secret->update(['pw_rejected' => true]);
            $prev = isset(MediaStackCredentials::read($home)['JELLYFIN_PASSWORD_PREVIOUS']) ? '; the file also keeps JELLYFIN_PASSWORD_PREVIOUS from an unanswered set-login: the user tries that one' : '';
            return ArrLoginCheck::out('jellyfin', [self::cause('password-rejected', "Jellyfin refused user $user with the password in $file$prev")], $file);
        } catch (CliError $e) {
            return ArrLoginCheck::out('jellyfin', [self::cause('not-answering', 'the login could not be tried: ' . $e->getMessage(), 'restart')], $file);
        }
        $secret->update(['session' => $a['token'], 'user_id' => $a['user_id']]); // the same device id: Jellyfin replaced the old token
        return ArrLoginCheck::out('jellyfin', [], $file, "the password in $file (JELLYFIN_PASSWORD) works for user $user"
            . '; if the web address still refuses it, the proxy in front of Jellyfin is the suspect: report it, never weaken the login');
    }

    /** set-login makes a new random one, writes the file first, then Jellyfin with the current one; a refused change puts the file back. */
    public static function set(Mediabrowser $mb, MediaSecret $secret, $home)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        $mb->http->reachable();
        list($user, $cur, $state) = self::credentials($home);
        $again = 'run `login-check` and do its next step';
        if ($state !== 'present') {
            throw new CliError("set-login needs the current Jellyfin password, and $file has none usable ($state); nothing changed", $again);
        }
        if (!empty($secret->load()['pw_rejected'])) {
            throw new CliError("Jellyfin refused the password in $file before; not tried again, nothing changed", $again);
        }
        try {
            $a = $mb->authenticate($user, $cur);
        } catch (MediaAuthRejected $e) {
            $secret->update(['pw_rejected' => true]);
            throw new CliError("Jellyfin refused user $user with the password in $file; marked, not tried again, nothing changed", $again);
        }
        $mb->useToken($a['token'], function () {
        });
        $new = ArrAuth::password();
        $mb->http->scrub($new);
        $backup = MediaStackCredentials::set($home, ['JELLYFIN_PASSWORD' => $new]);
        self::change($mb, $a['user_id'], $user, $cur, $new, $home, $backup);
        try {
            $b = $mb->authenticate($user, $new); // the real login: the new pair must work
            $secret->update(['session' => $b['token'], 'user_id' => $b['user_id'], 'pw_rejected' => null]);
            if (isset(MediaStackCredentials::read($home)['JELLYFIN_PASSWORD_PREVIOUS'])) { // left by an unanswered run; the new pair now works
                MediaStackCredentials::set($home, ['JELLYFIN_PASSWORD_PREVIOUS' => null]);
            }
        } catch (CliError $e) {
            $secret->update(['session' => null]);
            ArrAuth::log('set-login', 'jellyfin', 'set-unverified');
            throw new CliError("Jellyfin took the new password and $file holds it (JELLYFIN_PASSWORD), but the login check failed: " . $e->getMessage(), $again);
        }
        ArrAuth::log('set-login', 'jellyfin', 'set');
        return ['app' => 'jellyfin', 'username' => $user, 'password_file' => $file, 'password_line' => 'JELLYFIN_PASSWORD', 'login' => 'works',
            'other_sessions' => 'signed out by Jellyfin (its own rule on a password change)',
            'tell_user' => "$file holds the new Jellyfin login of user $user, readable only by you"];
    }

    /**
     * Sends the change; returns when Jellyfin took it, else throws with the file settled. A refusal puts the old line back.
     * No answer at all (MediaNoAnswer) never loses the new one: one login with the new pair, then with the old one; the
     * new works: done; only the old works: the old line back; neither: the file keeps both, the new as JELLYFIN_PASSWORD
     * and the old as JELLYFIN_PASSWORD_PREVIOUS, and the error says which to try first. These logins mark nothing.
     */
    private static function change(Mediabrowser $mb, $userId, $user, $cur, $new, $home, array $backup)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        $again = 'run `login-check` and do its next step';
        try {
            $mb->post('/Users/Password', ['userId' => $userId], json_encode(['CurrentPw' => $cur, 'NewPw' => $new]));
            return;
        } catch (MediaNoAnswer $e) {
            if (self::tries($mb, $user, $new) === 'works') {
                return;
            }
            if (self::tries($mb, $user, $cur) === 'works') {
                MediaStackCredentials::set($home, $backup);
                ArrAuth::log('set-login', 'jellyfin', 'refused');
                throw new CliError("Jellyfin gave no answer and still takes the old password, so $file is as it was: " . $e->getMessage(), $again);
            }
            MediaStackCredentials::set($home, ['JELLYFIN_PASSWORD_PREVIOUS' => $cur]);
            ArrAuth::log('set-login', 'jellyfin', 'unknown');
            throw new CliError("Jellyfin gave no answer, and neither password logs in now, so whether it took the new one is unknown: $file keeps both,"
                . ' JELLYFIN_PASSWORD (the new one: try it first) and JELLYFIN_PASSWORD_PREVIOUS (the old one)', 'once Jellyfin answers, the user tries the new one, then the old one; then `login-check`');
        } catch (CliError $e) {
            MediaStackCredentials::set($home, $backup);
            ArrAuth::log('set-login', 'jellyfin', 'refused');
            throw new CliError("Jellyfin did not take the new password, so $file is as it was: " . $e->getMessage(), $again);
        }
    }

    /** 'works', 'refused' or 'unknown' (no answer or another error): one login that marks nothing. */
    private static function tries(Mediabrowser $mb, $user, $secret)
    {
        try {
            $mb->authenticate($user, $secret);
            return 'works';
        } catch (MediaAuthRejected $e) {
            return 'refused';
        } catch (CliError $e) {
            return 'unknown';
        }
    }

    /** [user, password, state]: state is MediaStackCredentials::state of the pair ('present' only when both are usable). */
    private static function credentials($home)
    {
        $v = MediaStackCredentials::read($home);
        $user = MediaStackCredentials::value($v, 'JELLYFIN_USERNAME');
        $state = MediaStackCredentials::state($v, 'JELLYFIN_PASSWORD');
        return [$user, MediaStackCredentials::value($v, 'JELLYFIN_PASSWORD'), $state === 'present' && $user === null ? 'missing' : $state];
    }

    private static function cause($cause, $detail, $hint = null)
    {
        return ArrLoginCheck::named(self::CAUSES, $cause, $detail, $hint);
    }
}
