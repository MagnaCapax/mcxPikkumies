<?php
/**
 * WebGuardEvents: the guard's CDP state machine, without a socket. handle() takes one decoded CDP message and returns
 * the commands to send and the log records to write, so tests can feed it recorded messages. It tracks:
 *  - every attached target (browser-level Target.setAutoAttach, flatten, waitForDebuggerOnStart, repeated on each
 *    target so iframes, popups and workers are attached too) and the session it was attached from;
 *  - each page's top-level document and each frame's document (Page.frameNavigated), so a request knows whose page
 *    and whose frame asked;
 *  - navigations the CLI asked for (WebGuardIntent), and which requests carried the login, so a redirect keeps it only
 *    while every hop stays on the own panel prefix.
 * A target is resumed only after its Fetch.enable succeeded; when that fails the target is closed, never left running
 * unguarded. Every 401 challenge is cancelled, so the browser never answers one from its own credential cache.
 * ready() turns true once the browser-level attach is answered and every target that already existed has its
 * interception on (Chromium announces those targets before it answers the attach); broken() when the attach failed.
 * With no Authorization value (null) nothing ever gets a login, and the fence works the same.
 */
class WebGuardEvents
{
    private $self;
    private $hosts;
    private $auth; // the Authorization header value; never logged, never returned in a record
    private $intent; // callable($url): bool, true when the CLI asked for this navigation (consumes it)
    private $id = 0;
    private $sessions = []; // sessionId => [type, target, parent sessionId or null, top document URL or null, frames]
    private $pending = []; // command id => sessionId whose Fetch.enable it was
    private $startId = null;
    private $started = false;
    private $broken = false;
    private $initial = []; // sessionId => true: targets announced before the attach answer, waiting for Fetch.enable
    private $authed = []; // request id => true: the newest requests that carried the login (a redirect may keep it)

    public function __construct($self, array $hosts, $auth, $intent = null)
    {
        $this->self = $self;
        $this->hosts = $hosts;
        $this->auth = $auth;
        $this->intent = $intent;
    }

    /** The first command: attach to every target, paused before it runs anything. */
    public function start()
    {
        $c = $this->cmd('Target.setAutoAttach', ['autoAttach' => true, 'waitForDebuggerOnStart' => true, 'flatten' => true]);
        $this->startId = $c['id'];
        return [$c];
    }

    public function ready()
    {
        return $this->started && !$this->initial;
    }

    public function broken()
    {
        return $this->broken;
    }

    /** One CDP message => ['send' => [command, ...], 'log' => [record, ...]]. */
    public function handle(array $m)
    {
        $sid = isset($m['sessionId']) ? $m['sessionId'] : null;
        $p = isset($m['params']) && is_array($m['params']) ? $m['params'] : [];
        if (isset($m['id'])) {
            return $this->reply($m);
        }
        $method = isset($m['method']) ? $m['method'] : '';
        if ($method === 'Target.attachedToTarget') {
            return $this->attached($p, $sid);
        }
        if ($method === 'Target.detachedFromTarget' && isset($p['sessionId'])) {
            unset($this->sessions[$p['sessionId']]);
        } elseif ($method === 'Page.frameNavigated' && isset($this->sessions[$sid], $p['frame']['id'])) {
            $frames = array_slice($this->sessions[$sid]['frames'], -255, null, true);
            $this->sessions[$sid]['frames'] = $frames + [$p['frame']['id'] => (string) $p['frame']['url']];
            if ($this->sessions[$sid]['type'] === 'page' && empty($p['frame']['parentId'])) {
                $this->sessions[$sid]['top'] = (string) $p['frame']['url'];
            }
        } elseif ($method === 'Page.frameDetached' && isset($this->sessions[$sid], $p['frameId'])) {
            unset($this->sessions[$sid]['frames'][$p['frameId']]);
        } elseif ($method === 'Fetch.requestPaused') {
            return $this->paused($p, $sid);
        } elseif ($method === 'Fetch.authRequired') {
            return ['send' => [$this->cmd('Fetch.continueWithAuth', ['requestId' => $p['requestId'],
                'authChallengeResponse' => ['response' => 'CancelAuth']], $sid)], 'log' => []];
        }
        return ['send' => [], 'log' => []];
    }

