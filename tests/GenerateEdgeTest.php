<?php
/**
 * lib/Generate.php error paths and the hooks/INDEX.md step: outDir/lock failures, a mid-loop
 * skill-write failure's partial-write semantics, the INDEX.md-specific write failure, the no-sibling-
 * hooks/ skip, a broken hook unit's isolation, the rmdir cascade on disable, and writeIfChanged()'s
 * own atomic-write contract (a separate implementation from HermesConfig::put(), DRY-duplication
 * risk flagged in the review). Reviewed against the current source this session (see build brief).
 */
if (!function_exists('generateEdgeFixture')) {
    /** A minimal tools tree: one valid, script-less unit, so Generate::run() has exactly one skill to write. */
    function generateEdgeFixture($tmp)
    {
        $dir = "$tmp/tools/cat/one";
        mkdir($dir, 0700, true);
        file_put_contents("$dir/skill.md", "---\nname: one\ndescription: d\n---\nBody.\n");
        file_put_contents("$dir/USAGE.md", "# x\n\nNo verbs.\n");
        file_put_contents("$dir/README.md", "# x\n");
    }

    /** A hook unit directory hooks/<id>/ with hook.md, README.md and <name>.php. */
    function generateEdgeHook($hooksDir, $id, array $over = [])
    {
        $dir = "$hooksDir/$id";
        $name = basename($id);
        mkdir($dir, 0700, true);
        $fields = array_merge(['name' => $name, 'event' => 'pre_tool_call', 'description' => 'fixture', 'failClosed' => 'false', 'timeout' => '5'], $over);
        $md = "---\n";
        foreach ($fields as $k => $v) {
            $md .= "$k: $v\n";
        }
        file_put_contents("$dir/hook.md", $md . "---\nbody\n");
        file_put_contents("$dir/README.md", "# $name\n");
        file_put_contents("$dir/$name.php", "<?php\n");
    }
}

