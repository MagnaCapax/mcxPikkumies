<?php
/**
 * HermesDashboardGate: `pikkumies run dashboard` never starts Hermes' dashboard without a login (ADR-0074).
 *
 * Hermes v0.22.2 leaves its dashboard open on a loopback bind: the page then carries the session key
 * (web_server_dashboard.py:164-165), and that key opens the sensitive APIs, the chat terminal included
 * (web_server.py:510-516). The login gate engages only for a non-loopback bind or a non-loopback
 * dashboard.public_url (web_server.py:550-562). On a shared server every other account can reach 127.0.0.1,
 * so an ungated dashboard lets any of them run commands as this user. This class mirrors Hermes' own rule and
 * refuses the start where Hermes would not gate. Where it cannot read a value the way Hermes does, it refuses.
 */
class HermesDashboardGate
{
    /** Hermes' _LOOPBACK_HOST_VALUES (web_server.py:522): exact strings, as Hermes compares them. */
    const LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    /** Dashboard arguments that start no server. */
    const NO_START = ['--stop', '--status', '-h', '--help', 'register'];

    /** True for `run dashboard ...` that starts a server. */
    public static function starts(array $passArgs)
    {
        return isset($passArgs[0]) && $passArgs[0] === 'dashboard' && !array_intersect(array_slice($passArgs, 1), self::NO_START);
    }

    /** '' when Hermes will ask for a login (or nothing starts); else why the start is refused. */
    public static function refusal(array $passArgs, $bin)
    {
        if (!self::starts($passArgs) || !in_array(self::bindHost($passArgs), self::LOOPBACK, true)) {
            return ''; // nothing starts, or a non-loopback bind, which Hermes gates itself
        }
        // A Desktop-owned loopback backend (HERMES_DESKTOP=1 plus a session token) skips the public URL (web_server.py:565-583).
        $host = getenv('HERMES_DESKTOP') === '1' ? '' : self::urlHost(self::publicUrl($bin));
        if ($host !== '' && !in_array($host, self::LOOPBACK, true)) {
            return '';
        }
        return 'Hermes would serve it on ' . self::bindHost($passArgs) . ' without a login, and on a shared server other accounts can reach that'
            . ' address, read the page\'s session key and run commands as you. Set HERMES_DASHBOARD_PUBLIC_URL (or dashboard.public_url) to the'
            . ' https address you open it at, with HERMES_DASHBOARD_BASIC_AUTH_USERNAME, _PASSWORD and _SECRET for Hermes\' login, or bind a'
            . ' non-loopback --host, which makes Hermes require that login too.';
    }

    /** The bind host argparse ends up with: the last --host (or the --ho/--hos abbreviation), else Hermes' default. */
    public static function bindHost(array $passArgs)
    {
        $host = '127.0.0.1';
        for ($i = 1, $n = count($passArgs); $i < $n; $i++) {
            if (preg_match('/^--ho(?:s|st)?$/', $passArgs[$i]) && $i + 1 < $n) {
                $host = $passArgs[++$i];
            } elseif (preg_match('/^--ho(?:s|st)?=(.*)$/s', $passArgs[$i], $m)) {
                $host = $m[1];
            }
        }
        return $host === '' ? '127.0.0.1' : $host; // an empty value: treated as the default, the cautious reading
    }

    /**
     * The public URL Hermes resolves (dashboard_auth/prefix.py resolve_public_url): the environment variable when it is
     * well formed, else dashboard.public_url. Both are read through Hermes itself, so its .env files and managed scope count.
     */
    public static function publicUrl($bin)
    {
        foreach (['HERMES_DASHBOARD_PUBLIC_URL', 'dashboard.public_url'] as $key) {
            try {
                $r = ToolProc::run([$bin, 'config', 'get', $key, '--json', '--raw'], HermesConfig::env());
            } catch (Exception $x) {
                return '';
            }
            $lines = array_values(array_filter(explode("\n", trim($r['out'])), 'strlen'));
            $value = $r['code'] === 0 && $lines ? json_decode(end($lines), true) : null;
            if (is_string($value) && self::wellFormed($value)) {
                return trim($value);
            }
        }
        return '';
    }

    /** prefix.py _normalise_public_url: trimmed, none of " ' < > space tab CR LF, http(s) with a host part. */
    public static function wellFormed($url)
    {
        $url = trim($url);
        return $url !== '' && strpbrk($url, "\"'<> \n\r\t") === false && preg_match('#^https?://[^/?\#]+#i', $url) === 1;
    }

    /** The lower-case host of a plain http(s) URL (no user part), brackets off an IPv6 address; '' when not that plain. */
    public static function urlHost($url)
    {
        if (!preg_match('#^https?://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::[0-9]*)?(?:[/?\#]|$)#i', $url, $m)) {
            return '';
        }
        return strtolower(trim($m[1], '[]'));
    }
}
