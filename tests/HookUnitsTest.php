<?php
/**
 * lib/HookUnits.php (reading hook.md, ordering, the index), HermesConfig::hookEntries, and the edges of
 * the search-first units (ADR-0028, ADR-0030).
 */
require_once __DIR__ . '/hookSearchSupport.php';
if (!function_exists('hookUnitsAdd')) {
    /** hook.md text for $name; $over replaces or (with null) drops frontmatter lines, extra keys are appended. */
    function hookUnitsMd($name, array $over = [])
    {
        $fields = array_merge(['name' => $name, 'event' => 'pre_tool_call', 'description' => 'fixture', 'failClosed' => 'false', 'timeout' => '5'], $over);
        $md = "---\n";
        foreach ($fields as $k => $v) {
            $md .= $v === null ? '' : "$k: $v\n";
        }
        return $md . "---\nbody\n";
    }

    /** A unit directory hooks/<category>/<name>/ with hook.md ($md null = none), README.md and <name>.php. */
    function hookUnitsAdd($hooksDir, $id, $md)
    {
        $dir = "$hooksDir/$id";
        $name = basename($id);
        @mkdir($dir, 0700, true);
        if ($md !== null) {
            file_put_contents("$dir/hook.md", $md);
        }
        file_put_contents("$dir/README.md", "# $name\n");
        file_put_contents("$dir/$name.php", "<?php\n");
    }

    /** [category/name => errors] of HookUnits::all(). */
    function hookUnitsErrors($hooksDir)
    {
        $out = [];
        foreach (HookUnits::all($hooksDir) as $u) {
            $out[$u['category'] . '/' . $u['name']] = $u['errors'];
        }
        return $out;
    }

    /**
     * hookSearchFakeProvider, but sure the server is ours: when its port was taken, php -S exits and the
     * connect check reached someone else's listener (seen with tests running side by side).
     */
    function hookUnitsProvider($dir)
    {
        for ($try = 0; $try < 5; $try++) {
            list($proc, $port) = hookSearchFakeProvider($dir);
            usleep(100000);
            if (proc_get_status($proc)['running']) {
                return [$proc, $port];
            }
            proc_close($proc);
        }
        throw new Exception('fake provider did not start');
    }

    function hookUnitsIds(array $units)
    {
        return array_map(function ($u) {
            return $u['category'] . '/' . $u['name'];
        }, $units);
    }
}

