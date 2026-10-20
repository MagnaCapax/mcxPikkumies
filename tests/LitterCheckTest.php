<?php
/**
 * ADR-0093: hooks/guard/litter-check/. Each test builds a throwaway clone (<t>/clone/pikkumies with local/, memory/ and
 * tools/), a home whose pikkumies links into it, and a scratch base, then calls litterCheckAnswer() in this process.
 * Nothing in the fixture is created by the hook: it only reads paths.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/litter-check/litter-check.php';

if (!function_exists('litterTree')) {
    function litterTree()
    {
        $t = testTempDir('pk-litter');
        foreach (['clone/pikkumies/local', 'clone/pikkumies/memory', 'clone/pikkumies/tools', 'home', 'out', 'tmp'] as $d) {
            mkdir("$t/$d", 0700, true);
        }
        file_put_contents("$t/clone/pikkumies/tools/existing.md", "x\n");
        symlink("$t/clone/pikkumies", "$t/home/pikkumies");
        Scratch::$base = "$t/tmp";
        return $t;
    }

    /** The hook's answer for one call; $input is the tool_input, $cwd the payload's cwd. */
    function litterAnswer($t, $tool, array $input, $cwd = '')
    {
        $payload = ['tool_name' => $tool, 'tool_input' => $input, 'session_id' => 's1', 'cwd' => $cwd];
        return litterCheckAnswer($payload, "$t/clone/pikkumies", "$t/clone/pikkumies/local", "$t/home");
    }

    function litterBlocked($t, $command, $workdir = '')
    {
        $a = litterAnswer($t, 'terminal', ['command' => $command] + ($workdir !== '' ? ['workdir' => $workdir] : []));
        return is_array($a) && $a['action'] === 'block';
    }

    function litterDone($t)
    {
        Scratch::$base = null;
        testRmTree($t);
    }
}

