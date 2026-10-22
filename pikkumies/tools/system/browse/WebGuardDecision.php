<?php
/**
 * WebGuardDecision: what the browse unit's CDP guard does with one browser request. Pure: no I/O, no clock, no state;
 * WebGuardEvents feeds it and guard.php sends the answer. On PMSS every account's panel sits on one origin
 * (template.nginx-user routes /user-, /public-, /~, /webdav- and /deluge-<name> per account on the same server
 * block), so the browser must not carry this account's panel login to anyone else, and must not reach the
 * neighbours' paths at all: a neighbour's page on the same origin could script the account's own panel.
 *
 * Answers ('action'):
 *  - auth:  continue with this account's Authorization added. Only for /user-<self>/, and only
 *           when the page asking is the account's own (both the requesting frame's document and the top-level
 *           document are on that prefix) or the request is a top-level GET navigation the CLI asked for.
 *           A redirect keeps the login only when the hop it came from carried it. A foreign page's or frame's img,
 *           fetch, iframe or form POST to the own prefix, or a foreign site's redirect to it, goes out without the
 *           login, so it gets the 401 and the guard cancels it.
 *  - block: fail the request before anything is sent. On the panel host: every path outside the account's own
 *           routes, and any path that is not plain once decoded (dot segment, backslash, a second encoding, a
 *           control character), because nginx decodes and resolves those before it picks the account's location.
 *  - strip: continue with any Authorization header removed. Every other site, and the own routes that need no
 *           panel login from the browser (/~<self>/, /public-<self>/, /webdav-<self>/, /deluge-<self>/). /public-<self>/
 *           asks for no login and hosts third-party apps (Servarr, Jellyfin, `docker expose --public`), so the panel
 *           password never goes there.
 */
class WebGuardDecision
{
    /**
     * $req: url, method, type (CDP resourceType), top (the URL of the requesting page's top-level document, or null
     * when unknown), frame (the URL of the requesting frame's document, or null when unknown), mainNav (true for a
     * Document request of a page's main frame), cli (true when the CLI asked for this navigation), redirect (null, or
     * for a redirect hop whether the hop it came from carried the login). $self: the account name. $hosts: the panel
     * host names. Returns action, reason and prefix (the first path segment on the panel host, for the log; never a
     * query string).
     */
    public static function decide(array $req, $self, array $hosts)
    {
        $c = self::classify((string) $req['url'], $self, $hosts);
        if ($c['kind'] === 'external') {
            return self::answer('strip', 'not the panel host', null);
        }
        if ($c['kind'] === 'bad' || $c['kind'] === 'foreign') {
            return self::answer('block', $c['why'], $c['prefix']);
        }
        if ($c['kind'] === 'own') {
            return self::answer('strip', 'own route that takes no panel login from the browser', $c['prefix']);
        }
        if (isset($req['redirect'])) {
            return $req['redirect'] === true ? self::answer('auth', 'redirect of a request that carried the login', $c['prefix'])
                : self::answer('strip', 'redirect of a request that went without the login', $c['prefix']);
        }
        if (!empty($req['mainNav']) && !empty($req['cli']) && strtoupper((string) $req['method']) === 'GET') {
            return self::answer('auth', 'top-level navigation the CLI asked for', $c['prefix']);
        }
        if (self::isLogin($req, 'top', $self, $hosts) && self::isLogin($req, 'frame', $self, $hosts)) {
            return self::answer('auth', 'requested by the account\'s own page', $c['prefix']);
        }
        return self::answer('strip', 'own panel path requested by a page that is not the account\'s own', $c['prefix']);
    }

    /**
     * kind: external (not http(s)/ws(s) on a panel host), bad (not a plain path once decoded), foreign (on the panel
     * host, outside the own routes), own (an own route without the browser login), login (/user-<self>/). why: the
     * reason for bad/foreign. prefix: the first raw path segment, at most 64 bytes.
     */
    public static function classify($url, $self, array $hosts)
    {
        $u = parse_url($url);
        $scheme = is_array($u) && isset($u['scheme']) ? strtolower($u['scheme']) : '';
        if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true) || !isset($u['host'])
            || !in_array(self::host($u['host']), array_map([__CLASS__, 'host'], $hosts), true)) {
            return ['kind' => 'external', 'why' => null, 'prefix' => null];
        }
        $raw = isset($u['path']) && $u['path'] !== '' ? $u['path'] : '/';
        $prefix = substr(preg_replace('#^(/[^/]*/?).*$#s', '$1', $raw), 0, 64);
        $bad = function ($why) use ($prefix) {
            return ['kind' => 'bad', 'why' => $why, 'prefix' => $prefix];
        };
        if (strpos($raw, '\\') !== false || stripos($raw, '%5c') !== false) {
            return $bad('backslash in the path');
        }
        $path = rawurldecode($raw);
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            return $bad('path encoded twice');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return $bad('control character in the path');
        }
        if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            return $bad('dot segment in the path');
        }
        $named = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', (string) $self) === 1; // no name: nothing is own
        foreach ($named ? ['user-'] : [] as $route) {
            if (self::under($path, '/' . $route . $self)) {
                return ['kind' => 'login', 'why' => null, 'prefix' => $prefix];
            }
        }
        foreach ($named ? ['~', 'public-', 'webdav-', 'deluge-'] : [] as $route) {
            if (self::under($path, '/' . $route . $self)) {
                return ['kind' => 'own', 'why' => null, 'prefix' => $prefix];
            }
        }
        return ['kind' => 'foreign', 'why' => 'outside this account\'s own paths on the panel host', 'prefix' => $prefix];
    }

    private static function isLogin(array $req, $key, $self, array $hosts)
    {
        if (!isset($req[$key]) || !is_string($req[$key])) {
            return false;
        }
        $c = self::classify($req[$key], $self, $hosts);
        return $c['kind'] === 'login';
    }

    /** $path is exactly $base or below "$base/" (so /user-ab never matches /user-abc/). */
    private static function under($path, $base)
    {
        return $path === $base || strncmp($path, $base . '/', strlen($base) + 1) === 0;
    }

    private static function host($h)
    {
        return rtrim(strtolower(trim((string) $h, '[]')), '.');
    }

    private static function answer($action, $reason, $prefix)
    {
        return ['action' => $action, 'reason' => $reason, 'prefix' => $prefix];
    }
}