    private function attached(array $p, $parent)
    {
        $ns = $p['sessionId'];
        $type = (string) $p['targetInfo']['type'];
        $this->sessions[$ns] = ['type' => $type, 'target' => (string) $p['targetInfo']['targetId'], 'parent' => $parent,
            'top' => null, 'frames' => []];
        $auto = $this->cmd('Target.setAutoAttach', ['autoAttach' => true, 'waitForDebuggerOnStart' => true, 'flatten' => true], $ns);
        if ($type === 'worker') {
            // A dedicated worker has no Fetch domain ('Fetch.enable' wasn't found); its requests pause in the session of
            // the page that started it, with that page's frame (seen with Chromium 1208), so it is resumed as it is.
            return ['send' => [$auto, $this->cmd('Runtime.runIfWaitingForDebugger', [], $ns)], 'log' => []];
        }
        $send = [$this->cmd('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']], 'handleAuthRequests' => true], $ns)];
        $this->pending[$this->id] = $ns;
        if ($type === 'page' || $type === 'iframe') {
            $send[] = $this->cmd('Page.enable', [], $ns);
        }
        if ($parent === null && !$this->started) {
            $this->initial[$ns] = true;
        }
        $send[] = $auto;
        return ['send' => $send, 'log' => []];
    }

    /** A Fetch.enable answer: resume the target, or close it when interception could not be turned on. */
    private function reply(array $m)
    {
        if ($m['id'] === $this->startId) {
            $this->started = !isset($m['error']);
            $this->broken = isset($m['error']);
            return ['send' => [], 'log' => $this->broken ? [['action' => 'error', 'reason' => 'the browser refused to attach to its targets']] : []];
        }
        if (!isset($this->pending[$m['id']])) {
            return ['send' => [], 'log' => []];
        }
        $ns = $this->pending[$m['id']];
        unset($this->pending[$m['id']], $this->initial[$ns]);
        if (!isset($m['error'])) {
            return ['send' => [$this->cmd('Runtime.runIfWaitingForDebugger', [], $ns)], 'log' => []];
        }
        $type = isset($this->sessions[$ns]) ? $this->sessions[$ns]['type'] : 'unknown';
        $send = isset($this->sessions[$ns]) ? [$this->cmd('Target.closeTarget', ['targetId' => $this->sessions[$ns]['target']])] : [];
        return ['send' => $send, 'log' => [['action' => 'close', 'reason' => 'request interception could not be turned on', 'type' => $type]]];
    }

    private function paused(array $p, $sid)
    {
        $rid = $p['requestId'];
        $req = isset($p['request']) && is_array($p['request']) ? $p['request'] : [];
        $redirectOf = isset($p['redirectedRequestId']) ? (string) $p['redirectedRequestId'] : null;
        $cli = false;
        try {
            $s = isset($this->sessions[$sid]) ? $this->sessions[$sid] : null;
            $type = isset($p['resourceType']) ? (string) $p['resourceType'] : '';
            $mainNav = $s !== null && $s['type'] === 'page' && $type === 'Document' && isset($p['frameId']) && $p['frameId'] === $s['target'];
            if ($mainNav && $redirectOf === null && $this->intent !== null) {
                $cli = (bool) call_user_func($this->intent, (string) $req['url']);
            }
            $d = WebGuardDecision::decide(['url' => (string) $req['url'], 'method' => isset($req['method']) ? $req['method'] : 'GET',
                'type' => $type, 'top' => $this->top($sid), 'frame' => $this->doc($sid, isset($p['frameId']) ? $p['frameId'] : null),
                'mainNav' => $mainNav, 'cli' => $cli, 'redirect' => $redirectOf === null ? null : isset($this->authed[$redirectOf])],
                $this->self, $this->hosts);
        } catch (Throwable $e) {
            $d = ['action' => 'block', 'reason' => 'the guard could not decide', 'prefix' => null];
        }
        if ($redirectOf !== null) {
            unset($this->authed[$redirectOf]);
        }
        if ($d['action'] === 'block') {
            return ['send' => [$this->cmd('Fetch.failRequest', ['requestId' => $rid, 'errorReason' => 'BlockedByClient'], $sid)],
                'log' => [['action' => 'block', 'reason' => $d['reason'], 'prefix' => $d['prefix'],
                    'method' => isset($req['method']) ? $req['method'] : null, 'type' => isset($p['resourceType']) ? $p['resourceType'] : null]]];
        }
        $headers = [];
        $had = false;
        foreach (isset($req['headers']) && is_array($req['headers']) ? $req['headers'] : [] as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $had = true;
            } else {
                $headers[] = ['name' => $k, 'value' => (string) $v];
            }
        }
        if ($d['action'] === 'auth' && $this->auth === null) {
            $d['action'] = 'strip'; // no login file: the own panel gets its 401, which is cancelled below
        }
        if ($d['action'] === 'auth') {
            $headers[] = ['name' => 'Authorization', 'value' => $this->auth];
            $this->authed = array_slice($this->authed, -511, null, true) + [$rid => true]; // a redirect follows within moments
        }
        $params = ['requestId' => $rid] + ($d['action'] === 'auth' || $had ? ['headers' => $headers] : []);
        return ['send' => [$this->cmd('Fetch.continueRequest', $params, $sid)], 'log' => []];
    }

    /** The top-level document URL of the page $sid belongs to (walking up from iframes and workers), or null. */
    private function top($sid)
    {
        for ($i = 0; $sid !== null && isset($this->sessions[$sid]) && $i < 16; $i++) {
            if ($this->sessions[$sid]['type'] === 'page') {
                return $this->sessions[$sid]['top'];
            }
            $sid = $this->sessions[$sid]['parent'];
        }
        return null;
    }

    /**
     * The document URL of the frame that sent a request: the frame's last navigation in its session; a page's frame not
     * seen yet (a new iframe) counts as the page's own document; an out-of-process iframe's is its own main frame;
     * a worker's is the document of the session it was attached from. Null when unknown.
     */
    private function doc($sid, $frameId)
    {
        for ($i = 0; $sid !== null && isset($this->sessions[$sid]) && $i < 16; $i++) {
            $s = $this->sessions[$sid];
            if ($frameId !== null && isset($s['frames'][$frameId])) {
                return $s['frames'][$frameId];
            }
            if ($s['type'] === 'page') {
                return $s['top'];
            }
            if ($s['type'] === 'iframe') {
                return isset($s['frames'][$s['target']]) ? $s['frames'][$s['target']] : null;
            }
            $sid = $s['parent'];
            $frameId = null;
        }
        return null;
    }

    private function cmd($method, array $params, $sid = null)
    {
        $c = ['id' => ++$this->id, 'method' => $method, 'params' => (object) $params];
        if ($sid !== null) {
            $c['sessionId'] = $sid;
        }
        return $c;
    }
}
