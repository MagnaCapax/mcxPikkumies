<?php
/**
 * JellyfinAuth: which credential this call uses (README.md of this unit, "Credentials"), in order:
 * 1. an API key the user stored with `login`;
 * 2. on PMSS, a login session: the password the installer wrote to the user's own
 *    ~/.media-stack-credentials.txt, sent once to /Users/AuthenticateByName (ADR-0019: credentials
 *    are read from the user's own files), and the session token kept in local/secrets/jellyfin;
 * 3. otherwise an error whose hint is `login`.
 * One attempt per call. A refused key or password is marked and never tried again until `login`;
 * a refused session is dropped, and the next call logs in once more. No API key is ever created.
 */
class JellyfinAuth
{
    const CREDENTIALS = '.media-stack-credentials.txt';

    /** Loads the credential into $mb. Returns [where it lives (for `stats`), literal values to scrub]. */
    public static function load(Mediabrowser $mb, MediaSecret $secret, $home, $family, $script)
    {
        $s = $secret->load();
        $login = "the user runs `php $script login` in a terminal with an API key from the Jellyfin dashboard (Administration > API Keys)";
        if (!empty($s['key']) && is_string($s['key'])) {
            if (!empty($s['key_rejected'])) {
                throw new CliError('Jellyfin refused the API key stored with `login`; not trying it again', $login);
            }
            $mb->useToken($s['key'], function () use ($secret, $login) {
                $secret->update(['key_rejected' => true]);
                return new CliError('Jellyfin refused the API key stored with `login`; it is marked and will not be tried again', $login);
            });
            return ['API key stored with `login` in ' . $secret->path(), [$s['key']]];
        }
        if (!empty($s['session']) && is_string($s['session'])) {
            $mb->useToken($s['session'], function () use ($secret) {
                $secret->update(['session' => null]);
                return new CliError('Jellyfin ended the stored login session; it is cleared, and the next call logs in once more with ~/' . self::CREDENTIALS, 'run the same command again');
            });
            return ['login session from ~/' . self::CREDENTIALS . ', kept in ' . $secret->path(), [$s['session']]];
        }
        $cred = $family === 'pmss' ? self::pmssCredentials($home) : null;
        if ($cred === null) {
            throw new CliError('no Jellyfin credential for this tool', $login);
        }
        if (!empty($s['pw_rejected'])) {
            throw new CliError('Jellyfin refused the password in ~/' . self::CREDENTIALS . ' before; not trying it again (repeated failures can lock the account)', $login);
        }
        try {
            $a = $mb->authenticate($cred['user'], $cred['password']);
        } catch (MediaAuthRejected $e) {
            $secret->update(['pw_rejected' => true]);
            throw new CliError('Jellyfin refused the user name and password in ~/' . self::CREDENTIALS . '; marked, not tried again', $login);
        }
        $secret->update(['session' => $a['token'], 'user_id' => $a['user_id']]);
        $mb->useToken($a['token'], function () use ($secret) {
            $secret->update(['session' => null]);
        });
        return ['login session from ~/' . self::CREDENTIALS . ', kept in ' . $secret->path(), [$a['token'], $cred['password']]];
    }

    /**
     * JELLYFIN_USERNAME and JELLYFIN_PASSWORD, read the way PMSS reads them (install-media-stack.sh:1470-1484:
     * `KEY = value` lines; a value that starts with "[" is a placeholder). The file is the user's own,
     * mode 600 (install-media-stack.sh:2018-2049). Null when it is missing, a link, or incomplete.
     */
    public static function pmssCredentials($home)
    {
        $file = "$home/" . self::CREDENTIALS;
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $v = [];
        foreach (explode("\n", (string) @file_get_contents($file, false, null, 0, 65536)) as $line) {
            $p = strpos($line, ' = ');
            if ($p !== false && !isset($v[substr($line, 0, $p)])) {
                $v[substr($line, 0, $p)] = rtrim(substr($line, $p + 3), "\r");
            }
        }
        $user = isset($v['JELLYFIN_USERNAME']) ? $v['JELLYFIN_USERNAME'] : '';
        $pw = isset($v['JELLYFIN_PASSWORD']) ? $v['JELLYFIN_PASSWORD'] : '';
        return $user === '' || $pw === '' || $pw[0] === '[' ? null : ['user' => $user, 'password' => $pw];
    }
}
