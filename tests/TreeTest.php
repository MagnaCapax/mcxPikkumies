<?php
/** lib/Tree.php and its consumers on a three-level fixture (ADR-0055): tools, doctrine, hooks, disabled prefixes. */
if (!function_exists('treeToolUnit')) {
    /** A complete tool unit at tools/$path (name = last segment). */
    function treeToolUnit($tools, $path)
    {
        $name = basename($path);
        @mkdir("$tools/$path", 0700, true);
        file_put_contents("$tools/$path/skill.md", "---\nname: $name\ndescription: Fixture $name.\n---\nBody.\n");
        file_put_contents("$tools/$path/USAGE.md", "# $name\n\nNo verbs.\n");
        file_put_contents("$tools/$path/README.md", "# $name\n");
    }

    /** A hook unit at hooks/$path; $md false = no hook.md. */
    function treeHookUnit($hooks, $path, $md = true)
    {
        $name = basename($path);
        @mkdir("$hooks/$path", 0700, true);
        if ($md) {
            file_put_contents("$hooks/$path/hook.md", "---\nname: $name\nevent: pre_tool_call\ndescription: Fixture.\nfailClosed: false\ntimeout: 5\n---\nx\n");
        }
        file_put_contents("$hooks/$path/README.md", "# $name\n");
        file_put_contents("$hooks/$path/$name.php", "<?php\n");
    }

    function treeIds(array $units)
    {
        return array_map(function ($u) {
            return $u['category'] . '/' . $u['name'];
        }, $units);
    }
}

