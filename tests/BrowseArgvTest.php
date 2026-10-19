<?php
/** tools/system/browse/BrowseArgv.php: what the agent-browser wrapper lets through, and how it rewrites it (ADR-0077). */
require_once dirname(__DIR__) . '/pikkumies/tools/system/browse/browse.inc.php';

return [
    'browse argv: every call is pinned to the one guarded session, whatever session Hermes names' => function () {
        $p = BrowseArgv::plan(['--session', 'h_9f3a', '--headed', '--engine', 'chrome', '--json', 'snapshot', '-i']);
        check($p['mode'] === 'run' && $p['json'] === true, json_encode($p));
        check($p['calls'] === [['argv' => ['--session', BrowsePaths::SESSION, '--json', 'snapshot', '-i'], 'stdin' => null, 'intent' => null]], json_encode($p['calls']));
        $p = BrowseArgv::plan(['--session=h_1', 'get', 'url']);
        check($p['calls'][0]['argv'] === ['--session', BrowsePaths::SESSION, 'get', 'url'], json_encode($p));
    },
    'browse argv: options that attach to or launch another browser are refused, before or after the command' => function () {
        foreach ([['--cdp', '9222', 'open', 'https://example.com'], ['--auto-connect', 'snapshot'], ['-p', 'browserbase', 'open', 'x'],
            ['--session', 's', 'open', 'https://example.com', '--profile', 'Default'], ['open', 'x', '--args=--remote-debugging-address=0.0.0.0'],
            ['--executable-path', '/tmp/x', 'open', 'x'], ['--config', '/tmp/c.json', 'open', 'x'], ['open', 'x', '--proxy', 'http://192.0.2.1:3128']] as $argv) {
            $p = BrowseArgv::plan($argv);
            check(isset($p['refuse']) && strpos($p['refuse'], 'leave the isolated browser') !== false, json_encode($argv) . ' => ' . json_encode($p));
        }
        $p = BrowseArgv::plan(['--made-up', 'open', 'x']);
        check(isset($p['refuse']) && strpos($p['refuse'], 'not passed') !== false, 'an unknown option before the command is refused: ' . json_encode($p));
        $p = BrowseArgv::plan(['--engine', 'lightpanda', 'open', 'x']);
        check(isset($p['refuse']), 'another engine is refused');
    },
    'browse argv: commands that leave the browser or the machine are refused' => function () {
        foreach (['connect', 'chat', 'dashboard', 'install', 'upgrade'] as $cmd) {
            $p = BrowseArgv::plan(['--json', $cmd, 'x']);
            check(isset($p['refuse']) && strpos($p['refuse'], $cmd) !== false, "$cmd: " . json_encode($p));
        }
    },
    'browse argv: tab new <url> becomes tab new, then open <url> with the URL as the intent' => function () {
        $p = BrowseArgv::plan(['--json', 'tab', 'new', 'https://example.com/user-exampleuser/']);
        check(count($p['calls']) === 2, json_encode($p));
        check($p['calls'][0] === ['argv' => ['--session', BrowsePaths::SESSION, '--json', 'tab', 'new'], 'stdin' => null, 'intent' => null], json_encode($p['calls'][0]));
        check($p['calls'][1]['argv'] === ['--session', BrowsePaths::SESSION, '--json', 'open', 'https://example.com/user-exampleuser/']
            && $p['calls'][1]['intent'] === 'https://example.com/user-exampleuser/', json_encode($p['calls'][1]));
        $p = BrowseArgv::plan(['tab', 'new']);
        check(count($p['calls']) === 1 && $p['calls'][0]['argv'] === ['--session', BrowsePaths::SESSION, 'tab', 'new'], 'a bare tab new passes as it is');
        $p = BrowseArgv::plan(['open', 'https://example.org/']);
        check($p['calls'][0]['intent'] === 'https://example.org/', 'an open carries its URL as the intent');
    },
    'browse argv: a batch on stdin is checked command by command, and its tab new <url> is split too' => function () {
        $p = BrowseArgv::plan(['--session', 'h', '--json', 'batch'], '[["tab","new","https://example.net/?q=a%20b"],["snapshot"]]');
        check($p['calls'][0]['argv'] === ['--session', BrowsePaths::SESSION, '--json', 'batch'], json_encode($p));
        check(json_decode($p['calls'][0]['stdin'], true) === [['tab', 'new'], ['open', 'https://example.net/?q=a%20b'], ['snapshot']], $p['calls'][0]['stdin']);
        check($p['calls'][0]['intent'] === 'https://example.net/?q=a%20b', 'the first open is the intent');
        foreach (['[["connect","9222"]]', '[["open","x","--cdp","1"]]', '[["close"]]', '{"a":1}', '[[1,2]]', 'not json'] as $stdin) {
            check(isset(BrowseArgv::plan(['--json', 'batch'], $stdin)['refuse']), "refused: $stdin");
        }
        check(isset(BrowseArgv::plan(['batch', 'open https://example.com', 'tab new https://example.org'])['refuse']), 'a tab new <url> in batch words cannot be split: refused');
        check(isset(BrowseArgv::plan(['batch', 'connect 9222'])['refuse']), 'a denied command in batch words is refused');
        $ok = BrowseArgv::plan(['batch', '--bail', 'open https://example.com', 'snapshot']);
        check(!isset($ok['refuse']) && $ok['calls'][0]['argv'] === ['--session', BrowsePaths::SESSION, 'batch', '--bail', 'open https://example.com', 'snapshot'], json_encode($ok));
    },
    'browse argv: close is answered without closing, --version and --help run the binary directly' => function () {
        check(BrowseArgv::plan(['--session', 'h', '--json', 'close'])['mode'] === 'close', 'close');
        check(BrowseArgv::plan(['close', '--all'])['mode'] === 'close', 'close --all');
        foreach ([['--version'], ['-V'], ['--help'], []] as $argv) {
            check(BrowseArgv::plan($argv)['mode'] === 'direct', json_encode($argv));
        }
        check(isset(BrowseArgv::plan(['--json'])['refuse']), 'options but no command');
    },
];
