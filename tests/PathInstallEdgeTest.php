<?php
/**
 * lib/PathInstall.php edges not covered by PathInstallTest.php (ADR-0022): candidates() called
 * directly, binDir()'s prefix-collision guard, ensureLink()'s unwritable-parent branch, block()'s
 * shell-escaping, mergeBlock()'s damaged-dotfile and convergence behaviour, ensureFile()'s
 * nested-mkdir and mode-preserving branches, and install()'s trailing-slash/empty-PATH/partial-
 * failure edges.
 */
return [
    'pathinstall: candidates() direct order and trailing-slash strip' => function () {
        check(PathInstall::candidates('/home/u') === ['/home/u/.local/bin', '/home/u/bin'], 'order: .local/bin must come first');
        check(PathInstall::candidates('/home/u/') === ['/home/u/.local/bin', '/home/u/bin'], 'a trailing slash on $home must not double up');
    },
    "pathinstall: candidates('') with an empty \$home falls back to root-relative paths" => function () {
        check(PathInstall::candidates('') === ['/.local/bin', '/bin'], "an empty home must not crash, and must not silently become the current directory: " . json_encode(PathInstall::candidates('')));
    },
    'pathinstall: binDir() prefix-collision: a dir that merely starts with a candidate name must not match' => function () {
        list($dir, $on) = PathInstall::binDir('/home/u', '/home/u/bin2:/usr/bin');
        check($dir === '/home/u/.local/bin' && $on === false, "'/home/u/bin2' must never be read as '/home/u/bin' on PATH: " . json_encode([$dir, $on]));
    },
    'pathinstall: ensureLink() with a target containing a space round-trips exactly through readlink()' => function () {
        $tmp = testTempDir('pk-link-space');
        $target = "$tmp/my target dir/pikkumies";
        $r = PathInstall::ensureLink("$tmp/bin", $target);
        check(strpos($r, 'linked') === 0 && readlink("$tmp/bin/pikkumies") === $target, "a space in the target must survive byte for byte: $r");
        testRmTree($tmp);
    },
    'pathinstall: ensureLink() on an unwritable parent directory returns "could not link", never throws' => function () {
        $tmp = testTempDir('pk-link-nowrite');
        mkdir("$tmp/ro", 0500);
        try {
            $r = PathInstall::ensureLink("$tmp/ro/bin", "$tmp/target");
            check(strpos($r, 'could not link') !== false, "an unwritable parent must fail softly with 'could not link': $r");
        } finally {
            chmod("$tmp/ro", 0700);
            testRmTree($tmp);
        }
    },
    "pathinstall: block() for a directory with a quote and a backslash produces syntactically valid shell, and mergeBlock() is stable round-trip" => function () {
        $dir = '/home/u/weird"dir\\sub';
        $block = PathInstall::block($dir);
        $script = "set -n\n" . $block;
        $tmp = testTempDir('pk-block-syntax');
        file_put_contents("$tmp/s.sh", $script);
        exec('bash -n ' . escapeshellarg("$tmp/s.sh") . ' 2>&1', $out, $code);
        check($code === 0, "the exported PATH block is not valid shell syntax for a quote+backslash dir: " . implode("\n", $out) . "\n" . $block);
        $merged = PathInstall::mergeBlock($block, $block);
        check($merged === $block, 'merging the same quote+backslash block again changed it: ' . $merged);
        testRmTree($tmp);
    },
    'pathinstall: mergeBlock() with a dangling START (no END) swallows everything to EOF' => function () {
        $before = "keep me\n" . PathInstall::MARK_START . "\nexport PATH=\"/old:\$PATH\"\n# the END marker was lost\nstill inside the dangling block\n";
        $merged = PathInstall::mergeBlock($before, PathInstall::block('/new/bin'));
        check(strpos($merged, 'keep me') !== false, 'the line before the dangling block must survive: ' . $merged);
        check(strpos($merged, 'still inside the dangling block') === false, 'everything after a dangling START must be dropped, not kept as a foreign line: ' . $merged);
        check(substr_count($merged, PathInstall::MARK_START) === 1, 'exactly one fresh block must remain: ' . $merged);
    },
    'pathinstall: mergeBlock() drops a stray END-only line (no matching START), keeps foreign lines around it' => function () {
        $before = "before\n" . PathInstall::MARK_END . "\nafter\n";
        $merged = PathInstall::mergeBlock($before, PathInstall::block('/new/bin'));
        check(strpos($merged, 'before') !== false && strpos($merged, 'after') !== false, 'foreign lines around the stray END must survive: ' . $merged);
        check(strpos($merged, PathInstall::MARK_END . "\n" . 'after') === false, 'the stray END-only marker itself must not remain verbatim next to "after": ' . $merged);
        check(substr_count($merged, PathInstall::MARK_START) === 1, 'exactly one fresh block must be appended: ' . $merged);
    },
    'pathinstall: mergeBlock() reaches a byte-identical fixed point after repeated reapplication' => function () {
        $current = "alias ll='ls -la'\n";
        $block = PathInstall::block('/opt/pikkumies/bin');
        $once = PathInstall::mergeBlock($current, $block);
        $twice = PathInstall::mergeBlock($once, $block);
        $thrice = PathInstall::mergeBlock($twice, $block);
        check($once === $twice && $twice === $thrice, "mergeBlock must converge after the first application, not drift on repeated reinstalls:\n$once\n---\n$twice\n---\n$thrice");
    },
    'pathinstall: mergeBlock() with no trailing newline on the kept content inserts exactly one before the block' => function () {
        $merged = PathInstall::mergeBlock('foo', PathInstall::MARK_START . "\nbar\n" . PathInstall::MARK_END . "\n");
        check($merged === "foo\n" . PathInstall::MARK_START . "\nbar\n" . PathInstall::MARK_END . "\n", 'expected exactly one inserted newline: ' . json_encode($merged));
    },
    'pathinstall: ensureFile() creates missing nested parent directories' => function () {
        $tmp = testTempDir('pk-ef-nested');
        $path = "$tmp/a/b/c/.bashrc";
        check(!is_dir("$tmp/a"), 'the nested parent must not exist yet');
        check(PathInstall::ensureFile($path, '/opt/bin') === true, 'ensureFile must write through missing parents');
        check(is_file($path), 'the file must exist after mkdir -p of its parents');
        testRmTree($tmp);
    },
    'pathinstall: ensureFile() with nothing to change leaves an unusual file mode (0640) untouched' => function () {
        $tmp = testTempDir('pk-ef-mode');
        $path = "$tmp/.bashrc";
        file_put_contents($path, PathInstall::block('/opt/bin'));
        chmod($path, 0640);
        check(PathInstall::ensureFile($path, '/opt/bin') === false, 'no-op expected: the block is already exactly this');
        check((fileperms($path) & 0777) === 0640, 'a no-op write must never touch the file mode');
        testRmTree($tmp);
    },
    'pathinstall: install() with a trailing slash on $home behaves identically end to end' => function () {
        $tmp = testTempDir('pk-install-slash');
        $msg = PathInstall::install("$tmp/", '/usr/bin', "$tmp/pikkumies/bin/pikkumies");
        check(is_link("$tmp/.local/bin/pikkumies"), 'a trailing slash on $home must not break the link path: ' . $msg);
        testRmTree($tmp);
    },
    "pathinstall: install() with an empty \$pathEnv does not crash, falls back to writing rc files" => function () {
        $tmp = testTempDir('pk-install-emptypath');
        $msg = PathInstall::install($tmp, '', "$tmp/pikkumies/bin/pikkumies");
        check(strpos($msg, 'PATH line added') !== false, "explode(':', '') must not be treated as \"already on PATH\": $msg");
        testRmTree($tmp);
    },
    'pathinstall: install() still writes the rc files even when ensureLink() itself fails' => function () {
        $tmp = testTempDir('pk-install-partial');
        // $home stays writable (the rc files live directly under it); only the preferred bin
        // candidate (.local/bin, under a read-only .local) is where the symlink must fail.
        mkdir("$tmp/.local", 0500);
        $msg = PathInstall::install($tmp, '/usr/bin', "$tmp/pikkumies/bin/pikkumies");
        chmod("$tmp/.local", 0700);
        check(strpos($msg, 'could not link') !== false, "the link failure must be reported: $msg");
        check(strpos($msg, 'PATH line added') !== false, "the rc files must still be written despite the link failure: $msg");
        testRmTree($tmp);
    },
];
