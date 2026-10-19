<?php
/**
 * The guard asks the user before a write that can reach local hooks, local notes or their change record (ADR-0054,
 * GuardLocal.php); reading them, and writing anywhere else, stays silent. A prompt, not a stop on self-modification:
 * the last test pins the blind spots ADR-0054 lists, so a change in what is caught is a decision, not an accident.
 */
if (!function_exists('guardLocalAnswer')) {
    /** The guard's decoded answer for one tool call, or null for no answer. */
    function guardLocalAnswer($tool, array $input)
    {
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
            json_encode(['tool_name' => $tool, 'tool_input' => $input]));
        check($code === 0, "guard exited $code");
        $d = json_decode($out, true);
        return is_array($d) ? $d : null;
    }

    /** Each command must be asked as a local write, with its own rule_key and the whole command in the message. */
    function guardLocalAsks(array $commands)
    {
        foreach ($commands as $cmd) {
            $d = guardLocalAnswer('terminal', ['command' => $cmd]);
            $id = substr(sha1($cmd), 0, 16);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-local:$id" && strpos($d['message'], "[$id]: $cmd") !== false, "not asked: $cmd -> " . json_encode($d));
        }
    }
}

return [
    'local guard: commands that write local hooks, notes or the change record ask; reads do not' => function () {
        guardLocalAsks([
            "cat > ~/pikkumies/local/hooks/mine/check/check.php <<'EOF'\n<?php exit(0);\nEOF",
            'mkdir -p ~/pikkumies/local/hooks/tools/rtorrent/ask',
            'cp /tmp/x.php ~/pikkumies/local/hooks/mine/x/x.php',
            "sed -i 's/a/b/' /home/u/pikkumies/local/tools/email/DOCTRINE.md",
            'echo {} | tee ~/pikkumies/local/state/local-changes.json',
            'ln -s /tmp/evil ~/pikkumies/local/hooks/mine',
            'cat > ~/pikkumies/local//hooks/a/b/b.php',
            'php -r \'file_put_contents(getenv("HOME")."/pikkumies/local/hooks/a/b/b.php","x");\'',
        ]);
        foreach (['ls -la ~/pikkumies/local/hooks', 'cat ~/pikkumies/local/tools/email/DOCTRINE.md 2>/dev/null', 'grep -r block ~/pikkumies/local/hooks',
            'echo hi > ~/notes.txt', 'mkdir -p ~/work/local/hookshot', 'cp a.conf /usr/local/tools/a.conf', 'echo x > /usr/local/hooks/x',
            'cd ~/pikkumies && git log --oneline -5', 'cd ~/pikkumies && php tools/system/credits/credits.php balance > /tmp/b.txt'] as $cmd) {
            check(guardLocalAnswer('terminal', ['command' => $cmd]) === null, "asked about: $cmd");
        }
        $d = guardLocalAnswer('execute_code', ['code' => "open('/home/u/pikkumies/local/hooks/a/a/a.php', 'w').write('x')"]);
        check(is_array($d) && $d['action'] === 'approve', 'execute_code write not asked');
    },
    'local guard: moving or unpacking into local/ itself, a cd into it, a glob and a brace are asked (review M1)' => function () {
        guardLocalAsks([
            'mv /tmp/x/hooks ~/pikkumies/local/',
            'cp -r /tmp/x/hooks ~/pikkumies/local',
            'tar -xf /tmp/h.tar -C ~/pikkumies/local',
            'rsync -a /tmp/x/ "$HOME/pikkumies/local/"',
            'cd ~/pikkumies/local && mkdir -p hooks/a/b && cat > hooks/a/b/b.php',
            'cd ~/pikkumies/local/state && echo {} > x.json',
            'cd ~/pikkumies && mkdir -p local/hooks/a/b',
            'cd /home/u/pikkumies/ && mv /tmp/h local/',
            'cat > ~/pikkumies/local/h*s/a/b/b.php',
            'echo x > ~/pikkumies/local/{hooks,}/a/b/b.php',
            'cp -r /tmp/h ~/pikkumies/l?cal/hooks/',
        ]);
    },
    'local guard: a path that steps out with .. and back in, or a cd below pikkumies/ with ../local, is asked (review 2, S2)' => function () {
        guardLocalAsks([
            'cp -r /tmp/x ~/pikkumies/hooks/../local/hooks',
            'cd ~/pikkumies/hooks && cp -r /tmp/x ../local/hooks',
            'cd ~/pikkumies/tools && tar xf /tmp/h.tar -C ../local',
            'cd ~/pikkumies/tools/system && cp -r /tmp/h ../../local/hooks/',
        ]);
        foreach (['/home/u/pikkumies/hooks/../local/hooks/a/b/b.php', '/home/u/pikkumies/tools/x/../../local/tools/y/DOCTRINE.md'] as $p) {
            $d = guardLocalAnswer('write_file', ['path' => $p, 'content' => 'x']);
            check(is_array($d) && $d['action'] === 'approve', "write_file through .. not asked: $p");
        }
        check(guardLocalAnswer('terminal', ['command' => 'cd ~/pikkumies/hooks && cp a.txt /tmp/b.txt']) === null, 'a cd below pikkumies/ alone asked');
    },
    'local guard: in an agent started by another agent the question becomes a block (ADR-0020 nested-agent rule)' => function () {
        $d = testWithEnv(['PIKKUMIES_DEPTH' => '2'], function () {
            return guardLocalAnswer('terminal', ['command' => 'mv /tmp/x/hooks ~/pikkumies/local/']);
        });
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'started by another agent') !== false && strpos($d['message'], 'mv /tmp/x/hooks') !== false, 'not blocked at depth 2: ' . json_encode($d));
        $d = testWithEnv(['PIKKUMIES_DEPTH' => '2'], function () {
            return guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/hooks/a/b/b.php', 'content' => 'x']);
        });
        check(is_array($d) && $d['action'] === 'block', 'write_file not blocked at depth 2: ' . json_encode($d));
    },
    'local guard: write_file and patch under local hooks or notes ask with the start of the text; read_file does not' => function () {
        $d = guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/hooks/mine/check/check.php', 'content' => "<?php\n" . str_repeat('x', 10)]);
        check(is_array($d) && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-local:') === 0
            && strpos($d['message'], 'write_file writes /home/u/pikkumies/local/hooks/mine/check/check.php') !== false && strpos($d['message'], '<?php xxx') !== false,
            'write_file not asked with path and text: ' . json_encode($d));
        $d2 = guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/hooks/mine/check/check.php', 'content' => '<?php exit(0);']);
        check($d2['rule_key'] !== $d['rule_key'], 'one "always" answer would cover different text');
        // 900 characters of content triggers the "first 600 shown" truncation marker; that marker alone is already
        // over HookUnits::APPROVE_SHOWN, so the question cannot be shown in full and must be refused, not half-shown.
        $d = guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/hooks/mine/check/check.php', 'content' => "<?php\n" . str_repeat('x', 900)]);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'too long to show the user in full') !== false, 'a write too long to show in full must be refused, not half-shown: ' . json_encode($d));
        $d = guardLocalAnswer('patch', ['mode' => 'patch', 'patch' => "*** Update File: local/tools/x"]);
        check(is_array($d) && $d['action'] === 'approve' && strpos($d['message'], 'the files in the patch') !== false, 'a V4A patch into local/tools not asked: ' . json_encode($d));
        check(is_array(guardLocalAnswer('write_file', ['path' => 'local/hooks/mine/x/x.php', 'content' => 'x'])), 'a relative write_file path not asked');
        check(guardLocalAnswer('read_file', ['path' => '/home/u/pikkumies/local/hooks/mine/check/check.php']) === null, 'read_file asked');
        check(guardLocalAnswer('write_file', ['path' => '/home/u/notes.md', 'content' => 'x']) === null, 'an ordinary write asked');
        check(guardLocalAnswer('write_file', ['path' => '/usr/local/tools/x.conf', 'content' => 'x']) === null, '/usr/local/tools asked');
        $d = guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/secrets/x', 'content' => 'x']);
        check(is_array($d) && $d['action'] === 'block', 'the stored-password block must still come first');
    },
    'local guard: a write_file question is asked right up to HookUnits::APPROVE_SHOWN characters, one more is blocked' => function () {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        // n=26 and n=27 of unshown content around "<?php\n" measured against this exact path and the fixed wording
        // in GuardLocal.php's $shown string: computed once by hand, pinned here so a wording change fails loudly.
        foreach ([26 => 'approve', 27 => 'block'] as $n => $want) {
            $d = guardLocalAnswer('write_file', ['path' => '/home/u/pikkumies/local/hooks/mine/check/check.php', 'content' => str_repeat('x', $n)]);
            check(is_array($d) && $d['action'] === $want, "$n characters of content: expected $want: " . json_encode($d));
            if ($want === 'approve') {
                check(strlen($d['message']) === 350, 'length arithmetic off: ' . strlen($d['message']));
            }
        }
    },
    'local guard: the blind spots ADR-0054 lists stay unasked (a local hook written this way still waits for the next start)' => function () {
        foreach (['mkdir -p local/hooks/a/b', 'cp -r /tmp/h "$PK/local/hooks/"', 'cp -r /tmp/h ~/pikk*/local/hooks/'] as $cmd) {
            check(guardLocalAnswer('terminal', ['command' => $cmd]) === null, "a listed blind spot is now caught; update ADR-0054 and this test: $cmd");
        }
    },
];
