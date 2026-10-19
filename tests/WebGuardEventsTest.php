<?php
/**
 * The browse guard's CDP state machine (WebGuardEvents) fed recorded and hand-written DevTools messages, its WebSocket
 * frame codec, and guard.php end to end against a fake DevTools endpoint. No browser. The recorded session is Chromium
 * 1208 (HeadlessChrome 145) driven through a fake panel on panel.example.com and a foreign site on foreign.example.net,
 * with the test servers' ports removed.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/WebGuardDecision.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/WebGuardIntent.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/WebGuardEvents.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/CdpSocket.php';

const WGE_AUTH = 'Basic ZXhhbXBsZXVzZXI6cHctZXhhbXBsZXVzZXI='; // exampleuser:pw-exampleuser, a fixture value

/** A guard for exampleuser on panel.example.com; $cli: URLs the CLI "opens" (each consumed once). */
function wgeEvents(array $cli = [], $auth = WGE_AUTH)
{
    return new WebGuardEvents('exampleuser', ['panel.example.com'], $auth, function ($url) use (&$cli) {
        $i = array_search($url, $cli, true);
        if ($i === false) {
            return false;
        }
        unset($cli[$i]);
        return true;
    });
}

/** Feeds $msgs; answers every command with success unless $fail(command) says otherwise. Returns [requestPaused url => answer command, logs, all sent]. */
function wgeRun(WebGuardEvents $ev, array $msgs, $fail = null)
{
    $answers = [];
    $logs = [];
    $sent = $ev->start();
    $queue = $msgs;
    $acks = [['id' => $sent[0]['id'], 'result' => []]];
    while ($queue || $acks) {
        $m = $acks ? array_shift($acks) : array_shift($queue);
        $out = $ev->handle($m);
        $logs = array_merge($logs, $out['log']);
        foreach ($out['send'] as $c) {
            $sent[] = $c;
            $err = $fail !== null && $fail($c);
            $acks[] = ['id' => $c['id']] + ($err ? ['error' => ['code' => -32601, 'message' => 'not found']] : ['result' => []])
                + (isset($c['sessionId']) ? ['sessionId' => $c['sessionId']] : []);
        }
        if (isset($m['method']) && $m['method'] === 'Fetch.requestPaused') {
            $answers[] = [$m['params']['request']['url'], end($out['send'])];
        }
    }
    return [$answers, $logs, $sent];
}

/** 'auth', 'strip', 'plain' (continued, headers untouched) or 'block', from a Fetch answer. */
function wgeAction(array $c)
{
    if ($c['method'] === 'Fetch.failRequest') {
        return 'block';
    }
    $p = (array) $c['params'];
    if (!isset($p['headers'])) {
        return 'plain';
    }
    $auth = array_values(array_filter($p['headers'], function ($h) {
        return strcasecmp($h['name'], 'Authorization') === 0;
    }));
    check(count($auth) <= 1 && (!$auth || $auth[0]['value'] === WGE_AUTH), 'a wrong or doubled Authorization header: ' . json_encode($auth));
    return $auth ? 'auth' : 'strip';
}

function wgePage($sid = 'S1', $tid = 'T1', $parent = null)
{
    return ['method' => 'Target.attachedToTarget', 'params' => ['sessionId' => $sid, 'targetInfo' => ['targetId' => $tid, 'type' => 'page', 'url' => ''],
        'waitingForDebugger' => true]] + ($parent !== null ? ['sessionId' => $parent] : []);
}

function wgePaused($url, array $more = [], $sid = 'S1')
{
    return ['method' => 'Fetch.requestPaused', 'sessionId' => $sid, 'params' => $more + ['requestId' => 'r-' . md5($url . json_encode($more)),
        'request' => ['url' => $url, 'method' => 'GET', 'headers' => ['Accept' => '*/*']], 'frameId' => 'T1', 'resourceType' => 'Document']];
}

