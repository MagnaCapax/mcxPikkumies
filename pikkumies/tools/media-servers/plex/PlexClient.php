<?php
/**
 * PlexClient: HTTP to the user's own Plex Media Server. The token goes only in the X-Plex-Token
 * header (Plex also takes it as a URL parameter, which ends up in logs; MediaHttp refuses that), only
 * to this machine's loopback on a port this user owns, never to plex.tv or any other host. ALLOW is
 * the whole list of what may be sent: no DELETE, no /updater/apply, no preference writes.
 * Paths: README.md of this unit, "Plex calls" (community OpenAPI 1.1.1 read 2026-10-14; [UNVERIFIED-spec]).
 */
class PlexClient
{
    const ALLOW = [
        'GET' => ['#^/$#', '#^/identity$#', '#^/library/sections/all$#', '#^/library/sections/[0-9]{1,9}/all$#', '#^/status/sessions$#',
            '#^/library/recentlyAdded$#', '#^/:/prefs$#'],
        'POST' => ['#^/library/sections/[0-9]{1,9}/refresh$#', '#^/status/sessions/terminate$#'],
    ];

    public $http;
    private $client;
    private $token = null;
    private $onRejected = null;

    public function __construct(MediaHttp $http, $clientId)
    {
        $this->http = $http;
        $this->client = $clientId;
    }

    public function useToken($token, callable $onRejected)
    {
        $this->token = $token;
        $this->onRejected = $onRejected;
        $this->http->scrub($token);
    }

    /** The MediaContainer of a JSON answer ([] for an empty body). */
    /** $optional: a read the verb can do without; a 401 there fails only that read and marks nothing. */
    public function get($path, array $query = [], $optional = false)
    {
        return $this->call('GET', $path, $query, false, $optional);
    }

    public function post($path, array $query = [], $gated = false)
    {
        return $this->call('POST', $path, $query, $gated);
    }

    /** True when the server answers /identity (open in both specs), with no credential sent. */
    public function answers($timeout = 3)
    {
        try {
            return $this->http->send('GET', '/identity', [], null, ['Accept: application/json'], $timeout)['code'] === 200;
        } catch (CliError $e) {
            return false;
        }
    }

    /**
     * `login`'s check of a typed token: MediaAuthRejected when Plex refuses it; a plain CliError (stored, `verified:false`)
     * when the same read also answers with no token at all. Plex lets an allowed network (allowedNetworks) and, while
     * unclaimed, its own subnet in without a token (live 1.43.4: any token got 200 there), so a 200 alone proves nothing.
     */
    public function checkToken($token)
    {
        $this->useToken($token, function () {
        });
        $this->get('/library/sections/all');
        if ($this->http->send('GET', '/library/sections/all', [], null, ['Accept: application/json'])['code'] === 200) {
            throw new CliError('Plex answered without any token too (an allowed network or an unclaimed server),'
                . ' so it did not check this one');
        }
    }

    /**
     * $gated: a 401 here is Plex's feature gate, not a refused token (the terminate call's documented 401 is
     * "Server does not have the feature enabled"), so the token is not marked rejected.
     */
    private function call($method, $path, array $query, $gated = false, $optional = false)
    {
        if ($this->token === null) {
            throw new CliError('no credential loaded (a bug in the unit)', 'report it');
        }
        $h = ['Accept: application/json', "X-Plex-Token: {$this->token}", function () { // made by MediaHttp::send after its checks
            if ($this->client instanceof Closure) {
                $this->client = call_user_func($this->client); // the stored device id, written on first use only
            }
            return "X-Plex-Client-Identifier: {$this->client}";
        }, 'X-Plex-Product: pikkumies'];
        try {
            $d = $this->http->json($method, $path, $query, null, $h);
        } catch (MediaAuthRejected $e) {
            if ($gated) {
                throw new CliError('Plex refused (HTTP 401): for this call its API documents that answer as'
                    . ' "Server does not have the feature enabled"',
                    'end the stream from the Plex dashboard instead');
            }
            if ($optional) {
                throw new CliError("Plex answered 401 to $method $path", 'an optional read; the token is not marked');
            }
            $instead = call_user_func($this->onRejected);
            throw $instead instanceof CliError ? new MediaAuthRejected($instead->getMessage(), $instead->hint) : $e;
        }
        return isset($d['MediaContainer']) && is_array($d['MediaContainer']) ? $d['MediaContainer'] : $d;
    }
}
