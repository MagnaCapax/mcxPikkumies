<?php
/**
 * ADR-0093: lib/Scratch.php (the per-session scratch folder and its clean-up), lib/TreeLitter.php's tree check, and the
 * scratch-dir hook. Scratch::$base points at a throwaway folder, so nothing is made in the real /tmp.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/system/scratch-dir/scratch-dir.php';

if (!function_exists('scratchBase')) {
    function scratchBase()
    {
        $t = testTempDir('pk-scratch');
        mkdir("$t/base", 0700);
        mkdir("$t/local", 0700);
        Scratch::$base = "$t/base";
        return $t;
    }

    function scratchDone($t)
    {
        Scratch::$base = null;
        testRmTree($t);
    }

    function scratchAge($path, $days)
    {
        touch($path, time() - $days * 86400);
        clearstatcache();
    }

    /** A clone at <t>/clone with pikkumies/local/ ignored, one tracked file, one stray file and one file in local/. */
    function scratchClone($t)
    {
        if (ToolProc::which('git') === null) {
            skip('git is not installed here');
        }
        mkdir("$t/clone/pikkumies/local", 0700, true);
        file_put_contents("$t/clone/.gitignore", "pikkumies/local/\n");
        file_put_contents("$t/clone/.gitattributes", "*.php filter=pkreview\n"); // no such filter yet: git ignores it until a test defines one
        file_put_contents("$t/clone/pikkumies/tracked.php", "<?php\n");
        exec('git -C ' . escapeshellarg("$t/clone") . ' init -q && git -C ' . escapeshellarg("$t/clone")
            . ' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false add -A && git -C ' . escapeshellarg("$t/clone")
            . ' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false commit -qm first 2>&1', $out, $code);
        check($code === 0, 'git: ' . implode("\n", $out));
        file_put_contents("$t/clone/pikkumies/stray.png", 'x');
        file_put_contents("$t/clone/pikkumies/local/state.json", '{}');
    }
}