return [
    'browse guard: a recorded Chromium session gives the login only to own pages and CLI opens, and blocks the neighbours' => function () {
        $msgs = array_map(function ($l) {
            return json_decode($l, true);
        }, file(__DIR__ . '/fixtures/browse/chromium-1208-guard-session.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $p = 'http://panel.example.com';
        $f = 'http://foreign.example.net';
        $cli = ["$p/user-exampleuser", "$p/user-exampleuser/page", "$p/user-exampleuser/redir-own", "$f/csrf", "$f/to-own", "$p/public-otheruser/",
            "$p/user-exampleuser/..%2Fuser-otheruser/"];
        list($answers, $logs, $sent) = wgeRun(wgeEvents($cli), $msgs);
        $want = [
            "$p/user-exampleuser" => 'auth', "$p/user-exampleuser/" => 'auth', "$p/user-exampleuser/page" => 'auth', "$p/user-exampleuser/own.png" => 'auth',
            "$p/user-otheruser/x.png" => 'block', "$p/user-exampleuser/frame" => 'auth', "$f/inner" => 'plain', "$p/user-exampleuser/api" => 'auth',
            "$p/user-exampleuser/w.js" => 'auth', "$p/user-exampleuser/from-foreign-iframe" => 'plain', "$p/user-exampleuser/fromworker" => 'auth',
            "$p/public-otheruser/fromworker" => 'block', "$p/user-exampleuser/redir-own" => 'auth', "$p/user-exampleuser/landing" => 'auth',
            "$p/user-exampleuser/nocli" => 'auth', // the page before it was the account's own (landing)
            "$f/csrf" => 'plain', "$p/user-exampleuser/img-from-foreign" => 'plain', "$p/user-exampleuser/fetch-from-foreign" => 'plain',
            "$p/user-exampleuser/post-from-foreign" => 'plain', "$f/to-own" => 'plain', "$p/user-exampleuser/via-foreign-redirect" => 'plain',
            "$p/public-otheruser/" => 'block', "$p/user-exampleuser/..%2Fuser-otheruser/" => 'block',
        ];
        check(count($answers) === count($want), 'paused requests: ' . count($answers));
        foreach ($answers as $a) {
            check(isset($want[$a[0]]), "unexpected request {$a[0]}");
            check(wgeAction($a[1]) === $want[$a[0]], "{$a[0]}: " . wgeAction($a[1]) . ", expected {$want[$a[0]]}");
        }
        $cancels = array_filter($sent, function ($c) {
            return $c['method'] === 'Fetch.continueWithAuth' && ((array) $c['params'])['authChallengeResponse']['response'] === 'CancelAuth';
        });
        check(count($cancels) === 3, 'every 401 challenge (3 in the session) must be cancelled, got ' . count($cancels));
        check(count($logs) === 4 && strpos(json_encode($logs), 'Basic') === false && strpos(json_encode($logs), 'http') === false,
            'blocked log: ' . json_encode($logs));
        $workerEnable = array_filter($sent, function ($c) {
            return $c['method'] === 'Fetch.enable' && isset($c['sessionId']) && strpos(json_encode($c), 'worker') !== false;
        });
        check(!$workerEnable, 'Fetch.enable sent to a dedicated worker, which has no Fetch domain');
    },
    'browse guard: a target runs only after its interception is on, and is closed when interception fails' => function () {
        $ev = wgeEvents();
        $start = $ev->start();
        check($start[0]['method'] === 'Target.setAutoAttach' && ((array) $start[0]['params'])['flatten'] === true
            && ((array) $start[0]['params'])['waitForDebuggerOnStart'] === true, 'start: ' . json_encode($start));
        $out = $ev->handle(wgePage());
        $methods = array_column($out['send'], 'method');
        check($methods === ['Fetch.enable', 'Page.enable', 'Target.setAutoAttach'], 'attach sent ' . json_encode($methods));
        $fe = (array) $out['send'][0]['params'];
        check($fe['patterns'] === [['urlPattern' => '*', 'requestStage' => 'Request']] && $fe['handleAuthRequests'] === true, 'Fetch.enable: ' . json_encode($fe));
        check(!$ev->ready(), 'ready before the attach answer');
        $ev->handle(['id' => $start[0]['id'], 'result' => []]);
        check(!$ev->ready(), 'ready before the existing target\'s interception was on');
        $ok = $ev->handle(['id' => $out['send'][0]['id'], 'result' => [], 'sessionId' => 'S1']);
        check($ok['send'][0]['method'] === 'Runtime.runIfWaitingForDebugger' && $ok['send'][0]['sessionId'] === 'S1', 'resume: ' . json_encode($ok));
        check($ev->ready() && !$ev->broken(), 'not ready after the initial target was guarded');
        $out = $ev->handle(wgePage('S2', 'T2', 'S1'));
        $bad = $ev->handle(['id' => $out['send'][0]['id'], 'error' => ['code' => -32000, 'message' => 'x'], 'sessionId' => 'S2']);
        check(count($bad['send']) === 1 && $bad['send'][0]['method'] === 'Target.closeTarget' && ((array) $bad['send'][0]['params'])['targetId'] === 'T2',
            'a target whose interception failed was not closed: ' . json_encode($bad));
        check($bad['log'][0]['action'] === 'close', 'the close was not logged');
        $w = $ev->handle(['method' => 'Target.attachedToTarget', 'sessionId' => 'S1', 'params' => ['sessionId' => 'W1',
            'targetInfo' => ['targetId' => 'TW', 'type' => 'worker', 'url' => ''], 'waitingForDebugger' => true]]);
        check(array_column($w['send'], 'method') === ['Target.setAutoAttach', 'Runtime.runIfWaitingForDebugger'], 'worker: ' . json_encode($w));
        $refused = wgeEvents();
        $s = $refused->start();
        $refused->handle(['id' => $s[0]['id'], 'error' => ['code' => -32000, 'message' => 'no']]);
        check($refused->broken() && !$refused->ready(), 'a refused attach was not reported as broken');
    },
    'browse guard: foreign initiators get no login, the page\'s own Authorization is replaced, and no login file means no login' => function () {
        $own = 'https://panel.example.com/user-exampleuser/';
        $nav = function ($url) {
            return ['method' => 'Page.frameNavigated', 'sessionId' => 'S1', 'params' => ['frame' => ['id' => 'T1', 'url' => $url]]];
        };
        $sub = function ($url, $type, $frame = 'T1', array $headers = ['Accept' => '*/*'], $method = 'GET') {
            return wgePaused($url, ['request' => ['url' => $url, 'method' => $method, 'headers' => $headers], 'frameId' => $frame, 'resourceType' => $type]);
        };
        list($a) = wgeRun(wgeEvents(), [wgePage(), $nav('https://www.example.org/'), $sub($own . 'img', 'Image'), $sub($own . 'api', 'Fetch'),
            $sub($own . 'post', 'Document', 'T1', [], 'POST'), $sub($own, 'Document')]);
        foreach ($a as $x) {
            check(wgeAction($x[1]) === 'plain', "foreign-page request {$x[0]} was " . wgeAction($x[1]));
        }
        $frameNav = ['method' => 'Page.frameNavigated', 'sessionId' => 'S1', 'params' => ['frame' => ['id' => 'F2', 'parentId' => 'T1', 'url' => 'https://ads.example.net/x']]];
        list($a) = wgeRun(wgeEvents(), [wgePage(), $nav($own), $frameNav, $sub($own . 'a', 'XHR', 'F2'), $sub($own . 'b', 'XHR', 'T1', ['Authorization' => 'Basic Zm9yZ2Vk']),
            $sub('https://www.example.org/x', 'Image', 'T1', ['authorization' => 'Basic Zm9yZ2Vk'])]);
        check(wgeAction($a[0][1]) === 'plain', 'a foreign iframe in the own page got the login');
        check(wgeAction($a[1][1]) === 'auth', 'the page\'s own Authorization was not replaced by the login');
        check(wgeAction($a[2][1]) === 'strip', 'a page-set Authorization to another site was not stripped');
        list($a) = wgeRun(wgeEvents([$own], null), [wgePage(), $sub($own, 'Document'), $nav($own), $sub($own . 'x', 'Script'),
            wgePaused('https://panel.example.com/user-otheruser/')]);
        check(wgeAction($a[0][1]) === 'plain' && wgeAction($a[1][1]) === 'plain', 'without a login file something got a login');
        check(wgeAction($a[2][1]) === 'block', 'without a login file the fence was off');
        $ev = wgeEvents();
        $c = $ev->handle(['method' => 'Fetch.authRequired', 'sessionId' => 'S1', 'params' => ['requestId' => 'q1', 'request' => ['url' => $own]]]);
        check($c['send'][0]['method'] === 'Fetch.continueWithAuth' && ((array) $c['send'][0]['params'])['authChallengeResponse'] === ['response' => 'CancelAuth'],
            'a challenge was not cancelled');
    },
    'browse guard: the WebSocket codec round-trips masked frames of every length form and waits for partial ones' => function () {
        foreach ([0, 5, 125, 126, 65535, 65536, 200000] as $n) {
            $data = str_repeat('x', $n);
            $buf = CdpSocket::frame($data, 0x1);
            $half = substr($buf, 0, (int) (strlen($buf) / 2));
            check(CdpSocket::parse($half) === [], "a partial frame of $n bytes parsed");
            $two = $buf . CdpSocket::frame('{}', 0x9);
            $f = CdpSocket::parse($two);
            check(count($f) === 2 && $f[0]['data'] === $data && $f[0]['op'] === 1 && $f[0]['fin'] && $f[1]['op'] === 9 && $two === '', "round trip of $n bytes failed");
        }
        $huge = chr(0x81) . chr(127) . pack('J', CdpSocket::MAX + 1);
        $f = CdpSocket::parse($huge);
        check(count($f) === 1 && $f[0]['op'] === 8, 'an oversized frame did not end the connection');
    },
];