return [
    'litter-check: a new folder in ~/pikkumies or the clone is refused, by ~, $HOME and the real path; the message names the session scratch folder' => function () {
        $t = litterTree();
        try {
            $a = litterAnswer($t, 'terminal', ['command' => 'mkdir ~/pikkumies/foo']);
            check(is_array($a) && $a['action'] === 'block', 'mkdir ~/pikkumies/foo was not refused: ' . json_encode($a));
            check(preg_match('#' . preg_quote("$t/tmp/pk-", '#') . '\d+/[0-9a-f]{32}#', $a['message']) === 1, 'the message must name the scratch folder: ' . $a['message']);
            check(strpos($a['message'], 'do not work around it') !== false, 'the refusal must say it is the answer: ' . $a['message']);
            check(litterBlocked($t, 'mkdir -p "$HOME/pikkumies/tools/x/y"'), 'mkdir -p $HOME/pikkumies/tools/x/y was not refused');
            check(litterBlocked($t, "mkdir $t/clone/stray"), 'a new folder at the root of the clone was not refused');
            check(!is_dir("$t/clone/pikkumies/foo") && !is_dir("$t/clone/stray"), 'the hook must never create anything');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: local/, memory/, an existing file and places outside the tree pass; a fetch into tools/ does not' => function () {
        $t = litterTree();
        try {
            foreach (['mkdir -p ~/pikkumies/local/whisper', 'git clone -q --depth 1 https://example.invalid/x.git ~/pikkumies/local/opt/x',
                'curl -fsSL -o ~/pikkumies/local/tmp/f https://example.invalid/f', 'echo note > ~/pikkumies/memory/lessons.md',
                'echo hi > ~/pikkumies/tools/existing.md', "mkdir $t/out/new", 'ls ~/pikkumies 2>/dev/null >&2', 'git -C ~/pikkumies status'] as $ok) {
                check(!litterBlocked($t, $ok), "refused but allowed: $ok");
            }
            check(litterBlocked($t, 'git clone https://example.invalid/tool.git ~/pikkumies/tools/tool'), 'a clone into tools/ was not refused');
            check(litterBlocked($t, 'cd ~/pikkumies && wget https://example.invalid/a.tgz'), 'wget into ~/pikkumies by its default name was not refused');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: redirections, tee, cp into an existing tree folder, and relative paths from the workdir or a cd are refused' => function () {
        $t = litterTree();
        try {
            foreach (['echo x > ~/pikkumies/notes.txt', 'echo x >> ~/pikkumies/notes.txt', 'ls | tee ~/pikkumies/l.txt', 'cp /etc/hostname ~/pikkumies/tools/',
                'cd ~/pikkumies && mkdir v', "cd $t/clone && touch shot.png", 'echo $(touch ~/pikkumies/sub.txt)'] as $bad) {
                check(litterBlocked($t, $bad), "not refused: $bad");
            }
            check(litterBlocked($t, 'mkdir v', "$t/clone/pikkumies"), 'a relative mkdir from a workdir in the tree was not refused');
            check(!litterBlocked($t, "cd $t/out && mkdir v", "$t/clone/pikkumies"), 'a cd out of the tree must count');
            check(!litterBlocked($t, 'mkdir v'), 'a relative path with no workdir cannot be placed and must pass');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: a symlink into the tree does not get around it, existing or dangling' => function () {
        $t = litterTree();
        try {
            symlink("$t/clone/pikkumies/tools", "$t/out/link");
            symlink("$t/clone/pikkumies/newfile", "$t/out/dangling");
            check(litterBlocked($t, "mkdir $t/out/link/new"), 'mkdir through a symlinked folder was not refused');
            check(litterBlocked($t, "touch $t/out/dangling"), 'touch of a dangling link pointing into the tree was not refused');
            check(litterBlocked($t, "mkdir $t/out/../clone/x"), 'a path stepping in with .. was not refused');
            $a = litterAnswer($t, 'write_file', ['path' => "$t/out/link/new.php", 'content' => 'x']);
            check(is_array($a) && $a['action'] === 'block', 'write_file through a symlinked folder was not refused');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: Hermes write_file and patch of a new file in the tree are refused; an existing file and local/ pass' => function () {
        $t = litterTree();
        try {
            $a = litterAnswer($t, 'write_file', ['path' => '~/pikkumies/x.md', 'content' => 'x']);
            check(is_array($a) && $a['action'] === 'block', 'write_file of a new file was not refused');
            check(litterAnswer($t, 'write_file', ['path' => "$t/clone/pikkumies/local/notes.md", 'content' => 'x']) === null, 'write_file into local/ was refused');
            check(litterAnswer($t, 'write_file', ['path' => 'tools/existing.md', 'content' => 'x'], "$t/clone/pikkumies") === null, 'an existing file was refused');
            $patch = "*** Begin Patch\n*** Add File: tools/new.php\n+<?php\n*** End Patch\n";
            $a = litterAnswer($t, 'patch', ['mode' => 'patch', 'patch' => $patch], "$t/clone/pikkumies");
            check(is_array($a) && $a['action'] === 'block', 'a V4A Add File in the tree was not refused');
            check(litterAnswer($t, 'patch', ['path' => "$t/clone/pikkumies/tools/existing.md", 'old_string' => 'x', 'new_string' => 'y']) === null, 'a patch of an existing file was refused');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: globs and archives count by their folder' => function () {
        $t = litterTree();
        try {
            check(litterBlocked($t, 'tar xzf /x/a.tgz -C ~/pikkumies/'), 'tar extracting into ~/pikkumies was not refused');
            check(litterBlocked($t, 'mkdir ~/pikkumies/{a,b}'), 'a brace in the tree was not refused');
            check(litterBlocked($t, 'cp /x/*.png ~/pikkumies/tools/'), 'globbed sources into a tree folder were not refused');
            check(!litterBlocked($t, 'unzip -q /x/a.zip -d ~/pikkumies/local/x'), 'unzip into local/ was refused');
            check(!litterBlocked($t, 'tar czf /tmp/out.tgz -C ~/pikkumies tools'), 'creating an archive outside the tree from it was refused');
        } finally {
            litterDone($t);
        }
    },
    'litter-check review W-5: the text after sh -c is read as a command, and sudo -u/-g/-C values are not the program' => function () {
        $t = litterTree();
        try {
            foreach (['sh -c "mkdir ~/pikkumies/x"', "bash -lc 'touch ~/pikkumies/y'", 'sudo -u nobody mkdir ~/pikkumies/z', 'sudo -g staff -C 3 touch ~/pikkumies/w',
                'busybox mkdir ~/pikkumies/b', 'env -u X mkdir ~/pikkumies/e', 'timeout -s KILL 5 touch ~/pikkumies/t'] as $bad) {
                check(litterBlocked($t, $bad), "not refused: $bad");
            }
            check(!litterBlocked($t, 'sh -c "mkdir ~/pikkumies/local/ok"'), 'sh -c into local/ was refused');
        } finally {
            litterDone($t);
        }
    },
    'litter-check review W-6: here-document bodies are not commands, and a target that is only a variable passes' => function () {
        $t = litterTree();
        try {
            $wd = "$t/clone/pikkumies/tools";
            foreach (["cat > /tmp/x.php <<'E'\n<?php \$a->b();\nE", "cat > /tmp/x.html <<E\n<p>hi</p>\nE", "cat <<-EOF > /tmp/y\n\ta > b\n\tEOF",
                'echo "x" >> "$LOG"', 'mkdir "$(mktemp -d)/x"', "awk '\$1 > 5' f", "printf '%s\\n' 'a>b'"] as $ok) {
                check(!litterBlocked($t, $ok, $wd), 'refused but harmless: ' . json_encode($ok));
            }
            check(litterBlocked($t, "cat > notes.md <<'E'\nx\nE", $wd), 'the heredoc command itself must still be read');
            check(litterBlocked($t, "cat <<E > /tmp/a\nE\nmkdir v", $wd), 'a command after the here-document must still be read');
        } finally {
            litterDone($t);
        }
    },
    'litter-check: SshWords returns output redirection targets and leaves descriptors and input alone' => function () {
        $s = SshWords::split('cmd a > x 2>&1 >> "y z" < in &> w >&2');
        check(array_map(function ($w) {
            return $w[1]['text'];
        }, $s['writes']) === ['x', 'y z', 'w'], 'writes: ' . json_encode($s['writes']));
        check(array_column($s['commands'][0], 'text') === ['cmd', 'a'], 'the command words changed: ' . json_encode($s['commands']));
    },
    'litter-check: run as the hook, it answers a block for the real tree and fails closed on a payload it cannot read' => function () {
        $script = dirname(__DIR__) . '/pikkumies/hooks/guard/litter-check/litter-check.php';
        $target = dirname(Config::root()) . '/zz-litter-test-' . getmypid();
        list($code, $out) = runPhp($script, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => "mkdir $target"]]));
        $a = json_decode(trim($out), true);
        check($code === 0 && is_array($a) && $a['action'] === 'block' && !file_exists($target), "mkdir in the real clone: $code $out");
        // A word with a variable, read in a fresh process: SshExpansion must load SshUnreadable itself (publish-check has the same path).
        list($code, $out) = runPhp($script, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => "X=1; mkdir \"$target/\$X\""]]));
        $a = json_decode(trim($out), true);
        check($code === 0 && is_array($a) && $a['action'] === 'block', "a word with a variable must still be read: $code $out");
        list($code, $out) = runPhp($script, [], 'not json');
        $a = json_decode(trim($out), true);
        check(is_array($a) && $a['action'] === 'block', 'an unreadable payload must block: ' . $out);
        list($code, $out) = runPhp($script, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls /']]));
        check($code === 0 && trim($out) === '', 'a harmless command must get no opinion: ' . $out);
    },
];
