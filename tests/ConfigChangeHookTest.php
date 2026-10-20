<?php
/**
 * hooks/guard/config-change (ADR-0059): the plain configure command is judged by the tool's own plan, an asked change
 * gets Hermes' approval prompt with a rule_key for that exact change, free and person changes get no opinion, and the
 * other ways to change settings it can see are asked with the whole command. The hook runs in a throwaway tree whose
 * local/ holds the "current" config and the change log, so this checkout's local/ is never read or written.
 */
if (!function_exists('cfgHookTree')) {
    /** A pikkumies tree with lib, bin, the tools and this hook; local/config.php holds $local. */
    function cfgHookTree(array $local = [])
    {
        $tmp = realpath(testTempDir('pk-cfghook'));
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tmp/pikkumies/hooks/guard", 0700, true);
        @mkdir("$tmp/pikkumies/local/state", 0700, true);
        foreach (['lib', 'bin', 'tools', 'hooks/guard/config-change'] as $d) {
            exec('cp -R ' . escapeshellarg("$src/$d") . ' ' . escapeshellarg("$tmp/pikkumies/$d"));
        }
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($local, true) . ";\n");
        return $tmp;
    }

    /** The tree's hook on a tool call, at depth 1 unless given; the decoded answer, or null for no opinion. */
    function cfgHook($tree, $tool, array $input, $depth = '1')
    {
        return testWithEnv(['PIKKUMIES_CONFIG' => null, 'PIKKUMIES_DEPTH' => $depth], function () use ($tree, $tool, $input) {
            list(, $out) = runPhp("$tree/pikkumies/hooks/guard/config-change/config-change.php", [], json_encode(['tool_name' => $tool, 'tool_input' => $input]));
            return trim($out) === '' ? null : json_decode($out, true);
        });
    }

    /** `php <the tree's configure tool> <words>`. */
    function cfgHookCmd($tree, $words)
    {
        return 'php ' . "$tree/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php $words";
    }

    /** The rule_key the hook must give one change: one answer covers exactly that change (key, old, new). */
    function cfgHookKey($key, $old, $new)
    {
        return 'pikkumies-config:' . substr(hash('sha256', json_encode([$key, $old, $new], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);
    }
}

return [
    'config hook: the agent turning feedback.ask on asks the person with the reason, within the panel; turning it off is free' => function () {
        $tree = cfgHookTree();
        try {
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set feedback.ask on')]);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === cfgHookKey('feedback.ask', false, true)
                && strpos($d['message'], 'a rating is sent only when you press its number') !== false && HookUnits::approveShown($d['message']), json_encode($d));
            check(cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set feedback.ask on')], '2')['action'] === 'block', 'an agent started by an agent turned it on');
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['feedback' => ['ask' => true]], true) . ";\n");
            check(cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set feedback.ask off')]) === null, 'turning it off must need no question');
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: a free change and the person\'s own get no opinion; an asked one gets approve keyed to that exact change' => function () {
        $tree = cfgHookTree(['root' => ['disabled' => true]]);
        try {
            foreach (['set agent.name Kalle', 'set halt.disabled off', 'set sessions.retentionDays 0', 'set update.channel release', 'skills disable seedbox-apps',
                'review --human', 'set root.disabled off', 'set searchFirst.disabled on', 'set tools.disabled nosuch', 'set provider.endpoint x'] as $w) {
                check(cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, $w)]) === null, "$w must get no opinion (free, the person's, or refused by the tool)");
            }
            $asked = [['set provider.model m-1', 'provider.model', '', 'm-1'], ['set hooks.model m-mini', 'hooks.model', '', 'm-mini'],
                ['set tools.disabled email', 'tools.disabled', [], ['email']], ['set lowBalance.disabled on', 'lowBalance.disabled', false, true],
                ['set cron.disabled off', 'cron.disabled', true, false], ['set sessions.retentionDays 30', 'sessions.retentionDays', 0, 30],
                ['set update.channel git/main', 'update.channel', 'release', 'git/main'], ['set hooks.reword off', 'hooks.reword', true, false]];
            file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['cron' => ['disabled' => true]], true) . ";\n");
            foreach ($asked as $a) {
                $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, $a[0])]);
                check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === cfgHookKey($a[1], $a[2], $a[3]), "$a[0]: " . json_encode($d));
                check(strpos($d['message'], $a[1]) !== false && strpos($d['message'], cfgHookCmd($tree, $a[0])) !== false, "$a[0]: the message names the key and the command");
            }
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set tools.disabled email')]);
            check(strpos($d['message'], 'the rules of email') !== false, 'the question names the category rules that leave SOUL.md: ' . $d['message']);
            file_put_contents("$tree/pikkumies/local/config.php", "<?php return [];\n");
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set cron.disabled on')]);
            check($d['rule_key'] === cfgHookKey('cron.disabled', false, true) && strpos($d['message'], 'stop running') !== false, 'cron on is asked, with its own reason: ' . json_encode($d));
            $other = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set provider.model m-2')]);
            check($other['rule_key'] !== cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set provider.model m-1')])['rule_key'], 'another model is another question');
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: allowing an SSH host and asking again about unknown hosts are asked; refusing hosts is free (ADR-0068)' => function () {
        $tree = cfgHookTree(['ssh' => ['allow' => [], 'deny' => [], 'blockUnknown' => true]]);
        try {
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set ssh.allow nas.example')]);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === cfgHookKey('ssh.allow', [], ['nas.example'])
                && strpos($d['message'], 'without the safety check') !== false, 'ssh.allow: ' . json_encode($d));
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set ssh.blockUnknown off')]);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === cfgHookKey('ssh.blockUnknown', true, false), 'blockUnknown off: ' . json_encode($d));
            foreach (['set ssh.deny evil.example', 'set ssh.blockUnknown on', 'set ssh.allow none'] as $w) {
                check(cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, $w)]) === null, "$w must get no opinion (free)");
            }
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: `pikkumies configure undo` is the person\'s shell command: blocked from the agent in every form it can see' => function () {
        $tree = cfgHookTree(['lowBalance' => ['disabled' => false]]);
        try {
            file_put_contents("$tree/pikkumies/local/state/configure-changes.jsonl", json_encode(['key' => 'lowBalance.disabled', 'old' => true, 'new' => false]) . "\n");
            foreach ([['terminal', ['command' => 'pikkumies configure undo']], ['terminal', ['command' => "$tree/pikkumies/bin/pikkumies configure undo"]],
                ['process_manage', ['action' => 'submit', 'data' => 'pikkumies configure undo']], ['execute_code', ['code' => 'os.system("pikkumies configure undo")']]] as $w) {
                $d = cfgHook($tree, $w[0], $w[1]);
                check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], "user's own shell command") !== false, json_encode($w) . ': ' . json_encode($d));
            }
            check(cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'undo')]) === null, 'the tool has no undo verb: it refuses it, no opinion here');
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: forms it cannot read, file and shell edits of local/config.php, Config::set and the overlay are asked with the whole command' => function () {
        $tree = cfgHookTree();
        try {
            $whole = [
                ['terminal', ['command' => cfgHookCmd($tree, 'set agent.name x; ls')]],
                ['terminal', ['command' => 'php /tmp/elsewhere/pikkumies-configure.php set agent.name x']], // free with this tree's tool, but another script
                ['terminal', ['command' => cfgHookCmd($tree, 'set provider.model "$(cat /tmp/m)"')]],
                ['execute_code', ['code' => 'import subprocess; subprocess.run(["php", "x/pikkumies-configure.php", "set", "agent.name", "x"])']],
                ['patch', ['patch' => "*** Begin Patch\n*** Update File: /home/u/pikkumies/local/config.php\n@@\n-a\n+b\n*** End Patch"]],
                ['patch', ['patch' => "*** Begin Patch\n*** Move File: /tmp/x.php -> /home/u/pikkumies/local/config.php\n*** End Patch"]],
                ['patch', ['patch' => "***Add File: /home/u/pikkumies/hooks/guard/zz/zz.php\n+<?php"]],
                ['patch', ['patch' => "*** Delete File: /home/u/pikkumies/lib/ConfigureTiers.php"]],
                ['patch', ['path' => '/home/u/pikkumies/tools/system/pikkumies-configure/ConfigureChange.php', 'old_string' => 'a', 'new_string' => 'b']],
                ['write_file', ['path' => '/home/u/pikkumies/lib/Config.php', 'content' => '<?php']],
                ['terminal', ['command' => "sed -i 's/person/free/' ~/pikkumies/lib/ConfigureTiers.php"]],
                ['execute_code', ['code' => '<?php call_user_func("Config::set", "halt.disabled", true);']],
                ['execute_code', ['code' => '<?php call_user_func(["Config", "set"], "halt.disabled", true);']],
                ['execute_code', ['code' => '<?php $f = \'Config::set\'; $f("halt.disabled", true);']],
                ['write_file', ['path' => '/home/u/pikkumies/local/config.php', 'content' => '<?php return [];']],
                ['patch', ['path' => '/home/u/pikkumies//local/./config.php', 'old_string' => 'a', 'new_string' => 'b']],
                ['terminal', ['command' => "sed -i 's/true/false/' ~/pikkumies/local/config.php"]],
                ['terminal', ['command' => 'printf x > ~/pikkumies/local/config.php']],
                ['execute_code', ['code' => '<?php require "lib/Config.php"; Config::set("halt.disabled", true);']],
                ['terminal', ['command' => 'PIKKUMIES_CONFIG=/tmp/o.php pikkumies run']],
                ['terminal', ['command' => 'export PIKKUMIES_CONFIG=/tmp/o.php']],
            ];
            foreach ($whole as $w) {
                $d = cfgHook($tree, $w[0], $w[1]);
                check(is_array($d) && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-config:') === 0, json_encode($w) . ': ' . json_encode($d));
            }
            $quiet = [
                ['terminal', ['command' => 'cat ~/pikkumies/local/config.php']],
                ['terminal', ['command' => 'grep -n model ~/pikkumies/local/config.php']],
                ['write_file', ['path' => '/home/u/notes/config.php.txt', 'content' => 'x']],
                ['terminal', ['command' => 'env | grep ^PIKKUMIES_CONFIG=']],
                ['read_file', ['path' => '/home/u/pikkumies/local/config.php']],
                ['terminal', ['command' => 'ls -la']],
                ['patch', ['patch' => "*** Begin Patch\n*** Update File: /home/u/notes/todo.md\n@@\n-a\n+b\n*** End Patch"]],
                ['terminal', ['command' => 'cat ~/pikkumies/hooks/guard/config-change/README.md']],
                ['terminal', ['command' => 'git config --get user.name']],
            ];
            foreach ($quiet as $q) {
                check(cfgHook($tree, $q[0], $q[1]) === null, json_encode($q) . ' must get no opinion');
            }
        } finally {
            testRmTree($tree);
        }
    },
    // The $ask closure checks the whole built message, not just $shown (the config-change.php $ask helper used to
    // check strlen($shown) alone, missing $what in the count): pad only the command, which is the last thing in the
    // message, so the length grows by exactly one character per padding character.
    'config hook: an askWhole question is asked right up to HookUnits::APPROVE_SHOWN characters, one more is blocked' => function () {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $tree = cfgHookTree();
        try {
            $cmdFor = function ($pad) use ($tree) {
                return cfgHookCmd($tree, 'set agent.name ' . str_repeat('a', $pad)) . '; ls';
            };
            $base = cfgHook($tree, 'terminal', ['command' => $cmdFor(0)]);
            check(is_array($base) && $base['action'] === 'approve', 'base askWhole case not approved: ' . json_encode($base));
            $need350 = HookUnits::APPROVE_SHOWN - strlen($base['message']);
            check($need350 >= 0, 'the base askWhole message is already over the cap; shorten it before padding');
            $d = cfgHook($tree, 'terminal', ['command' => $cmdFor($need350)]);
            check(is_array($d) && $d['action'] === 'approve' && strlen($d['message']) === HookUnits::APPROVE_SHOWN, '350 characters: expected approve: ' . json_encode($d));
            $d = cfgHook($tree, 'terminal', ['command' => $cmdFor($need350 + 1)]);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'too long to show the user in full') !== false, '351 characters: expected block: ' . json_encode($d));
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: an asked key next to set or apply in any form it cannot parse is asked; Configure* called directly is asked (re-review N1)' => function () {
        $tree = cfgHookTree();
        try {
            $c = "$tree/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php";
            $asked = [
                ['terminal', ['command' => 'C=' . $c . '; php "$C" set sessions.retentionDays 1']],
                ['terminal', ['command' => "c=$c; php \$c set sessions.retentionDays 1"]],
                ['execute_code', ['code' => '<?php ConfigureChange::apply(Config::fileConfig(), "sessions.retentionDays", 1);']],
                ['execute_code', ['code' => '<?php $c = "Con" . "fig"; $c::set("hooks.reword", false);']],
                ['execute_code', ['code' => '<?php call_user_func(["ConfigureChange", "apply"], $cfg, $k, $v);']],
                ['execute_code', ['code' => '<?php call_user_func("ConfigureSettings::set", $k, $v);']],
                ['process_manage', ['action' => 'submit', 'data' => 'php $T set cron.disabled off']],
            ];
            foreach ($asked as $w) {
                $d = cfgHook($tree, $w[0], $w[1]);
                check(is_array($d) && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-config:') === 0, json_encode($w) . ': ' . json_encode($d));
            }
            foreach ([['terminal', ['command' => 'grep -rn hooks.reword ~/pikkumies/docs']], ['terminal', ['command' => "c=$c; php \$c set agent.name Kalle"]],
                ['terminal', ['command' => 'echo set the table']]] as $q) {
                check(cfgHook($tree, $q[0], $q[1]) === null, json_encode($q) . ' must get no opinion (no asked key next to set, or a free one)');
            }
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: V4A headers with Unicode spaces, paths through a symlinked directory, and any file under lib/ are asked (re-review N3, N4)' => function () {
        $tree = cfgHookTree();
        try {
            $nb = "\u{a0}";
            symlink("$tree/pikkumies/local", "$tree/l");
            symlink("$tree/pikkumies", "$tree/pk");
            $asked = [
                ['patch', ['patch' => "***{$nb}Update File: $tree/pikkumies/local/config.php\n@@\n-a\n+b"]],
                ['patch', ['patch' => "*** Update{$nb}File: $tree/pikkumies/local/config.php\n@@\n-a\n+b"]],
                ['patch', ['patch' => "*** Update File: $tree/pikkumies/local/config.php{$nb}\n@@\n-a\n+b"]],
                ['write_file', ['path' => "$tree/l/config.php", 'content' => '<?php return [];']],
                ['patch', ['path' => "$tree/pk/hooks/guard/zz/zz.php", 'old_string' => '', 'new_string' => '<?php']],
                ['patch', ['patch' => "*** Update File: $tree/pikkumies/lib/NestedAgent.php\n@@\n-a\n+b"]],
                ['patch', ['patch' => "*** Update File: ~/pikkumies/lib/Cli.php\n@@\n-a\n+b"]], // matched by name too, not only under this tree
            ];
            foreach ($asked as $w) {
                $d = cfgHook($tree, $w[0], $w[1]);
                check(is_array($d) && $d['action'] === 'approve', json_encode($w) . ': ' . json_encode($d));
            }
            check(cfgHook($tree, 'write_file', ['path' => "$tree/l-notes.txt", 'content' => 'x']) === null, 'an ordinary file');
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: a regex engine failure is a block, never an allow (re-review N6)' => function () {
        $tree = cfgHookTree();
        try {
            // No JIT and a backtrack limit of 1: the V4A header scan fails inside PCRE, which must block.
            $cmd = escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -d pcre.backtrack_limit=1 ' . escapeshellarg("$tree/pikkumies/hooks/guard/config-change/config-change.php");
            $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode(['tool_name' => 'patch', 'tool_input' => ['patch' => "*** Update File: /home/u/notes/todo.md\n@@\n-a\n+b"]]));
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'too long or complex') !== false, 'regex failure: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
    'config hook: in an agent started by an agent a question is a block; an unreadable payload blocks; hook.md is a valid unit' => function () {
        $tree = cfgHookTree();
        try {
            $d = cfgHook($tree, 'terminal', ['command' => cfgHookCmd($tree, 'set provider.model m-1')], '2');
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'started by another agent') !== false, 'depth 2: ' . json_encode($d));
            list(, $out) = runPhp("$tree/pikkumies/hooks/guard/config-change/config-change.php", [], 'not json');
            check(json_decode($out, true)['action'] === 'block', 'unreadable payload: ' . $out);
            $units = array_filter(HookUnits::all(dirname(__DIR__) . '/pikkumies/hooks'), function ($u) {
                return $u['name'] === 'config-change';
            });
            $u = reset($units);
            check($u && $u['errors'] === [] && $u['event'] === 'pre_tool_call' && $u['failClosed'] === true, 'unit: ' . json_encode($u ? $u['errors'] : null));
        } finally {
            testRmTree($tree);
        }
    },
];