return [
    'tree: units at any depth, category = path; lib, dot-dirs, depth-1 markers and dirs inside a unit are not units' => function () {
        $dir = testTempDir('pk-tree');
        try {
            $t = "$dir/tools";
            treeToolUnit($t, 'email/send');
            treeToolUnit($t, 'email/imap/fetch');
            treeToolUnit($t, 'email/imap/fetch/inner');   // inside a unit: never read
            treeToolUnit($t, 'email/lib/helper');          // lib/ is shared code
            treeToolUnit($t, '.hidden/ghost');
            treeToolUnit($t, 'email-x/other');
            file_put_contents("$t/email/skill.md", "---\nname: email\ndescription: d\n---\n"); // a depth-1 marker is not a unit
            $units = Units::all($t);
            check(treeIds($units) === ['email-x/other', 'email/send', 'email/imap/fetch'], 'units: ' . json_encode(treeIds($units)));
            foreach ($units as $u) {
                check($u['errors'] === [], "{$u['name']}: " . json_encode($u['errors']));
            }
            $index = Units::renderIndex($units);
            check(substr_count($index, "\n## email\n") === 1 && substr_count($index, "\n## email/imap\n") === 1, 'one heading per category path: ' . $index);
            check(strpos(Units::renderSkill($units[2]), 'Generated from tools/email/imap/fetch/skill.md') !== false, 'header path of a nested unit');
        } finally {
            testRmTree($dir);
        }
    },
    'tree: DOCTRINE.md of any category dir; shown only while one enabled unit is below it; prefixes are whole segments' => function () {
        $dir = testTempDir('pk-tree-doc');
        try {
            $t = "$dir/tools";
            treeToolUnit($t, 'email/pop/get');
            treeToolUnit($t, 'email/imap/fetch');
            treeToolUnit($t, 'media/ffmpeg');
            treeToolUnit($t, 'media-servers/plex');
            file_put_contents("$t/email/DOCTRINE.md", "- email rule\n");
            file_put_contents("$t/email/imap/DOCTRINE.md", "- imap rule\n");
            file_put_contents("$t/media-servers/DOCTRINE.md", "- servers rule\n");
            file_put_contents("$t/email/imap/fetch/DOCTRINE.md", "- unit rule\n"); // a unit's own doctrine is not category doctrine
            check(Units::doctrines([], $t) === ['email' => '- email rule', 'email/imap' => '- imap rule', 'media-servers' => '- servers rule'], 'all: ' . json_encode(Units::doctrines([], $t)));
            check(array_keys(Units::doctrines(['email/imap'], $t)) === ['email', 'media-servers'], 'a nested category off');
            check(array_keys(Units::doctrines(['email'], $t)) === ['media-servers'], 'a parent off takes its children');
            check(array_keys(Units::doctrines(['email/imap', 'email/pop'], $t)) === ['media-servers'], 'every unit below off: the doctrine goes too');
            check(array_keys(Units::doctrines(['media'], $t)) === ['email', 'email/imap', 'media-servers'], 'media must not switch off media-servers');
            check(Units::isDisabled('media/ffmpeg', ['media']) && Units::isDisabled('media', ['media/']) && !Units::isDisabled('media-servers', ['media']) && !Units::isDisabled('media', ['']), 'isDisabled segments');
        } finally {
            testRmTree($dir);
        }
    },
    'tree: a reserved name and the second unit of one name are errors; the first keeps working' => function () {
        $dir = testTempDir('pk-tree-names');
        try {
            $t = "$dir/tools";
            treeToolUnit($t, 'a/same');
            treeToolUnit($t, 'b/deep/same');
            treeToolUnit($t, 'c/terminal');
            $e = [];
            foreach (Units::all($t) as $u) {
                $e[$u['category'] . '/' . $u['name']] = $u['errors'];
            }
            check($e['a/same'] === [], 'the first unit of a name got an error');
            check(count($e['b/deep/same']) === 1 && strpos($e['b/deep/same'][0], 'already used by tools/a/same') !== false, 'duplicate: ' . json_encode($e));
            check(count($e['c/terminal']) === 1 && strpos($e['c/terminal'][0], 'Hermes already uses') !== false, 'reserved: ' . json_encode($e));
            check(in_array('terminal', explode(' ', Units::RESERVED_NAMES), true), 'the reserved list lives in lib');
        } finally {
            testRmTree($dir);
        }
    },
    'tree: a symlinked dir and a category past the depth bound are reported as broken units, never dropped' => function () {
        $dir = testTempDir('pk-tree-link');
        try {
            $t = "$dir/tools";
            treeToolUnit("$dir/elsewhere", 'x/real');
            treeToolUnit($t, 'cat/ok');
            symlink("$dir/elsewhere/x/real", "$t/cat/linked");
            $deep = 'd1/d2/d3/d4/d5/d6/d7';
            treeToolUnit($t, "$deep/at8");               // depth 8: still read
            treeToolUnit($t, "$deep/d8/below");          // d8 is a category at depth 8 with a subdirectory
            $e = [];
            foreach (Units::all($t) as $u) {
                $e[$u['category'] . '/' . $u['name']] = $u['errors'];
            }
            check($e['cat/ok'] === [] && $e["$deep/at8"] === [], 'good units: ' . json_encode($e));
            check(isset($e['cat/linked']) && strpos($e['cat/linked'][0], 'symlinked') !== false, 'symlink: ' . json_encode($e));
            check(isset($e["$deep/d8"]) && strpos($e["$deep/d8"][0], 'deeper than') !== false && !isset($e["$deep/d8/below"]), 'depth bound: ' . json_encode(array_keys($e)));
        } finally {
            testRmTree($dir);
        }
    },
    'tree: hooks keep ADR-0030: depth-1 dirs are categories; a dir with <hook>.php or a leaf is a unit, broken without hook.md' => function () {
        $dir = testTempDir('pk-tree-hooks');
        try {
            $h = "$dir/hooks";
            treeHookUnit($h, 'guard/fine');
            treeHookUnit($h, 'tools/rtorrent/ask');      // three levels deep
            file_put_contents("$h/tools/README.md", "# per-tool hooks\n");
            mkdir("$h/docs");
            file_put_contents("$h/docs/README.md", "# a README-only category\n");
            treeHookUnit($h, 'guard/lost', false);       // hook.md lost, a fixtures/ subdir: still a unit
            mkdir("$h/guard/lost/fixtures");
            treeHookUnit($h, 'guard/lost/fixtures/nested');
            mkdir("$h/guard/empty");                     // a leaf without anything
            $e = [];
            foreach (HookUnits::all($h) as $u) {
                $e[$u['category'] . '/' . $u['name']] = $u['errors'];
            }
            check(array_keys($e) === ['guard/empty', 'guard/fine', 'guard/lost', 'tools/rtorrent/ask'], 'hook units: ' . json_encode(array_keys($e)));
            check($e['guard/fine'] === [] && $e['tools/rtorrent/ask'] === [], 'good hook units: ' . json_encode($e));
            check($e['guard/lost'] && $e['guard/empty'], 'a lost hook.md or an empty leaf must be a broken unit');
            check(treeIds(HookUnits::forEvent('pre_tool_call', $h)) === ['tools/rtorrent/ask', 'guard/empty', 'guard/fine', 'guard/lost'], 'broken units run on pre_tool_call');
        } finally {
            testRmTree($dir);
        }
    },
    'tree: a nested hook unit whose hook.md is gone blocks every tool call through the orchestrator' => function () {
        $dir = testTempDir('pk-tree-orch');
        try {
            $src = dirname(__DIR__) . '/pikkumies';
            mkdir("$dir/pikkumies/lib", 0700, true);
            mkdir("$dir/pikkumies/local", 0700);
            foreach ((array) glob("$src/lib/*.php") as $f) {
                copy($f, "$dir/pikkumies/lib/" . basename($f));
            }
            mkdir("$dir/pikkumies/hooks");
            copy("$src/hooks/orchestrator.php", "$dir/pikkumies/hooks/orchestrator.php");
            treeHookUnit("$dir/pikkumies/hooks", 'tools/rtorrent/lost', false);
            mkdir("$dir/pikkumies/hooks/tools/rtorrent/lost/fixtures");
            list(, $out) = runPhp("$dir/pikkumies/hooks/orchestrator.php", ['pre_tool_call'], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block', 'a broken nested unit did not block: ' . $out);
        } finally {
            testRmTree($dir);
        }
    },
    'tree: an unreadable directory is one broken unit with one reason, never a phantom path or a depth error' => function () {
        $dir = testTempDir('pk-tree-locked');
        $locked = [];
        try {
            $t = "$dir/tools";
            $h = "$dir/hooks";
            treeToolUnit($t, 'cat/ok');
            treeToolUnit($t, 'cat/shut/inner');          // depth 2, unreadable: its unit below is not read
            treeToolUnit($t, 'top/x');                   // depth 1 category, unreadable
            treeHookUnit($h, 'guard/fine');
            treeHookUnit($h, 'guard/shut');
            mkdir("$dir/gen/cat/shut", 0700, true);      // an unreadable directory inside the generated skills
            foreach (["$t/cat/shut", "$t/top", "$h/guard/shut", "$dir/gen/cat/shut"] as $d) {
                chmod($d, 0);
                $locked[] = $d;
            }
            if (is_readable("$t/top")) {
                skip('running as root (or with CAP_DAC_OVERRIDE): chmod 0 does not lock a directory');
            }
            $warnings = [];
            set_error_handler(function ($no, $msg) use (&$warnings) {
                if (error_reporting() & $no) { // a warning silenced with @ is not counted
                    $warnings[] = $msg;
                }
                return true;
            });
            try {
                $tools = Units::all($t);
                $hooks = HookUnits::all($h);
                $gen = Generate::run([], $t, "$dir/gen", "$dir/INDEX.md");
            } finally {
                restore_error_handler();
            }
            $e = [];
            foreach (array_merge($tools, $hooks) as $u) {
                $e[$u['category'] . '/' . $u['name']] = $u['errors'];
            }
            check(array_keys($e) === ['/top', 'cat/ok', 'cat/shut', 'guard/fine', 'guard/shut'], 'units: ' . json_encode(array_keys($e)));
            foreach (['/top', 'cat/shut', 'guard/shut'] as $id) {
                check($e[$id] === [Tree::UNREADABLE], "$id: " . json_encode($e[$id]));
            }
            check($e['cat/ok'] === [] && $e['guard/fine'] === [], 'readable units: ' . json_encode($e));
            check($warnings === [], 'PHP warnings: ' . json_encode($warnings));
            check(isset($gen['problems']['cat/shut']) && is_file("$dir/gen/cat/ok/SKILL.md"), 'generate: ' . json_encode($gen));
            chmod($t, 0);
            $locked[] = $t;
            check(treeIds(Units::all($t)) === ['/tools'] && Units::all($t)[0]['errors'] === [Tree::UNREADABLE], 'an unreadable root is one broken unit');
        } finally {
            foreach ($locked as $d) {
                @chmod($d, 0700);
            }
            testRmTree($dir);
        }
    },
    'tree: categories keep the old glob order (trailing slash); names within a category are in strcmp order' => function () {
        $u = function ($c, $n) {
            return ['category' => $c, 'name' => $n];
        };
        $list = [$u('media', 'a'), $u('email/imap', 'b'), $u('media-servers', 'c'), $u('email', 'z'), $u('email', 'a'), $u('cat', 'pk-x'), $u('cat', 'pk')];
        usort($list, ['Tree', 'compare']);
        // The old glob of cat/pk/skill.md sorted pk after pk-x ('-' < '/'); strcmp on the name puts pk first.
        check(treeIds($list) === ['cat/pk', 'cat/pk-x', 'email/a', 'email/z', 'email/imap/b', 'media-servers/c', 'media/a'], 'order: ' . json_encode(treeIds($list)));
    },
];
