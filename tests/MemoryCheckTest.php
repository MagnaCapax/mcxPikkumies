<?php
/**
 * ADR-0081: `save --tool` (tools/memory/pikkumies/Lessons.php) and hooks/search/memory-check/. Each test builds a throwaway
 * tree (home/, pikkumies/{memory,tools,local}) with one demo tool unit; most call memoryCheckAnswer() in this process with
 * HOME set to the tree's home, and one runs the hook script from a copy of lib/ and the unit.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/search/memory-check/memory-check.php';
require_once dirname(__DIR__) . '/pikkumies/tools/memory/pikkumies/Lessons.php';

if (!function_exists('memCheckTree')) {
    /** A tree with home/.democtl.rc, a demo unit (show no, restart yes, test "no (...)", wipe deletes) and an empty memory. */
    function memCheckTree()
    {
        $tmp = testTempDir('pk-memcheck');
        $unit = "$tmp/pikkumies/tools/demo/democtl";
        @mkdir($unit, 0700, true);
        @mkdir("$tmp/pikkumies/memory/lessons/demo", 0700, true);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        @mkdir("$tmp/home", 0700, true);
        file_put_contents("$tmp/home/.democtl.rc", "x\n");
        file_put_contents("$unit/skill.md", "---\nname: democtl\ndescription: \"A demo unit.\"\n---\nDemo.\n");
        file_put_contents("$unit/README.md", "# democtl\n");
        file_put_contents("$unit/democtl.php", "<?php\n");
        file_put_contents("$unit/USAGE.md", "# democtl\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| show | | no | Shows. |\n"
            . "| restart | | yes | Restarts. |\n| test | | no (contacts the trackers) | Tests. |\n| wipe | | deletes | Wipes. |\n");
        return $tmp;
    }

    /** A memory in the tree linked to $tools (real paths). */
    function memCheckMemory($tree, $slug, $title, array $tools)
    {
        file_put_contents("$tree/pikkumies/memory/lessons/demo/20261019-$slug.md", "---\ndate: 2026-10-19\ntopic: demo\nsources: [\"test\"]\ntools: "
            . json_encode($tools, JSON_UNESCAPED_SLASHES) . "\n---\n# $title\n\nBody.\n");
    }

    function memCheckCall($tool, array $input, $session = 's1')
    {
        return ['tool_name' => $tool, 'tool_input' => $input, 'session_id' => $session];
    }

    function memCheckAnswer($tree, array $payload, array $config = [])
    {
        return testWithEnv(['HOME' => "$tree/home"], function () use ($tree, $payload, $config) {
            return memoryCheckAnswer($payload, $config, "$tree/pikkumies", "$tree/pikkumies/local");
        });
    }
}