return [
    'scratch: a new folder is pk-<uid>/<32 hex> in the base, 0700, owned by this account, and a new name each time' => function () {
        $t = scratchBase();
        try {
            $uid = Scratch::uid();
            $a = Scratch::create();
            $b = Scratch::create();
            check($a !== '' && $b !== '' && $a !== $b, "two folders expected: $a $b");
            check(dirname($a) === "$t/base/pk-$uid" && preg_match('/^[0-9a-f]{32}$/', basename($a)) === 1, "name: $a");
            $st = lstat($a);
            check(($st['mode'] & 0777) === 0700 && $st['uid'] === $uid && !is_link($a), 'mode ' . decoct($st['mode'] & 0777));
        } finally {
            scratchDone($t);
        }
    },
    'scratch: refuses a base that is a symlink or that others can write without the sticky bit; safe() refuses links, open modes and other places' => function () {
        $t = scratchBase();
        try {
            symlink("$t/base", "$t/linked");
            Scratch::$base = "$t/linked";
            check(Scratch::create() === '', 'a symlinked base must be refused');
            mkdir("$t/open", 0700);
            chmod("$t/open", 0777);
            Scratch::$base = "$t/open";
            check(Scratch::create() === '', 'a world-writable base without the sticky bit must be refused');
            chmod("$t/open", 01777);
            check(Scratch::create() !== '', 'a sticky shared base must be accepted');
            Scratch::$base = "$t/base";
            $uid = Scratch::uid();
            mkdir("$t/base/pk-$uid", 0700);
            $name = "pk-$uid/" . str_repeat('a', 32);
            symlink("$t/local", "$t/base/$name");
            check(!Scratch::safe("$t/base/$name", $uid), 'a symlink named like a scratch folder must not be safe');
            mkdir("$t/base/pk-$uid/" . str_repeat('b', 32), 0750);
            chmod("$t/base/pk-$uid/" . str_repeat('b', 32), 0750);
            check(!Scratch::safe("$t/base/pk-$uid/" . str_repeat('b', 32), $uid), 'a group-readable folder must not be safe');
            check(!Scratch::safe("$t/local", $uid), 'a folder outside the base, or misnamed, must not be safe');
        } finally {
            scratchDone($t);
        }
    },
    'scratch: one folder per session; a record pointing at a symlink or a vanished folder gets a fresh folder, never the old target' => function () {
        $t = scratchBase();
        try {
            list($a, $newA) = Scratch::forSession("$t/local", 's1');
            list($again, $newAgain) = Scratch::forSession("$t/local", 's1');
            list($b) = Scratch::forSession("$t/local", 's2');
            check($a !== '' && $newA && $again === $a && !$newAgain && $b !== $a, "per session: $a $again $b");
            check(Scratch::forSession("$t/local", 'bad id/../x') === ['', false], 'an unusable session id must get no folder');
            $uid = Scratch::uid();
            $trap = "$t/base/pk-$uid/" . str_repeat('c', 32);
            symlink("$t/local", $trap);
            file_put_contents("$t/local/state/scratch/" . sha1('s1'), "$trap\n");
            list($c, $newC) = Scratch::forSession("$t/local", 's1');
            check($newC && $c !== $trap && !is_link($c), "a record pointing at a symlink must get a new folder: $c");
            rmdir($c);
            list($d, $newD) = Scratch::forSession("$t/local", 's1');
            check($newD && $d !== $c && is_dir($d), 'a vanished folder must be made again');
        } finally {
            scratchDone($t);
        }
    },
    'scratch: prune removes this account\'s folders unused for 7 days, keeps fresh ones, follows no symlink and leaves other owners alone' => function () {
        $t = scratchBase();
        try {
            $uid = Scratch::uid();
            $old = Scratch::create();
            $fresh = Scratch::create();
            mkdir("$t/outside", 0700);
            file_put_contents("$t/outside/keep.txt", 'keep');
            symlink("$t/outside", "$old/link");                       // a link inside: unlinked, never followed
            mkdir("$old/sub", 0700);
            file_put_contents("$old/sub/f", 'x');
            $other = "$t/base/pk-" . ($uid + 1) . '/' . str_repeat('d', 32); // another uid's parent (made here, so not that uid's)
            mkdir($other, 0700, true);
            $topLink = "$t/base/pk-$uid/" . str_repeat('e', 32);        // a link named like ours, pointing at an old folder
            mkdir("$t/oldtarget", 0700);
            symlink("$t/oldtarget", $topLink);
            foreach ([$old, $other, "$t/oldtarget"] as $p) {
                scratchAge($p, 8);
            }
            list($removed, $failed) = Scratch::prune("$t/local");
            check($removed === 1 && $failed === 0 && !file_exists($old), "removed $removed, failed $failed");
            check(is_dir($fresh) && is_dir($other) && is_link($topLink) && is_dir("$t/oldtarget"), 'fresh, other-uid and symlinked entries must stay');
            check(file_get_contents("$t/outside/keep.txt") === 'keep', 'a symlink inside a pruned folder was followed');
            // The owner check: folders this account owns are never removed as another uid's.
            scratchAge($fresh, 8);
            list($removed) = Scratch::prune("$t/local", null, $uid + 1);
            check($removed === 0 && is_dir($fresh) && is_dir($other), 'prune for another uid removed a folder it does not own');
        } finally {
            scratchDone($t);
        }
    },
    'scratch: TreeLitter lists entries neither tracked nor ignored, and says when it cannot tell' => function () {
        $t = scratchBase();
        try {
            check(TreeLitter::stray("$t/local/pikkumies") === null, 'a folder that is not a checkout must give null');
            scratchClone($t);
            $stray = TreeLitter::stray("$t/clone/pikkumies");
            check($stray === ['pikkumies/stray.png'], 'stray: ' . json_encode($stray));
            check(strpos(TreeLitter::doctorLine("$t/clone/pikkumies"), 'stray.png') !== false, 'the doctor line must name the stray entry');
            check(file_exists("$t/clone/pikkumies/stray.png"), 'the tree check must never delete');
        } finally {
            scratchDone($t);
        }
    },
    'scratch: the hook names the folder and the stray entries once per session, and prunes old folders at a session start' => function () {
        $t = scratchBase();
        try {
            scratchClone($t);
            $old = Scratch::create();
            scratchAge($old, 9);
            $a = scratchDirAnswer(['session_id' => 'one'], "$t/clone/pikkumies", "$t/local");
            check(is_array($a) && preg_match('#Scratch folder for this session: ' . preg_quote("$t/base/pk-", '#') . '#', $a['context']) === 1, 'first turn: ' . json_encode($a));
            check(strpos($a['context'], 'stray.png') !== false && strpos($a['context'], 'state.json') === false, 'stray list: ' . $a['context']);
            check(!file_exists($old), 'an old folder must be pruned at a session start');
            check(scratchDirAnswer(['session_id' => 'one'], "$t/clone/pikkumies", "$t/local") === null, 'a later turn must be silent');
            check(scratchDirAnswer([], "$t/clone/pikkumies", "$t/local") === null, 'no session id: nothing to say');
        } finally {
            scratchDone($t);
        }
    },
    'scratch review W-1: the tree check runs no fsmonitor command and no clean filter the agent could have put in .git/config' => function () {
        $t = scratchBase();
        try {
            scratchClone($t);
            $git = 'git -C ' . escapeshellarg("$t/clone") . ' config ';
            exec($git . 'core.fsmonitor ' . escapeshellarg("touch $t/FSMONITOR-RAN; false"));
            exec($git . 'filter.pkreview.clean ' . escapeshellarg("sh -c 'touch $t/FILTER-RAN; cat'"));
            touch("$t/clone/pikkumies/tracked.php", time() + 5); // stat-dirty: git status would run the clean filter on it
            $stray = TreeLitter::stray("$t/clone/pikkumies");
            check(!file_exists("$t/FSMONITOR-RAN") && !file_exists("$t/FILTER-RAN"), 'the tree check ran a command from .git/config');
            check($stray === ['pikkumies/stray.png'], 'stray: ' . json_encode($stray));
        } finally {
            scratchDone($t);
        }
    },
    'scratch review W-2: prune leaves a folder that has a mount point at or below it, even on the same filesystem' => function () {
        $t = scratchBase();
        try {
            $old = Scratch::create();
            $plain = Scratch::create();
            mkdir("$old/bind", 0700);
            file_put_contents("$old/bind/keep.txt", 'keep');
            file_put_contents("$t/mountinfo", "22 1 8:1 / / rw - ext4 /dev/sda1 rw\n"
                . '91 22 8:1 /victim ' . str_replace(' ', '\040', "$old/bind") . " rw - ext4 /dev/sda1 rw\n");
            Scratch::$mountinfo = "$t/mountinfo";
            scratchAge($old, 8);
            scratchAge($plain, 8);
            list($removed, $failed) = Scratch::prune("$t/local");
            check(file_get_contents("$old/bind/keep.txt") === 'keep' && $failed === 1, "a folder with a mount inside was entered: removed $removed, failed $failed");
            check($removed === 1 && !file_exists($plain), 'a folder with no mount must still be removed');
        } finally {
            Scratch::$mountinfo = null;
            scratchDone($t);
        }
    },
    'scratch review W-3: session folders live in one private parent per account, pk-<uid>, which must be a real 0700 folder of this account' => function () {
        $t = scratchBase();
        try {
            $uid = Scratch::uid();
            $a = Scratch::create();
            check(dirname($a) === "$t/base/pk-$uid" && preg_match('/^[0-9a-f]{32}$/', basename($a)) === 1, "layout: $a");
            clearstatcache();
            $st = lstat("$t/base/pk-$uid");
            check(($st['mode'] & 0777) === 0700 && $st['uid'] === $uid, 'the parent must be 0700 and ours');
            mkdir("$t/other", 0700);
            Scratch::$base = "$t/other";
            symlink("$t/base/pk-$uid", "$t/other/pk-$uid");
            check(Scratch::create() === '', 'a parent that is a symlink must be refused');
            unlink("$t/other/pk-$uid");
            mkdir("$t/other/pk-$uid", 0700);
            chmod("$t/other/pk-$uid", 0755);
            check(Scratch::create() === '', 'a parent others can read must be refused');
        } finally {
            scratchDone($t);
        }
    },
    'scratch review W-4: the announce line and the doctrine line say scratch is for small files and big ones go in the home folder' => function () {
        $t = scratchBase();
        try {
            $a = scratchDirAnswer(['session_id' => 'w4'], "$t/none/pikkumies", "$t/local");
            check(is_array($a) && strpos($a['context'], 'small files only') !== false && strpos($a['context'], 'go in your home') !== false, 'announce: ' . json_encode($a));
            $rules = (string) file_get_contents(Config::root() . '/doctrine/doctrine.md');
            check(strpos($rules, 'small files only') !== false && strpos($rules, 'downloads, archives, builds and anything large go in your home') !== false, 'the doctrine line must carry the size rule');
        } finally {
            scratchDone($t);
        }
    },
];
