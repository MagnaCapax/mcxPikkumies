<?php
/**
 * lib/Units.php edges not covered by ContractTest/GenerateTest: frontmatter parsing quirks, the
 * verbs-table parser's boundaries, Units::all()'s individual error branches, Units::doctrines()'
 * nested category rule (ADR-0055), and renderSkill()/renderIndex() rendering details. Reviewed against
 * the current source this session (see build brief); the "script-less unit with no verbs table is
 * not an error" row from the original 50-row table is dropped here because tests/GenerateTest.php's
 * generateFixture() already proves it: its server-admin unit has no script and no verbs table, and
 * that file's first test asserts $r['skills'] === 2 (rtorrent + server-admin), which requires
 * server-admin to carry zero errors.
 */

return [
    'units frontmatter: a duplicate key keeps the later value' => function () {
        list($meta, ) = Units::frontmatter("---\nname: first\nname: second\ndescription: d\n---\nbody\n");
        check($meta['name'] === 'second', 'duplicate name key did not keep the later value: ' . json_encode($meta));
    },
    'units frontmatter: a malformed meta line is silently dropped, not an error' => function () {
        list($meta, $body) = Units::frontmatter("---\nname: ok\nno colon on this line\ndescription: d\n---\nbody\n");
        check($meta === ['name' => 'ok', 'description' => 'd'], 'a malformed line was not simply skipped: ' . json_encode($meta));
        check($body === "body\n", 'body corrupted by the malformed line');
    },
    'units frontmatter: text without a leading "---" marker returns empty meta and the whole text as body' => function () {
        $text = "no marker here\njust body text\n";
        list($meta, $body) = Units::frontmatter($text);
        check($meta === [] && $body === $text, 'the no-match fallback must be a verbatim no-op: ' . json_encode([$meta, $body]));
    },
    'units verbs: the header match is case-insensitive' => function () {
        $v = Units::verbs("x\n| VERB | arguments | changes | what it does |\n|---|---|---|---|\n| show | `<id>` | no | One. |\n");
        check(count($v) === 1 && $v[0]['verb'] === 'show', 'an uppercase VERB header produced zero verbs: ' . json_encode($v));
    },
    'units verbs: only the first verb table in a document is ever parsed' => function () {
        $usage = "intro\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| first | a | no | First row. |\n"
            . "\nmore text\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| second | b | no | Second row. |\n";
        $v = Units::verbs($usage);
        check(count($v) === 1 && $v[0]['verb'] === 'first', 'a second verb table after a blank line leaked into the result: ' . json_encode($v));
    },
    'units verbs: a row with fewer than 4 cells is dropped, not an error' => function () {
        $usage = "| verb | arguments | changes | what it does |\n|---|---|---|---|\n| short | a | no |\n| ok | a | no | Full row. |\n";
        $v = Units::verbs($usage);
        check(count($v) === 1 && $v[0]['verb'] === 'ok', 'a short row was not dropped cleanly: ' . json_encode($v));
    },
    'units all(): an extra frontmatter key beyond name and description is flagged' => function () {
        $dir = testTempDir('pk-units-extra');
        try {
            $u = "$dir/tools/cat/extra";
            mkdir($u, 0700, true);
            file_put_contents("$u/skill.md", "---\nname: extra\ndescription: d\nversion: 1\n---\nbody\n");
            file_put_contents("$u/USAGE.md", "# x\n\nNo verbs.\n");
            file_put_contents("$u/README.md", "# x\n");
            $units = Units::all("$dir/tools");
            check(count($units) === 1, 'unexpected unit count: ' . count($units));
            check(in_array('skill.md frontmatter must be exactly name (= directory) and description', $units[0]['errors'], true), 'extra key not flagged: ' . json_encode($units[0]['errors']));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: array_diff(array_keys($meta), ['name','description']) is the only guard
        // against a stray key (e.g. a copy-pasted "version:") silently riding along in generated SKILL.md.
    },
    'units all(): a missing README.md alone hits the same combined error as a missing USAGE.md' => function () {
        $dir = testTempDir('pk-units-readme');
        try {
            $u = "$dir/tools/cat/noreadme";
            mkdir($u, 0700, true);
            file_put_contents("$u/skill.md", "---\nname: noreadme\ndescription: d\n---\nbody\n");
            file_put_contents("$u/USAGE.md", "# x\n\nNo verbs.\n");
            $units = Units::all("$dir/tools");
            check($units[0]['errors'] === ['USAGE.md and README.md are required'], 'a missing README.md alone did not hit the combined error: ' . json_encode($units[0]['errors']));
        } finally {
            testRmTree($dir);
        }
    },
    'units all(): a verbs header with zero data rows still counts as "no verbs table" on a unit with a script' => function () {
        $dir = testTempDir('pk-units-emptytable');
        try {
            $u = "$dir/tools/cat/emptytable";
            mkdir($u, 0700, true);
            file_put_contents("$u/skill.md", "---\nname: emptytable\ndescription: d\n---\nbody\n");
            file_put_contents("$u/USAGE.md", "# x\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n");
            file_put_contents("$u/README.md", "# x\n");
            file_put_contents("$u/emptytable.php", "<?php\n");
            $units = Units::all("$dir/tools");
            check($units[0]['errors'] === ['USAGE.md has no verbs table (' . Units::VERBS_HEADER . ')'], 'a header-only verbs table was not treated as "no verbs table": ' . json_encode($units[0]['errors']));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: a doc author who deletes the example rows but leaves the header+separator
        // behind must still be caught, not silently accepted as "has a verbs table".
    },
    'units doctrines(): a nested category\'s DOCTRINE.md is read under its path; one with no unit below it is not' => function () {
        $dir = testTempDir('pk-doctrine-nested');
        try {
            foreach (['flat/u1', 'outer/nested/u2'] as $u) {
                mkdir("$dir/$u", 0700, true);
                file_put_contents("$dir/$u/skill.md", "---\nname: " . basename($u) . "\ndescription: d\n---\n");
            }
            file_put_contents("$dir/flat/DOCTRINE.md", "- flat rule\n");
            file_put_contents("$dir/outer/nested/DOCTRINE.md", "- nested rule\n");
            mkdir("$dir/lonely/empty", 0700, true);
            file_put_contents("$dir/lonely/DOCTRINE.md", "- lonely rule\n");
            $d = Units::doctrines([], $dir);
            check($d === ['flat' => '- flat rule', 'outer/nested' => '- nested rule'], 'nested doctrine: ' . json_encode($d));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: ADR-0055 reads DOCTRINE.md in every category directory at any depth, keyed by its
        // path; rules of a category with no unit below it would sit in SOUL.md for nothing.
    },
    'units render: renderSkill() for a unit with verbs but no script omits the "Run:" line' => function () {
        $u = ['category' => 'cat', 'name' => 'noscript', 'dir' => '/t/cat/noscript', 'meta' => ['description' => 'd'], 'body' => "Body.\n",
            'verbs' => [['verb' => 'show', 'args' => 'a', 'changes' => 'no', 'does' => 'Shows.']], 'script' => ''];
        $out = Units::renderSkill($u);
        check(strpos($out, 'Run: `php') === false, 'a script-less unit with verbs still printed a Run: line: ' . $out);
        check(strpos($out, 'Full usage with examples:') !== false, 'the verbs table itself must still render');
    },
    'units render: renderSkill() JSON-encoding keeps slashes and non-ASCII characters literal' => function () {
        $u = ['category' => 'cat', 'name' => 'u', 'dir' => '/t', 'meta' => ['description' => 'Path a/b and café.'], 'body' => "Body.\n", 'verbs' => [], 'script' => ''];
        $out = Units::renderSkill($u);
        check(strpos($out, 'description: "Path a/b and café."') !== false, 'a slash or a non-ASCII character was escaped: ' . $out);
    },
    'units render: renderIndex() treats any "changes" word other than blank/no/- as destructive' => function () {
        $units = [['category' => 'cat', 'name' => 'u', 'dir' => '/t', 'meta' => ['description' => 'd'],
            'verbs' => [['verb' => 'act', 'args' => '', 'changes' => 'Maybe', 'does' => 'x']]]];
        $out = Units::renderIndex($units);
        check(strpos($out, 'Verbs: act*.') !== false, 'a non-standard changes value ("Maybe") was not marked destructive: ' . $out);
        // defect this catches: in_array(strtolower($changes), ['', 'no', '-'], true) is an allow-list for
        // "safe"; any looser check (e.g. matching only the literal string 'yes') would under-mark danger.
    },
];
