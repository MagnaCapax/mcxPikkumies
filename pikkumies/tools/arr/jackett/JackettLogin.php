<?php
/**
 * JackettLogin: `login-check` of Jackett's dashboard login (tools/arr/README.md "Login of the other apps", ADR-0078
 * amendment 2026-10-19). It changes nothing: Jackett has no API-key route that sets the admin password, so there is no
 * set-login. Checked at upstream cbf1fa3f (2026-10-20): ServerConfigurationController.AdminPassword (POST
 * api/v2.0/server/AdminPassword, Controllers/ServerConfigurationController.cs:50-64) sits under the global filter that
 * needs a signed-in user (Startup.cs:83-86, AddControllers with AuthorizeFilter RequireAuthenticatedUser), and the only
 * authentication scheme is the login cookie (Startup.cs:51-59); the API key is read only by the Torznab and results
 * routes (ResultsController.cs:33-49). So the dashboard login changes in the dashboard only, by the user (skill.md).
 * From ServerConfig.json (json_decode, never written) it reads two facts, never a value: whether AdminPassword holds
 * anything (when not, the dashboard opens for anyone on this machine: SecurityService.cs:15-24 CheckAuthorised returns
 * true, and UIController.cs Login signs them in, :29-45) and whether AllowExternal is on (Jackett listens on every
 * address, ServerConfig.cs:105-121), outside Docker, where the container's port mapping decides instead.
 */
class JackettLogin
{
    const DASHBOARD = 'the user sets an admin password in Jackett\'s dashboard now (Admin password, Set Password), as skill.md says';
    /** cause => next step (README.md "Why a Jackett login fails", checked by the contract test). */
    const CAUSES = ['not-installed' => 'installer', 'config-invalid' => 'installer', 'not-answering' => 'restart',
        'no-admin-password' => self::DASHBOARD, 'external-access' => 'the user unticks External access in Jackett\'s dashboard and restarts it',
        'password-unknown' => 'the user\'s own step in the dashboard (skill.md "Jackett login"); the tool cannot check or change it'];

    public static function check(callable $locate, $timeout)
    {
        $file = '~/' . MediaStackCredentials::FILE;
        try {
            $w = $locate('key');
        } catch (CliError $e) {
            $m = $e->getMessage();
            $c = strpos($m, 'not installed') !== false ? 'not-installed' : (strpos($m, 'empty or not') !== false ? 'config-invalid' : 'not-answering');
            return ArrLoginCheck::out('jackett', [self::cause($c, $m, $e->hint)], $file);
        }
        $found = [];
        $anon = new ArrClient(['key' => ''] + $w, function () use ($w) {
            return ['key' => ''] + $w;
        }, '', $timeout);
        try {
            $anon->call('GET', 'health', [], null, ['timeout' => 10]); // no key: HealthcheckController.cs:8-21
        } catch (CliError $e) {
            $found[] = self::cause('not-answering', $e->getMessage(), $e->hint);
        }
        $j = json_decode((string) @file_get_contents($w['config'], false, null, 0, ArrConfig::MAX), true, 16);
        $j = is_array($j) ? $j : [];
        if ($w['source'] !== 'docker' && !empty($j['AllowExternal'])) {
            $found[] = self::cause('external-access', 'Jackett listens on every address (AllowExternal), not only on this machine');
        }
        $found[] = isset($j['AdminPassword']) && is_string($j['AdminPassword']) && $j['AdminPassword'] !== ''
            ? self::cause('password-unknown', 'Jackett has an admin password; it keeps only a hash of it (SecurityService.cs:26-38), so the tool cannot check it')
            : self::cause('no-admin-password', 'Jackett has no admin password set, so its dashboard opens for anyone on this machine');
        return ArrLoginCheck::out('jackett', $found, $file);
    }

    private static function cause($cause, $detail, $hint = null)
    {
        return ArrLoginCheck::named(self::CAUSES, $cause, $detail, $hint);
    }
}