return [
    'memory-check: save --tool writes the real paths into the frontmatter, refuses a path that is no file, and without --tool writes no tools line' => function () {
        $tmp = testTempDir('pk-memcheck-save');
        mkdir("$tmp/memory/lessons/demo", 0700, true);
        file_put_contents("$tmp/memory/lessons/demo/20261001-old.md", "---\ndate: 2026-10-01\ntopic: demo\nsources: [\"x\"]\n---\n# Old\n\nOld.\n");
        file_put_contents("$tmp/app.rc", "x\n");
        DocSources::$root = $tmp;
        DocSources::$host = ['family' => 'generic', 'stack' => 'none'];
        try {
            $body = "See [old]($tmp/memory/lessons/demo/20261001-old.md).\n";
            $r = Lessons::save(['topic' => 'demo', 'title' => 'App rc wants tabs', 'sources' => 'test', 'tool' => "$tmp/app.rc; $tmp/./app.rc"], $body);
            $text = (string) file_get_contents($r['records'][0]['path']);
            check(strpos($text, "\ntools: [\"" . realpath("$tmp/app.rc") . "\"]\n---\n") !== false, 'tools line: ' . $text);
            check($r['records'][0]['tools'] === [realpath("$tmp/app.rc")], 'record: ' . json_encode($r['records'][0]));
            $r2 = Lessons::save(['topic' => 'demo', 'title' => 'No tool here', 'sources' => 'test'], $body);
            check(strpos((string) file_get_contents($r2['records'][0]['path']), 'tools:') === false && !isset($r2['records'][0]['tools']), 'no --tool, no tools line');
            try {
                Lessons::save(['topic' => 'demo', 'title' => 'Missing tool', 'sources' => 'test', 'tool' => "$tmp/nope.rc"], $body);
                check(false, 'a missing --tool file must be refused');
            } catch (CliError $e) {
                check(strpos($e->getMessage(), '--tool names no file') === 0, 'message: ' . $e->getMessage());
            }
            check(!glob("$tmp/memory/lessons/demo/*missing-tool*"), 'nothing written for a refused --tool');
        } finally {
            DocSources::$root = null;
            DocSources::$host = null;
            testRmTree($tmp);
        }
    },
    'memory-check: a call naming a linked file is held once with the memory path and title; the identical call then proceeds, another tool is held once more' => function () {
        $tree = memCheckTree();
        try {
            memCheckMemory($tree, 'tabs', 'democtl rc wants tabs', [realpath("$tree/home/.democtl.rc")]);
            $call = memCheckCall('terminal', ['command' => 'cat ~/.democtl.rc']);
            $a = memCheckAnswer($tree, $call);
            check(is_array($a) && $a['action'] === 'block', 'not held: ' . json_encode($a));
            check(strpos($a['message'], '~/.democtl.rc') !== false && strpos($a['message'], 'democtl rc wants tabs') !== false
                && strpos($a['message'], '20261019-tabs.md') !== false && strpos($a['message'], 'search "') !== false
                && strpos($a['message'], 'running it again proceeds') !== false, 'message: ' . $a['message']);
            check(memCheckAnswer($tree, $call) === null, 'the identical re-run must proceed');
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => 'cd ~ && grep x .democtl.rc'])) === null, 'same tool, same target: proceeds');
            $read = memCheckAnswer($tree, memCheckCall('read_file', ['path' => "$tree/home/.democtl.rc"]));
            check(is_array($read) && $read['action'] === 'block', 'another tool on the linked file is held once: ' . json_encode($read));
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => 'cat ~/.democtl.rc'], 's2')) !== null, 'a new session is held again');
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => 'cat ~/x.democtl.rc.bak'])) === null, 'a longer name is not the linked file');
        } finally {
            testRmTree($tree);
        }
    },
    'memory-check: a Pikkumies verb whose changes is not no is held (yes, deletes, through X=path.php), a no verb and "no (...)" are not' => function () {
        $tree = memCheckTree();
        try {
            $p = "$tree/pikkumies/tools/demo/democtl/democtl.php";
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => "php $p show"])) === null, 'a no verb is not held');
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => "php $p test"])) === null, '"no (contacts the trackers)" is not held');
            $a = memCheckAnswer($tree, memCheckCall('terminal', ['command' => "php $p --human restart"]));
            check(is_array($a) && strpos($a['message'], 'democtl restart') !== false && strpos($a['message'], 'No memory is linked to it.') !== false, 'restart: ' . json_encode($a));
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => "php $p restart"])) === null, 'restart again proceeds');
            $w = memCheckAnswer($tree, memCheckCall('terminal', ['command' => "D=$p\nphp \$D wipe"]));
            check(is_array($w) && strpos($w['message'], 'democtl wipe') !== false, 'X=path.php then php $X wipe: ' . json_encode($w));
            memCheckMemory($tree, 'unit', 'democtl restart needs the lock', [realpath($p)]);
            $s = memCheckAnswer($tree, memCheckCall('execute_code', ['code' => "system('php $p restart');"]));
            check(is_array($s) && strpos($s['message'], 'democtl restart needs the lock') !== false, 'a memory linked to the unit script is shown: ' . json_encode($s));
        } finally {
            testRmTree($tree);
        }
    },
    'memory-check: a Hermes tool that changes is held per target; quiet tools, reading actions, terminal and web research are not' => function () {
        $tree = memCheckTree();
        try {
            $w = memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a.txt', 'content' => 'x']));
            check(is_array($w) && strpos($w['message'], 'write_file /tmp/a.txt') !== false, 'write_file: ' . json_encode($w));
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a.txt', 'content' => 'y'])) === null, 'same path again proceeds');
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/b.txt', 'content' => 'y'])) !== null, 'a new path is held');
            $v4a = memCheckAnswer($tree, memCheckCall('patch', ['mode' => 'patch', 'patch' => "*** Begin Patch\n*** Update File: /tmp/c.txt\n@@\n-a\n+b\n*** End Patch\n"]));
            check(is_array($v4a) && strpos($v4a['message'], 'patch /tmp/c.txt') !== false, 'V4A patch by its header: ' . json_encode($v4a));
            check(memCheckAnswer($tree, memCheckCall('cronjob_manage', ['action' => 'list'])) === null, 'cronjob list only reads');
            $c = memCheckAnswer($tree, memCheckCall('cronjob_manage', ['action' => 'create', 'name' => 'nightly', 'schedule' => '0 3 * * *', 'prompt' => 'x']));
            check(is_array($c) && strpos($c['message'], 'cronjob_manage create nightly') !== false, 'cronjob create: ' . json_encode($c));
            foreach ([['read_file', ['path' => '/tmp/a.txt']], ['terminal', ['command' => 'touch /tmp/z']], ['web_search', ['query' => 'x']], ['process_manage', ['action' => 'poll', 'session_id' => 'p1']]] as $q) {
                check(memCheckAnswer($tree, memCheckCall($q[0], $q[1])) === null, "{$q[0]} must not be held");
            }
            check(memCheckAnswer($tree, memCheckCall('process_manage', ['action' => 'kill', 'session_id' => 'p1'])) !== null, 'process kill is held');
        } finally {
            testRmTree($tree);
        }
    },
    'memory-check: never held for the memory unit, without a session id, when disabled, or when the marker cannot be written' => function () {
        $tree = memCheckTree();
        try {
            memCheckMemory($tree, 'tabs', 'democtl rc wants tabs', [realpath("$tree/home/.democtl.rc")]);
            $search = 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php save --topic demo --title t --sources s --tool ~/.democtl.rc --body-file /tmp/b.md';
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => $search])) === null, 'a memory save naming a linked file must never be held');
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a'], '')) === null, 'no session id: no hold');
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a'], '../x')) === null, 'a session id that is not plain: no hold');
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a']), ['memoryCheck' => ['disabled' => true]]) === null, 'disabled: no hold');
            file_put_contents("$tree/pikkumies/local/state", 'a file where the directory should be');
            check(memCheckAnswer($tree, memCheckCall('write_file', ['path' => '/tmp/a'])) === null, 'unwritable marker: no hold');
        } finally {
            testRmTree($tree);
        }
    },
    'memory-check: at most 5 memories and 5 targets are named, then "and N more"; a title is one cleaned line' => function () {
        $tree = memCheckTree();
        try {
            $rc = realpath("$tree/home/.democtl.rc");
            for ($i = 1; $i <= 7; $i++) {
                memCheckMemory($tree, "m$i", "note $i" . ($i === 1 ? "\x07 with " . str_repeat('long ', 40) : ''), [$rc]);
            }
            $a = memCheckAnswer($tree, memCheckCall('terminal', ['command' => 'cat ~/.democtl.rc']));
            check(substr_count($a['message'], "\n- ") === 6 && strpos($a['message'], '- and 2 more') !== false, 'capped list: ' . $a['message']);
            check(strpos($a['message'], "\x07") === false && strpos($a['message'], '...') !== false, 'title cleaned and cut: ' . $a['message']);
            $p = "$tree/pikkumies/tools/demo/democtl/democtl.php";
            $cmd = implode('; ', array_map(function ($n) use ($p) {
                return "php $p restart > /tmp/o$n";
            }, range(1, 7)));
            $w = memCheckAnswer($tree, memCheckCall('patch', ['patch' => implode("\n", array_map(function ($n) {
                return "*** Add File: /tmp/f$n";
            }, range(1, 7)))]));
            check(is_array($w) && strpos($w['message'], 'and 2 more.') !== false, 'targets capped: ' . $w['message']);
            check(memCheckAnswer($tree, memCheckCall('terminal', ['command' => $cmd])) !== null, 'chained verb calls are held');
        } finally {
            testRmTree($tree);
        }
    },
    'memory-check: the hook script, run as the orchestrator runs it, holds once and then prints nothing' => function () {
        $tree = memCheckTree();
        try {
            @mkdir("$tree/pikkumies/lib", 0700, true);
            @mkdir("$tree/pikkumies/hooks/search/memory-check", 0700, true);
            foreach ((array) glob(dirname(__DIR__) . '/pikkumies/lib/*.php') as $f) {
                copy($f, "$tree/pikkumies/lib/" . basename($f));
            }
            foreach ((array) glob(dirname(__DIR__) . '/pikkumies/hooks/search/memory-check/*.*') as $f) {
                copy($f, "$tree/pikkumies/hooks/search/memory-check/" . basename($f));
            }
            $script = "$tree/pikkumies/hooks/search/memory-check/memory-check.php";
            $stdin = json_encode(memCheckCall('write_file', ['path' => '/tmp/a.txt', 'content' => 'x']));
            list(, $out) = testWithEnv(['HOME' => "$tree/home"], function () use ($script, $stdin) {
                return runPhp($script, [], $stdin);
            });
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'write_file /tmp/a.txt') !== false, 'first run: ' . $out);
            check(count((array) glob("$tree/pikkumies/local/state/memory-check/*")) === 1, 'one marker written');
            list(, $again) = testWithEnv(['HOME' => "$tree/home"], function () use ($script, $stdin) {
                return runPhp($script, [], $stdin);
            });
            check(trim($again) === '', 'second run must print nothing: ' . $again);
        } finally {
            testRmTree($tree);
        }
    },
];