return [
    'generate: hooks/INDEX.md is written correctly on first run and never reopened on an unchanged second run' => function () {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $tmp = testTempDir('pk-gen-hooks');
        try {
            generateEdgeFixture($tmp);
            generateEdgeHook("$tmp/hooks", 'guard/delete', ['failClosed' => 'true']);
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === '', 'first run failed: ' . json_encode($r));
            $index = (string) @file_get_contents("$tmp/hooks/INDEX.md");
            check(strpos($index, '**delete**') !== false && strpos($index, 'fail closed') !== false, 'hooks/INDEX.md not written correctly: ' . $index);
            chmod("$tmp/hooks", 0500); // read+execute only: a second write attempt (temp file + rename) would fail
            $r2 = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            chmod("$tmp/hooks", 0700);
            check($r2['error'] === '', 'an unchanged hooks/INDEX.md was reopened for writing on the second run: ' . json_encode($r2));
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: outDir blocked by a same-named file reports a create error, no writes' => function () {
        $tmp = testTempDir('pk-gen-blocked');
        try {
            generateEdgeFixture($tmp);
            file_put_contents("$tmp/out", "not a directory\n"); // outDir's own path is a plain file
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === "cannot create $tmp/out", 'wrong/missing error: ' . json_encode($r));
            check($r['written'] === 0 && $r['skills'] === 0, 'a blocked outDir must not report any writes: ' . json_encode($r));
            check(!is_file("$tmp/tools/INDEX.md"), 'INDEX.md must not be written when outDir cannot be created');
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: the generate lock cannot be acquired reports a lock error, no writes' => function () {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $tmp = testTempDir('pk-gen-lock');
        try {
            generateEdgeFixture($tmp);
            mkdir("$tmp/out", 0700, true); // outDir pre-exists so Generate::run() proceeds to the lock step
            chmod($tmp, 0500); // outDir's parent: no write, so creating .generate.lock there fails
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            chmod($tmp, 0700);
            check($r['error'] === 'cannot take the generate lock', 'wrong/missing error: ' . json_encode($r));
            check($r['written'] === 0, 'a failed lock must not report any writes: ' . json_encode($r));
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: a mid-loop skill write failure stops writing the remaining skills, earlier ones stay written' => function () {
        $tmp = testTempDir('pk-gen-midloop');
        try {
            foreach (['aaa', 'bbb', 'ccc'] as $name) {
                $dir = "$tmp/tools/cat/$name";
                mkdir($dir, 0700, true);
                file_put_contents("$dir/skill.md", "---\nname: $name\ndescription: d\n---\nBody.\n");
                file_put_contents("$dir/USAGE.md", "# x\n\nNo verbs.\n");
                file_put_contents("$dir/README.md", "# x\n");
            }
            mkdir("$tmp/out/cat", 0700, true);
            file_put_contents("$tmp/out/cat/bbb", "blocking file, not a directory\n"); // blocks bbb's own output dir
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === "cannot write $tmp/out/cat/bbb/SKILL.md (disk full?)", 'wrong/missing error: ' . json_encode($r));
            check(is_file("$tmp/out/cat/aaa/SKILL.md"), 'the earlier skill (aaa) must stay written when a later one fails');
            check(!is_file("$tmp/out/cat/ccc/SKILL.md"), 'a skill after the failure point (ccc) must not have been written: the loop should have stopped');
        } finally {
            testRmTree($tmp);
        }
        // defect this catches: the loop breaks on the first writeIfChanged() === null; continuing past it
        // (e.g. with a bare `continue`) would silently drop the disk-full signal for the rest of the batch.
    },
    'generate: an INDEX.md write failure after skills succeed is reported for the index specifically' => function () {
        $tmp = testTempDir('pk-gen-indexfail');
        try {
            generateEdgeFixture($tmp);
            mkdir("$tmp/tools/INDEX.md", 0700, true); // the index path itself is pre-occupied by a directory
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === "cannot write $tmp/tools/INDEX.md", 'wrong/missing error: ' . json_encode($r));
            check($r['written'] === 1 && is_file("$tmp/out/cat/one/SKILL.md"), 'the skill itself must still have been written before the index failed: ' . json_encode($r));
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: no sibling hooks/ directory skips the hook-index step entirely' => function () {
        $tmp = testTempDir('pk-gen-nohooks');
        try {
            generateEdgeFixture($tmp);
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === '', 'run failed with no hooks/ sibling: ' . json_encode($r));
            $hookProblems = array_filter(array_keys($r['problems']), function ($k) {
                return strpos($k, 'hooks/') === 0;
            });
            check(!$hookProblems, 'a hook problem was reported with no hooks/ dir: ' . json_encode($r['problems']));
            check(!is_dir("$tmp/hooks"), 'a hooks/ directory must not be created when none existed');
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: a broken hook unit is reported and does not block the valid ones' => function () {
        $tmp = testTempDir('pk-gen-brokenhook');
        try {
            generateEdgeFixture($tmp);
            generateEdgeHook("$tmp/hooks", 'cat/good', []);
            generateEdgeHook("$tmp/hooks", 'cat/bad', ['event' => 'not-a-real-event']); // invalid event -> errors
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['error'] === '', 'run failed: ' . json_encode($r));
            check(isset($r['problems']['hooks/cat/bad']), 'the broken hook unit was not reported: ' . json_encode($r['problems']));
            $index = (string) @file_get_contents("$tmp/hooks/INDEX.md");
            check(strpos($index, '**good**') !== false, 'the valid hook unit must still be written: ' . $index);
            check(strpos($index, 'bad') === false, 'the broken hook unit must not appear in the index');
        } finally {
            testRmTree($tmp);
        }
    },
    'generate: disabling the only unit of a category removes the now-empty directories too' => function () {
        $tmp = testTempDir('pk-gen-rmdir');
        try {
            generateEdgeFixture($tmp); // category 'cat', unit 'one'
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['written'] === 1 && is_file("$tmp/out/cat/one/SKILL.md"), 'first run did not write the skill: ' . json_encode($r));
            $r2 = Generate::run(['cat'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r2['removed'] === 1, 'disabling the category did not remove the skill: ' . json_encode($r2));
            check(!is_dir("$tmp/out/cat/one") && !is_dir("$tmp/out/cat"), 'the now-empty unit and category directories must be rmdir\'d too');
        } finally {
            testRmTree($tmp);
        }
        // existing coverage (GenerateTest.php) checks the SKILL.md file disappears; it does not check
        // that the now-empty unit/category directories are rmdir'd, which this test adds.
    },
    'generate: writeIfChanged() with different content writes and returns true, updating the file' => function () {
        $dir = testTempDir('pk-wic');
        try {
            $file = "$dir/f.md";
            check(Generate::writeIfChanged($file, "first\n") === true, 'first write must return true');
            check(Generate::writeIfChanged($file, "second\n") === true, 'a changed write must return true');
            check(file_get_contents($file) === "second\n", 'content was not updated');
        } finally {
            testRmTree($dir);
        }
    },
    'generate: writeIfChanged() returns null and leaves no stray temp file when rename is blocked' => function () {
        $dir = testTempDir('pk-wic-rename');
        try {
            $file = "$dir/f.md";
            mkdir($file, 0700); // the final path is a directory: rename() onto it must fail
            check(Generate::writeIfChanged($file, "content\n") === null, 'a blocked rename must return null');
            check(!glob("$dir/.tmp-*"), 'a stray temporary file was left behind');
            check(is_dir($file), 'the pre-existing directory must be left alone');
        } finally {
            testRmTree($dir);
        }
        // defect this catches: writeIfChanged() is a SEPARATE implementation from HermesConfig::put()
        // (DRY-duplication risk); a missing @unlink($tmp) here would leave an orphaned temp file
        // independent of whether put()'s own cleanup is correct.
    },
    'generate: writeIfChanged() on unchanged content performs no write attempt at all' => function () {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            skip('running as root; permission bits do not block the write');
        }
        $dir = testTempDir('pk-wic-unchanged');
        try {
            $file = "$dir/f.md";
            file_put_contents($file, "same\n");
            chmod($dir, 0500); // read+execute only: any write attempt (temp file creation) would fail
            $result = Generate::writeIfChanged($file, "same\n");
            chmod($dir, 0700);
            check($result === false, 'unchanged content must return false: ' . var_export($result, true));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: if writeIfChanged() attempted a write even on unchanged content, ADR-0022's
        // "never thrashes a live Hermes session's open file" guarantee would be broken; this proves zero
        // attempt was made, not merely that the return value happens to be correct.
    },
];
