<?php
/**
 * Mediabrowser: the HTTP client Jellyfin and Emby share (Jellyfin is a 2018 fork of Emby and the paths
 * these units use are the same). The per-server differences are the SETTINGS rows, checked against
 * the Jellyfin 12.2.0 and Emby 4.1.1.0 OpenAPI specs and live 12.2.0 / 4.10.1.0 servers on 2026-10-14
 * (README.md of each unit). ALLOW is the whole list of what may be sent: no DELETE, no /Auth/Keys,
 * no /System/Restart or /Shutdown, no /Startup, no configuration or user-policy writes.
 */
class Mediabrowser
{
    const SETTINGS = [
        // Jellyfin 12 removed X-Emby-Token (live 12.2.0: 401) and takes `Authorization: MediaBrowser ..., Token="..."`.
        'jellyfin' => ['name' => 'Jellyfin', 'auth' => 'mediabrowser', 'pascal' => false, 'latest' => '/Items/Latest',
            'recursive' => false, 'hw' => 'HardwareAccelerationType'],
        'emby' => ['name' => 'Emby', 'auth' => 'x-emby-token', 'pascal' => true, 'latest' => '/Users/{user}/Items/Latest',
            'recursive' => true, 'hw' => 'HardwareAccelerationMode'],
    ];
    const ALLOW = [
        'GET' => ['#^/System/Info(/Public)?$#', '#^/System/Logs(/Log)?$#', '#^/System/Configuration/encoding$#',
            '#^/Library/VirtualFolders$#', '#^/Items$#', '#^/Items/Counts$#', '#^/Items/Latest$#',
            '#^/Users/[0-9A-Za-z-]{1,64}/Items/Latest$#', '#^/Users$#', '#^/Sessions$#', '#^/ScheduledTasks$#'],
        'POST' => ['#^/Users/AuthenticateByName$#', '#^/Items/[0-9A-Za-z-]{1,64}/Refresh$#',
            '#^/Sessions/[0-9A-Za-z-]{1,64}/Playing/Stop$#', '#^/Sessions/[0-9A-Za-z-]{1,64}/Message$#'],
    ];

    public $http;
    public $set;
    private $deviceId;
    private $token = null;
    private $onRejected = null;

    public function __construct($kind, MediaHttp $http, $deviceId)
    {
        $this->set = self::SETTINGS[$kind];
        $this->http = $http;
        $this->deviceId = $deviceId;
    }

    /** The credential for every later call, and what to do when the server refuses it. */
    public function useToken($token, callable $onRejected)
    {
        $this->token = $token;
        $this->onRejected = $onRejected;
        $this->http->scrub($token);
    }

    /** $optional: a read the verb can do without; a 401 there fails only that read and marks nothing. */
    public function get($path, array $params = [], $optional = false)
    {
        return $this->call('GET', $path, $params, null, false, $optional);
    }

    public function post($path, array $params = [], $body = null)
    {
        return $this->call('POST', $path, $params, $body);
    }

    /** A text body (a log file), not JSON. */
    public function text($path, array $params = [])
    {
        return $this->call('GET', $path, $params, null, true);
    }

    /** GET /System/Info/Public without a credential: the decoded body when it answers 200, else null. */
    public function publicInfo($timeout = null)
    {
        try {
            $r = $this->http->send('GET', '/System/Info/Public', [], null, ['Accept: application/json'], $timeout);
        } catch (CliError $e) {
            return null;
        }
        $d = json_decode($r['body'], true);
        return $r['code'] === 200 && is_array($d) ? $d : null;
    }

    /** POST /Users/AuthenticateByName once: ['token', 'user_id'], or MediaAuthRejected. */
    public function authenticate($user, $password)
    {
        $body = json_encode(['Username' => $user, 'Pw' => $password]);
        $this->http->scrub($password);
        $h = ['Content-Type: application/json', 'Accept: application/json', $this->authHeader(null)];
        $d = $this->http->json('POST', '/Users/AuthenticateByName', [], $body, $h);
        if (!isset($d['AccessToken']) || !is_string($d['AccessToken']) || $d['AccessToken'] === '') {
            throw new CliError("{$this->set['name']} accepted the login but sent no session token",
                'run `login` with an API key from the dashboard');
        }
        return ['token' => $d['AccessToken'], 'user_id' => isset($d['User']['Id']) ? (string) $d['User']['Id'] : ''];
    }

    /** The parameter names in the server's own spelling: camelCase for Jellyfin, PascalCase for Emby. */
    public function q(array $params)
    {
        if (!$this->set['pascal']) {
            return $params;
        }
        $out = [];
        foreach ($params as $k => $v) {
            $out[ucfirst($k)] = $v;
        }
        return $out;
    }

    private function call($method, $path, array $params, $body = null, $raw = false, $optional = false)
    {
        if ($this->token === null) {
            throw new CliError('no credential loaded (a bug in the unit)', 'report it');
        }
        $h = ['Accept: application/json', $this->authHeader($this->token)];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        try {
            if (!$raw) {
                return $this->http->json($method, $path, $this->q($params), $body, $h);
            }
            $r = $this->http->send($method, $path, $this->q($params), $body, $h);
            $this->http->check($r['code'], "$method $path");
            return $r['body'];
        } catch (MediaAuthRejected $e) {
            if ($optional) {
                throw new CliError("{$this->set['name']} answered 401 to $method $path", 'an optional read; the credential is not marked');
            }
            $instead = call_user_func($this->onRejected); // marks the credential; may explain what happens next
            throw $instead instanceof CliError ? new MediaAuthRejected($instead->getMessage(), $instead->hint) : $e;
        }
    }

    /** The header line: Jellyfin's MediaBrowser scheme (the token in it when given), Emby's X-Emby-Token. */
    private function authHeader($token)
    {
        $client = 'Client="pikkumies", Device="pikkumies", DeviceId="' . $this->deviceId . '", Version="1"';
        if ($this->set['auth'] === 'x-emby-token') {
            return $token !== null ? "X-Emby-Token: $token" : "X-Emby-Authorization: Emby $client";
        }
        return "Authorization: MediaBrowser $client" . ($token !== null ? ', Token="' . $token . '"' : '');
    }
}