return [
    'hook units: a CRLF hook.md reads the same as LF; timeout 1 and 60 pass, 0 and 61 do not' => function () {
        $lf = hookUnitsMd('x', ['description' => '"quoted: yes"']);
        check(HookUnits::frontmatter(str_replace("\n", "\r\n", $lf)) === HookUnits::frontmatter($lf), 'CRLF read differently');
        $dir = testTempDir('pk-hu');
        try {
            foreach (['0', '1', '60', '61'] as $t) {
                hookUnitsAdd($dir, "t/t$t", hookUnitsMd("t$t", ['timeout' => $t]));
            }
            $e = hookUnitsErrors($dir);
            check($e['t/t1'] === [] && $e['t/t60'] === [], 'timeouts 1 and 60 refused: ' . json_encode($e));
            check($e['t/t0'] === ['timeout must be whole seconds from 1 to 60'] && $e['t/t61'] === ['timeout must be whole seconds from 1 to 60'], 'timeouts 0 and 61 accepted: ' . json_encode($e));
        } finally {
            testRmTree($dir);
        }
    },
    'hook units: failClosed TRUE, an extra key and a name that is not the directory are errors, and the unit is still listed' => function () {
        $dir = testTempDir('pk-hu');
        try {
            hookUnitsAdd($dir, 'k/upper', hookUnitsMd('upper', ['failClosed' => 'TRUE']));
            hookUnitsAdd($dir, 'k/extra', hookUnitsMd('extra', ['owner' => 'me']));
            hookUnitsAdd($dir, 'k/renamed', hookUnitsMd('other-name'));
            $frontmatter = 'hook.md frontmatter must be exactly name (= directory), event, description, failClosed and timeout';
            $e = hookUnitsErrors($dir);
            check(array_keys($e) === ['k/extra', 'k/renamed', 'k/upper'], 'units not all listed: ' . json_encode(array_keys($e)));
            check($e['k/upper'] === ['failClosed must be true or false'] && $e['k/extra'] === [$frontmatter] && $e['k/renamed'] === [$frontmatter], 'errors: ' . json_encode($e));
            $upper = HookUnits::all($dir)[2];
            check($upper['failClosed'] === false, 'failClosed TRUE read as true');
        } finally {
            testRmTree($dir);
        }
    },
    'hook units: hook.md without delimiters or missing gives every error, never a dropped unit; such a unit joins pre_tool_call' => function () {
        $dir = testTempDir('pk-hu');
        try {
            hookUnitsAdd($dir, 'b/plain', "name: plain\nevent: pre_tool_call\n");
            hookUnitsAdd($dir, 'b/none', null);
            hookUnitsAdd($dir, 'c/ctx', hookUnitsMd('ctx', ['event' => 'pre_llm_call']));
            $e = hookUnitsErrors($dir);
            check(count($e['b/plain']) === 5, 'plain-text hook.md: ' . json_encode($e['b/plain']));
            check(count($e['b/none']) === 6 && in_array('hook.md, README.md and none.php are required', $e['b/none'], true), 'missing hook.md: ' . json_encode($e['b/none']));
            check(hookUnitsIds(HookUnits::forEvent('pre_tool_call', $dir)) === ['b/none', 'b/plain'], 'broken units must run on pre_tool_call');
            check(hookUnitsIds(HookUnits::forEvent('pre_llm_call', $dir)) === ['c/ctx'], 'a broken unit joined pre_llm_call');
        } finally {
            testRmTree($dir);
        }
    },
    'hook units: same-named units in two categories run in category order; byEvent lists only events that have units' => function () {
        $dir = testTempDir('pk-hu');
        try {
            foreach (['b/same', 'a/same', 'z/alpha', 'a/omega'] as $id) {
                hookUnitsAdd($dir, $id, hookUnitsMd(basename($id), ['event' => 'pre_llm_call']));
            }
            check(hookUnitsIds(HookUnits::forEvent('pre_llm_call', $dir)) === ['z/alpha', 'a/omega', 'a/same', 'b/same'], 'order: ' . json_encode(hookUnitsIds(HookUnits::forEvent('pre_llm_call', $dir))));
            check(array_keys(HookUnits::byEvent($dir)) === ['pre_llm_call'], 'an event without units got a key');
        } finally {
            testRmTree($dir);
        }
    },
    'hook units: the index has one heading per category in first-seen order and keeps descriptions verbatim' => function () {
        $unit = function ($category, $name, $closed, $description) {
            return ['category' => $category, 'name' => $name, 'dir' => "/h/$category/$name", 'event' => 'pre_tool_call', 'failClosed' => $closed, 'timeout' => 3, 'meta' => ['description' => $description]];
        };
        $index = HookUnits::renderIndex([$unit('zeta', 'one', true, 'Uses *stars*, _under_ and [a](b) | #hash.'), $unit('zeta', 'two', false, 'Two.'), $unit('alpha', 'three', false, 'Three.'), $unit('mid', 'four', false, 'Four.')]);
        $body = substr($index, strpos($index, "\n## "));
        check($body === "\n## zeta\n- **one** (`/h/zeta/one`): pre_tool_call, fail closed, 3s. Uses *stars*, _under_ and [a](b) | #hash.\n- **two** (`/h/zeta/two`): pre_tool_call, fail open, 3s. Two.\n"
            . "\n## alpha\n- **three** (`/h/alpha/three`): pre_tool_call, fail open, 3s. Three.\n\n## mid\n- **four** (`/h/mid/four`): pre_tool_call, fail open, 3s. Four.\n", 'index: ' . $index);
    },
    'hook entries: the summed timeout is clamped to 300; an errored unit\'s timeout still counts' => function () {
        $dir = testTempDir('pk-hu');
        try {
            foreach ([60, 60, 60, 60, 57 - AskReview::TIMEOUT] as $i => $t) { // plus 2, plus AskReview::TIMEOUT on pre_tool_call (ADR-0073)
                hookUnitsAdd($dir, "s/u$i", hookUnitsMd("u$i", ['timeout' => (string) $t]));
            }
            check(HermesConfig::hookEntries($dir)['pre_tool_call']['timeout'] === 299, 'unclamped: the units, plus 2, plus the review, is 299');
            unlink("$dir/s/u4/hook.md");
            hookUnitsAdd($dir, 's/u4', hookUnitsMd('u4', ['timeout' => (string) (59 - AskReview::TIMEOUT)]));
            check(HermesConfig::hookEntries($dir)['pre_tool_call']['timeout'] === 300, 'a sum of 301 not clamped to 300');
        } finally {
            testRmTree($dir);
        }
        $dir = testTempDir('pk-hu');
        try {
            hookUnitsAdd($dir, 'e/ok', hookUnitsMd('ok', ['timeout' => '10']));
            hookUnitsAdd($dir, 'e/bad', hookUnitsMd('bad', ['timeout' => '20', 'failClosed' => 'yes']));
            check(HermesConfig::hookEntries($dir)['pre_tool_call']['timeout'] === 10 + 20 + 2 + AskReview::TIMEOUT, 'errored unit timeout not counted (10 + 20 + 2 + the review): ' . json_encode(HermesConfig::hookEntries($dir)));
        } finally {
            testRmTree($dir);
        }
    },
    'hook entries: pre_tool_call always fails closed, also when its hook.md files say otherwise; pre_llm_call never does' => function () {
        $dir = testTempDir('pk-hu');
        try {
            hookUnitsAdd($dir, 'g/open', hookUnitsMd('open'));
            hookUnitsAdd($dir, 'c/closed-ctx', hookUnitsMd('closed-ctx', ['event' => 'pre_llm_call', 'failClosed' => 'true']));
            $e = HermesConfig::hookEntries($dir);
            check($e['pre_tool_call']['failClosed'] === true && $e['pre_llm_call']['failClosed'] === false, 'entries: ' . json_encode($e));
            check(strpos($e['pre_tool_call']['command'], PHP_BINARY . ' -d display_errors=stdout -d error_reporting=4437 ') === 0, 'command: ' . $e['pre_tool_call']['command']);
        } finally {
            testRmTree($dir);
        }
        // The guard's hook.md mangled into another event: the pre_tool_call entry must not vanish.
        $dir = testTempDir('pk-hu');
        try {
            hookUnitsAdd($dir, 'g/guard', hookUnitsMd('guard', ['event' => 'pre-tool-call', 'failClosed' => 'true']));
            hookUnitsAdd($dir, 'c/ctx', hookUnitsMd('ctx', ['event' => 'pre_llm_call']));
            $e = HermesConfig::hookEntries($dir);
            check(isset($e['pre_tool_call']) && $e['pre_tool_call']['failClosed'] === true, 'a broken guard dropped the pre_tool_call entry: ' . json_encode($e));
        } finally {
            testRmTree($dir);
        }
    },
    'search gate: every read-only tool passes before any search, and a marker as new as the re-arm keeps the gate open' => function () {
        $tree = hookSearchTree([]);
        try {
            foreach (['read_file', 'search_files', 'skill_view', 'skills_list', 'memory', 'clarify', 'todo', 'todo_list', 'session_search', 'read_terminal'] as $tool) {
                check(!hookSearchGateBlocks($tree, 'r1', $tool), "read-only $tool blocked");
            }
            check(hookSearchGateBlocks($tree, 'r1', 'patch'), 'an acting tool passed before any search');
            $marker = "$tree/pikkumies/local/state/search-first/" . sha1('r2');
            @mkdir(dirname($marker), 0700, true);
            $t = time() - 100;
            touch($marker, $t);
            touch("$marker.armed", $t);
            check(!hookSearchGateBlocks($tree, 'r2', 'write_file'), 'a search as new as the re-arm closed the gate');
            touch("$marker.armed", $t + 1);
            check(hookSearchGateBlocks($tree, 'r2', 'write_file'), 'a re-arm newer than the search left the gate open');
        } finally {
            testRmTree($tree);
        }
    },
    'search gate: an acting tool with no tool_input at all is blocked cleanly' => function () {
        $tree = hookSearchTree([]);
        try {
            list($code, $out) = runPhp("$tree/pikkumies/hooks/search/search-first-gate/search-first-gate.php", [], json_encode(['session_id' => 'n1', 'tool_name' => 'write_file']));
            $d = json_decode($out, true);
            check($code === 0 && is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'search first') !== false, 'no tool_input: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
    'search-first: a page sharing exactly half of the message words is listed, one sharing a quarter is not' => function () {
        $tree = hookSearchTree(['alpha' => ['Alpha page', 'zebra yak'], 'beta' => ['Beta page', 'walrus'], 'gamma' => ['Gamma page', 'narwhal']]);
        try {
            $c = hookSearchRun($tree, hookSearchTurn('h1', 'zebra yak walrus narwhal', true));
            check(strpos($c, 'kb: Alpha page') !== false && strpos($c, 'Beta page') === false && strpos($c, 'Gamma page') === false, 'half-share hits: ' . $c);
        } finally {
            testRmTree($tree);
        }
    },
    'search-first: a later turn starting with a question word but no question mark still gets the reword step' => function () {
        $tree = hookSearchTree(['quota' => ['Quota and storage', 'How the disk quota and storage limit work on a seedbox.']]);
        $fake = "$tree/fake";
        @mkdir($fake, 0700);
        list($proc, $port) = hookUnitsProvider($fake);
        try {
            file_put_contents("$tree/key", "key=k\n");
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' => ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'acme/m', 'keyFile' => "$tree/key"]], true) . ";\n");
            file_put_contents("$fake/reply", 'disk quota');
            $c = hookSearchRun($tree, hookSearchTurn('q1', 'how can I recover deleted torrents later', false));
            check(count(hookSearchRequests($fake)) === 1 && strpos($c, 'searched as: disk quota') !== false && strpos($c, 'Quota and storage') !== false, 'question word without ? not reworded (' . count(hookSearchRequests($fake)) . ' requests): ' . $c);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
            testRmTree($tree);
        }
    },
];
