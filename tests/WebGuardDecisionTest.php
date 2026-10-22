<?php
/**
 * The browse guard's pure rules: WebGuardDecision (which request gets the panel login, which is blocked, which goes out
 * stripped) and WebGuardIntent (which navigation the CLI asked for). No browser, no socket.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/WebGuardDecision.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/WebGuardIntent.php';

/** decide() for one request on the fixture panel host, as the account exampleuser. */
function wgdDecide($url, array $more = [])
{
    return WebGuardDecision::decide($more + ['url' => $url, 'method' => 'GET', 'type' => 'Document', 'top' => null, 'frame' => null,
        'mainNav' => false, 'cli' => false], 'exampleuser', ['panel.example.com']);
}

return [
    'browse guard: the decision table, own page, CLI navigation, foreign initiators, other tenants and other sites' => function () {
        $p = 'https://panel.example.com';
        $own = "$p/user-exampleuser/rutorrent/";
        $foreign = 'https://www.example.org/page';
        $cases = [
            // [url, extra request fields, expected action, what it shows]
            ["$p/user-exampleuser/", ['mainNav' => true, 'cli' => true], 'auth', 'the CLI opens the own panel'],
            ["$p/public-exampleuser/sonarr/", ['mainNav' => true, 'cli' => true], 'strip', 'an own public app asks no login: the panel password never goes there'],
            ["$p/user-exampleuser/", ['mainNav' => true], 'strip', 'a navigation nobody asked for, from a blank tab'],
            ["$p/user-exampleuser/", ['mainNav' => true, 'cli' => true, 'method' => 'POST'], 'strip', 'the CLI never navigates with POST'],
            ["$p/user-exampleuser/x.js", ['type' => 'Script', 'top' => $own, 'frame' => $own], 'auth', 'the own page loads its own script'],
            ["$p/public-exampleuser/sonarr/api", ['type' => 'XHR', 'top' => "$p/public-exampleuser/sonarr/", 'frame' => "$p/public-exampleuser/sonarr/"], 'strip', 'an own public app calls its API without the panel login'],
            ["$p/user-exampleuser/api", ['type' => 'XHR', 'top' => "$p/public-exampleuser/app/", 'frame' => "$p/public-exampleuser/app/"], 'strip', 'a third-party app on the own public path cannot use the panel login'],
            ["$p/user-exampleuser/img", ['type' => 'Image', 'top' => $foreign, 'frame' => $foreign], 'strip', 'a foreign page\'s img'],
            ["$p/user-exampleuser/api", ['type' => 'Fetch', 'top' => $foreign, 'frame' => $foreign], 'strip', 'a foreign page\'s fetch'],
            ["$p/user-exampleuser/post", ['method' => 'POST', 'mainNav' => true, 'top' => $foreign, 'frame' => $foreign], 'strip', 'a foreign page\'s form POST'],
            ["$p/user-exampleuser/api", ['type' => 'XHR', 'top' => $own, 'frame' => 'https://ads.example.net/frame'], 'strip', 'a foreign iframe inside the own page'],
            ["$p/user-exampleuser/frame", ['type' => 'Document', 'top' => $foreign, 'frame' => $own], 'strip', 'the own panel framed by a foreign page'],
            ["$p/user-exampleuser/x", ['top' => "$p/public-otheruser/", 'frame' => "$p/public-otheruser/"], 'strip', 'a neighbour\'s page, were it ever loaded'],
            ["$p/user-otheruser/", ['mainNav' => true, 'cli' => true, 'top' => $own, 'frame' => $own], 'block', 'another account\'s panel, even from the own page'],
            ["$p/public-otheruser/", ['mainNav' => true, 'cli' => true], 'block', 'another account\'s public folder'],
            ["$p/~otheruser/", [], 'block', 'another account\'s ~ alias'],
            ["$p/webdav-otheruser/", [], 'block', 'another account\'s WebDAV'],
            ["$p/deluge-otheruser/", [], 'block', 'another account\'s legacy Deluge path'],
            ["$p/user-exampleuser2/", ['top' => $own, 'frame' => $own], 'block', 'a name that only starts with the own name'],
            ["$p/user-exampleuserx", [], 'block', 'the own name with a suffix, no slash'],
            ["$p/USER-exampleuser/", [], 'block', 'the own prefix in other case (nginx locations are case-sensitive)'],
            ["$p/", [], 'block', 'the panel root'],
            ["$p/401_images/a.png", [], 'block', 'shared files on the panel host'],
            ["https://PANEL.example.com./user-otheruser/", [], 'block', 'the panel host in other case with a trailing dot'],
            ["http://panel.example.com:8080/user-otheruser/", [], 'block', 'the panel host on another scheme and port'],
            ["wss://panel.example.com/user-otheruser/ws", [], 'block', 'a WebSocket to another account'],
            ["$p/~exampleuser/", [], 'strip', 'the own ~ alias takes no login'],
            ["$p/webdav-exampleuser/", ['top' => $own, 'frame' => $own], 'strip', 'the own WebDAV gets no login from the browser'],
            ["https://www.example.org/user-otheruser/", ['top' => $own, 'frame' => $own], 'strip', 'another site\'s /user- path is just a path'],
            ['http://192.0.2.10/user-exampleuser/', ['top' => $own, 'frame' => $own], 'strip', 'the panel by address is not the panel host'],
            ['data:text/html,hi', [], 'strip', 'a data URL'],
            ["$p/user-exampleuser/next", ['redirect' => true], 'auth', 'a redirect of a request that carried the login'],
            ["$p/user-exampleuser/next", ['redirect' => false, 'mainNav' => true, 'cli' => true, 'top' => $own, 'frame' => $own], 'strip', 'a foreign site\'s redirect to the own panel'],
            ["$p/user-otheruser/", ['redirect' => true], 'block', 'a redirect to another account'],
        ];
        foreach ($cases as $c) {
            $d = wgdDecide($c[0], $c[1]);
            check($d['action'] === $c[2], "{$c[3]}: {$c[0]} gave {$d['action']} ({$d['reason']}), expected {$c[2]}");
        }
    },
    'browse guard: traversal, backslash, double encoding and control characters on the panel host are blocked' => function () {
        $own = 'https://panel.example.com/user-exampleuser/';
        foreach (['../user-otheruser/', '%2e%2e/user-otheruser/', '%2E%2E%2Fuser-otheruser/', '..%2Fuser-otheruser/', '.%2E/x', 'a/./b',
            '.', '%252e%252e/user-otheruser/', '%2525/x', 'a\\b', 'a%5cb', 'a%5Cb', 'a%00b', 'a%0d%0ab', '%2f..%2f..%2fuser-otheruser/'] as $tail) {
            $d = wgdDecide($own . $tail, ['mainNav' => true, 'cli' => true, 'top' => $own, 'frame' => $own]);
            check($d['action'] === 'block', "$tail gave {$d['action']}");
        }
        // the same tails as a page URL never make a page count as the account's own
        $d = wgdDecide($own . 'x', ['top' => $own . '%2e%2e/public-otheruser/', 'frame' => $own . '%2e%2e/public-otheruser/']);
        check($d['action'] === 'strip', 'a traversing page URL counted as the own page');
        // plain encodings that decode to an ordinary own path stay own
        check(wgdDecide($own . 'a%20b', ['top' => $own, 'frame' => $own])['action'] === 'auth', 'a %20 in an own path was refused');
    },
    'browse guard: the logged prefix is the first path segment only, never a query string, and no name means nothing is own' => function () {
        $d = wgdDecide('https://panel.example.com/user-otheruser/deep/path?token=abc#frag');
        check($d['prefix'] === '/user-otheruser/', 'prefix: ' . var_export($d['prefix'], true));
        $d = wgdDecide('https://panel.example.com/?token=abc');
        check($d['prefix'] === '/' && $d['action'] === 'block', 'root prefix: ' . var_export($d, true));
        foreach (['', '-x', 'a/b', '../x'] as $bad) {
            $d = WebGuardDecision::decide(['url' => "https://panel.example.com/user-$bad/", 'method' => 'GET', 'mainNav' => true, 'cli' => true],
                $bad, ['panel.example.com']);
            check($d['action'] === 'block', "self '$bad' made a path own");
        }
    },
    'browse guard: the CLI intent file is consumed once by the same URL, and refused when stale, foreign, a symlink or writable by others' => function () {
        $dir = testTempDir('pk-webintent');
        try {
            $f = "$dir/intent";
            $now = time();
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            file_put_contents($f, "https://Panel.Example.com:443/user-exampleuser/?a=1#top\n");
            chmod($f, 0600);
            check(!WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/other', $now, $uid), 'another URL matched');
            check(is_file($f), 'a mismatch consumed the intent');
            check(WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/?a=1', $now, $uid), 'the same URL did not match');
            check(!is_file($f) && !WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/?a=1', $now, $uid), 'the intent matched twice');
            file_put_contents($f, "https://panel.example.com/user-exampleuser/\n");
            chmod($f, 0600);
            check(!WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/', $now, $uid + 1), 'another account\'s file was trusted');
            chmod($f, 0620);
            check(!WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/', $now, $uid), 'a group-writable file was trusted');
            chmod($f, 0600);
            check(!WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/', $now + WebGuardIntent::TTL + 5, $uid), 'a stale intent matched');
            check(!is_file($f), 'a stale intent was left behind');
            file_put_contents("$dir/real", "https://panel.example.com/user-exampleuser/\n");
            symlink("$dir/real", $f);
            check(!WebGuardIntent::take($f, 'https://panel.example.com/user-exampleuser/', $now, $uid), 'a symlink was followed');
            check(!WebGuardIntent::take("$dir/missing", 'https://panel.example.com/', $now, $uid) && !WebGuardIntent::take('', 'x', $now, $uid), 'no file matched');
            check(WebGuardIntent::canonical('not a url') === null, 'a non-URL got a canonical form');
        } finally {
            testRmTree($dir);
        }
    },
];
