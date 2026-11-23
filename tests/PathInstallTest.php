<?php
/** lib/PathInstall.php: put `pikkumies` on PATH, idempotently (ADR-0022). */
return [
    'pathinstall: picks the first of ~/.local/bin, ~/bin already on PATH; else prefers ~/.local/bin' => function () {
        list($dir, $on) = PathInstall::binDir('/home/u', '/usr/bin:/home/u/bin:/usr/local/bin');
        check($dir === '/home/u/bin' && $on === true, 'did not find ~/bin on PATH: ' . json_encode([$dir, $on]));
        list($dir, $on) = PathInstall::binDir('/home/u', '/usr/bin:/home/u/.local/bin:/home/u/bin');
        check($dir === '/home/u/.local/bin' && $on === true, '.local/bin should win when both are on PATH: ' . json_encode([$dir, $on]));
        list($dir, $on) = PathInstall::binDir('/home/u', '/usr/bin:/usr/local/bin');
        check($dir === '/home/u/.local/bin' && $on === false, 'neither on PATH should still prefer .local/bin: ' . json_encode([$dir, $on]));
        // A trailing slash in PATH, or in $home, must not defeat the match.
        list(, $on) = PathInstall::binDir('/home/u/', '/usr/bin:/home/u/bin/');
        check($on === true, 'trailing slash broke the PATH match');
    },
    'pathinstall: ensureLink symlinks, is idempotent, retargets a stale link, never clobbers a real file' => function () {
        $tmp = sys_get_temp_dir() . '/pk-link-' . getmypid() . '-' . mt_rand();
        @mkdir($tmp, 0700, true);
        $r = PathInstall::ensureLink("$tmp/bin", "$tmp/target-a");
        check(strpos($r, 'linked') === 0 && is_link("$tmp/bin/pikkumies") && readlink("$tmp/bin/pikkumies") === "$tmp/target-a", "first link failed: $r");
        $r = PathInstall::ensureLink("$tmp/bin", "$tmp/target-a");
        check(strpos($r, 'already linked') !== false, "re-run should be a no-op: $r");
        $r = PathInstall::ensureLink("$tmp/bin", "$tmp/target-b");
        check(readlink("$tmp/bin/pikkumies") === "$tmp/target-b", "stale link to a moved checkout was not retargeted: $r");
        unlink("$tmp/bin/pikkumies");
        file_put_contents("$tmp/bin/pikkumies", "not a symlink");
        $r = PathInstall::ensureLink("$tmp/bin", "$tmp/target-c");
        check(strpos($r, 'left alone') !== false && file_get_contents("$tmp/bin/pikkumies") === 'not a symlink', "a real file was clobbered: $r");
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: mergeBlock keeps foreign lines byte for byte and never duplicates on re-run' => function () {
        $before = "alias ll='ls -la'\n" . PathInstall::MARK_START . "\nexport PATH=\"/old/bin:\$PATH\"\n" . PathInstall::MARK_END . "\nexport EDITOR=vim\n";
        $merged = PathInstall::mergeBlock($before, PathInstall::block('/new/bin'));
        check(substr_count($merged, PathInstall::MARK_START) === 1, "old block not replaced once: $merged");
        check(strpos($merged, "alias ll='ls -la'\n") === 0, 'line before the block lost: ' . $merged);
        check(strpos($merged, "export EDITOR=vim\n") !== false, 'line after the block lost: ' . $merged);
        check(strpos($merged, '/old/bin') === false && strpos($merged, '/new/bin') !== false, 'old dir not replaced: ' . $merged);
        check(PathInstall::mergeBlock($merged, PathInstall::block('/new/bin')) === $merged, 'merging the same block again changed the file');
    },
    'pathinstall: ensureFile writes once, keeps the file mode, and reports whether it wrote' => function () {
        $tmp = sys_get_temp_dir() . '/pk-ef-' . getmypid() . '-' . mt_rand();
        @mkdir($tmp, 0700, true);
        file_put_contents("$tmp/.bashrc", "export FOO=1\n");
        chmod("$tmp/.bashrc", 0644);
        check(PathInstall::ensureFile("$tmp/.bashrc", '/opt/a/bin') === true, 'first call should write');
        check((fileperms("$tmp/.bashrc") & 0777) === 0644, 'file mode changed');
        check(strpos(file_get_contents("$tmp/.bashrc"), "export FOO=1\n") === 0, 'foreign line lost');
        check(PathInstall::ensureFile("$tmp/.bashrc", '/opt/a/bin') === false, 'second call for the same dir should be a no-op');
        check(PathInstall::ensureFile("$tmp/.bashrc", '/opt/b/bin') === true, 'a changed dir should rewrite the block');
        check(strpos(file_get_contents("$tmp/.bashrc"), '/opt/a/bin') === false, 'old dir left behind');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: install() links and, only when not already on PATH, writes .bashrc and .profile' => function () {
        $tmp = sys_get_temp_dir() . '/pk-inst-' . getmypid() . '-' . mt_rand();
        @mkdir($tmp, 0700, true);
        $msg = PathInstall::install($tmp, "/usr/bin:$tmp/.local/bin", "$tmp/pikkumies/bin/pikkumies");
        check(strpos($msg, 'already on PATH') !== false, "should trust an already-exported dir: $msg");
        check(!is_file("$tmp/.bashrc") && !is_file("$tmp/.profile"), 'rc files touched even though the dir was already on PATH');
        check(is_link("$tmp/.local/bin/pikkumies"), 'link not created for the already-on-PATH dir');
        $tmp2 = sys_get_temp_dir() . '/pk-inst2-' . getmypid() . '-' . mt_rand();
        @mkdir($tmp2, 0700, true);
        $msg = PathInstall::install($tmp2, '/usr/bin', "$tmp2/pikkumies/bin/pikkumies");
        check(strpos($msg, 'PATH line added to .bashrc and .profile') !== false, "should fall back to rc files: $msg");
        check(is_link("$tmp2/.local/bin/pikkumies"), 'link not created for the new dir');
        foreach (['.bashrc', '.profile'] as $f) {
            check(strpos(file_get_contents("$tmp2/$f"), $tmp2 . '/.local/bin') !== false, "$f missing the PATH block");
        }
        $again = PathInstall::install($tmp2, '/usr/bin', "$tmp2/pikkumies/bin/pikkumies");
        check(strpos($again, 'already present') !== false, "re-run should say the PATH line is already there: $again");
        exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($tmp2));
    },
    'pathinstall: a symlinked rc file stays a symlink; its target gets the block; a dangling link is left alone' => function () {
        $tmp = sys_get_temp_dir() . '/pk-link-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/dotfiles", 0700, true);
        file_put_contents("$tmp/dotfiles/bashrc", "export FOO=1\n");
        symlink("$tmp/dotfiles/bashrc", "$tmp/.bashrc");
        check(PathInstall::ensureFile("$tmp/.bashrc", '/opt/a/bin') === true, 'symlinked rc not written');
        check(is_link("$tmp/.bashrc"), 'the symlink was replaced by a plain file');
        check(strpos(file_get_contents("$tmp/dotfiles/bashrc"), '/opt/a/bin') !== false, 'link target missing the block');
        symlink("$tmp/nowhere", "$tmp/.profile");
        check(PathInstall::ensureFile("$tmp/.profile", '/opt/a/bin') === false, 'dangling link should be left alone');
        check(is_link("$tmp/.profile") && !file_exists("$tmp/nowhere"), 'dangling link was followed or replaced');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: ensureRootLink repairs a dangling ~/pikkumies and names the old target; creates an absent one silently' => function () {
        $tmp = sys_get_temp_dir() . '/pk-root-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/home", 0700, true);
        @mkdir("$tmp/root/bin", 0700, true);
        file_put_contents("$tmp/root/bin/pikkumies", "#!/bin/sh\n");
        symlink("$tmp/missing-home/.mcxPikkumies/pikkumies", "$tmp/home/pikkumies");
        check(!file_exists("$tmp/home/pikkumies"), 'setup: the link should dangle');
        $note = PathInstall::ensureRootLink("$tmp/home", "$tmp/root");
        check(is_link("$tmp/home/pikkumies") && readlink("$tmp/home/pikkumies") === "$tmp/root", 'dangling link not repointed: ' . $note);
        check(is_file("$tmp/home/pikkumies/bin/pikkumies"), 'the repaired link does not resolve to the launcher');
        check(strpos($note, "$tmp/missing-home/.mcxPikkumies/pikkumies") !== false, "the old target is not named: $note");
        check(PathInstall::ensureRootLink("$tmp/home", "$tmp/root") === '', 're-run should say nothing');
        unlink("$tmp/home/pikkumies");
        check(PathInstall::ensureRootLink("$tmp/home", "$tmp/root") === '' && readlink("$tmp/home/pikkumies") === "$tmp/root", 'an absent link was not created quietly');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: ensureRootLink never touches a real directory, a real file or a link that resolves' => function () {
        $tmp = sys_get_temp_dir() . '/pk-root2-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/root", 0700, true);
        @mkdir("$tmp/h1/pikkumies/keep", 0700, true);
        file_put_contents("$tmp/h1/pikkumies/keep/data", 'mine');
        @mkdir("$tmp/h2", 0700, true);
        file_put_contents("$tmp/h2/pikkumies", 'a plain file');
        @mkdir("$tmp/h3", 0700, true);
        @mkdir("$tmp/own-clone", 0700, true);
        symlink("$tmp/own-clone", "$tmp/h3/pikkumies");
        foreach (['h1', 'h2', 'h3'] as $h) {
            $note = PathInstall::ensureRootLink("$tmp/$h", "$tmp/root");
            check($note === '', "$h: said something about a name that exists: $note");
        }
        check(file_get_contents("$tmp/h1/pikkumies/keep/data") === 'mine' && !is_link("$tmp/h1/pikkumies"), 'a real directory was replaced');
        check(file_get_contents("$tmp/h2/pikkumies") === 'a plain file', 'a real file was replaced');
        check(readlink("$tmp/h3/pikkumies") === "$tmp/own-clone", 'a resolving link to another directory was repointed');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: ensureRootLink reports a failure to link instead of staying silent' => function () {
        // A home whose parent is a regular file cannot hold a link, whoever runs the test.
        $tmp = sys_get_temp_dir() . '/pk-root3-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/root", 0700, true);
        file_put_contents("$tmp/notadir", 'x');
        $note = PathInstall::ensureRootLink("$tmp/notadir/home", "$tmp/root");
        check(strpos($note, 'could not link') === 0, "a failed link was silent or misreported: '$note'");
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'pathinstall: the install case in bin/pikkumies uses ensureRootLink, not an inline file_exists + @symlink' => function () {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($src, 'PathInstall::ensureRootLink(') !== false, 'install no longer calls PathInstall::ensureRootLink');
        check(strpos($src, '@symlink(') === false, 'install again links ~/pikkumies inline with a suppressed @symlink');
    },
];
