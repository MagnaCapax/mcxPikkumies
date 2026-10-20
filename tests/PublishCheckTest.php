<?php
/**
 * ADR-0083: hooks/guard/publish-check/. Each test builds a throwaway git repository whose remote is a URL that is never
 * contacted (nothing is pushed: only the hook reads the repository) and calls publishCheckAnswer() in this process with
 * the visibility lookup stubbed, so no test uses the network. Fixture secrets are built at run time, so no
 * credential-shaped literal sits in this file.
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/publish-check/publish-check.php';

if (!function_exists('pubGit')) {
    function pubGit($dir, $args)
    {
        exec('git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@example.invalid -c commit.gpgsign=false ' . $args . ' 2>&1', $out, $code);
        check($code === 0, "git $args: " . implode("\n", $out));
        return trim(implode("\n", $out));
    }

    /** A token in a well-known form Redact masks: the GitHub prefix and 36 random letters and digits. */
    function pubToken()
    {
        return 'gh' . 'p_' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 2)), 0, 36);
    }

    /**
     * A repository at <tree>/repo with remote origin $url: one commit the remote already has (refs/remotes/origin/main),
     * holding $old, then one it does not, adding $file with $new. Returns the tree.
     */
    function pubRepo($url, $file, $new, $old = "readme\n")
    {
        if (ToolProc::which('git') === null) {
            skip('git is not installed here');
        }
        $tree = testTempDir('pk-publish');
        mkdir("$tree/repo");
        mkdir("$tree/home");
        mkdir("$tree/local");
        pubGit("$tree/repo", 'init -q -b main');
        file_put_contents("$tree/repo/README.md", $old);
        pubGit("$tree/repo", 'add README.md');
        pubGit("$tree/repo", 'commit -q -m first');
        pubGit("$tree/repo", 'remote add origin ' . escapeshellarg($url));
        pubGit("$tree/repo", 'update-ref refs/remotes/origin/main HEAD');
        file_put_contents("$tree/repo/$file", $new);
        pubGit("$tree/repo", 'add ' . escapeshellarg($file));
        pubGit("$tree/repo", 'commit -q -m second');
        return $tree;
    }

    /** The hook's answer for a terminal $command run in the repository, with visibility $seen; $calls records each lookup. */
    function pubAnswer($tree, $command, $seen, &$calls, $session = 's1')
    {
        $calls = [];
        $stub = function ($host, $slug, $dir) use ($seen, &$calls) {
            $calls[] = "$host $slug";
            return $seen;
        };
        $payload = ['tool_name' => 'terminal', 'tool_input' => ['command' => $command, 'workdir' => "$tree/repo"], 'session_id' => $session];
        return publishCheckAnswer($payload, "$tree/local", "$tree/home", $stub);
    }
}

return [
    'publish-check: a push to a private GitHub repository is allowed without reading it, a token in the commit included' => function () {
        $tree = pubRepo('https://github.com/example/demo.git', 'app.conf', 'X=' . pubToken() . "\napi_key = " . pubToken() . "\n");
        $d = pubAnswer($tree, 'git push origin main', 'private', $calls);
        check($d === null, 'a private push was held: ' . json_encode($d));
        check($calls === ['github.com example/demo'], 'visibility not asked for the remote: ' . json_encode($calls));
        testRmTree($tree);
    },
    'publish-check: a public push with a token is blocked once with the value masked, then asked as a send' => function () {
        $token = pubToken();
        $tree = pubRepo('git@github.com:example/demo.git', 'deploy.sh', "export GITHUB_TOKEN=$token\n");
        $d = pubAnswer($tree, 'git push', 'public', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'github.com/example/demo (public)') !== false
            && strpos($d['message'], 'deploy.sh:1: export GITHUB_TOKEN=REDACTED') !== false && strpos($d['message'], 'run the same command again') !== false,
            'not blocked with the masked line: ' . json_encode($d));
        check(strpos(json_encode($d), substr($token, 4)) === false, 'the token value is in the message');
        $again = pubAnswer($tree, 'git push', 'public', $calls);
        check(is_array($again) && $again['action'] === 'approve' && $again['kind'] === 'send' && strpos($again['rule_key'], 'pikkumies-publish:') === 0
            && HookUnits::approveShown($again['message']) && strpos($again['message'], substr($token, 4)) === false, 'the re-run is not a send question: ' . json_encode($again));
        $other = pubAnswer($tree, 'git push', 'public', $calls, 's2');
        check(is_array($other) && $other['action'] === 'block', 'another session skipped the block: ' . json_encode($other));
        testRmTree($tree);
    },
    'publish-check: a clean public push is allowed; what the remote already has is not read again' => function () {
        $tree = pubRepo('https://github.com/example/demo', 'main.php', "<?php echo 'hello';\n", 'old_password = ' . pubToken() . "\n");
        $d = pubAnswer($tree, 'cd ' . escapeshellarg("$tree/repo") . ' && git push origin main', 'public', $calls);
        check($d === null, 'a clean push was held: ' . json_encode($d));
        // A remote with no remote-tracking branches gets everything: the old commit is read too.
        pubGit("$tree/repo", 'remote add mirror https://codeberg.org/example/demo.git');
        $d = pubAnswer($tree, 'git push mirror main', 'unknown', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'README.md:1: old_password = REDACTED') !== false, 'a new remote did not get the whole history read: ' . json_encode($d));
        testRmTree($tree);
    },
    'publish-check: a tracked .env is refused by its name; .env.example is not' => function () {
        $tree = pubRepo('https://github.com/example/demo.git', '.env', "COLOR=blue\n");
        $d = pubAnswer($tree, 'git push -u origin main', 'public', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'adds .env: a file named like a credential file') !== false, '.env not refused: ' . json_encode($d));
        testRmTree($tree);
        $tree = pubRepo('https://github.com/example/demo.git', '.env.example', "COLOR=blue\n");
        check(pubAnswer($tree, 'git push origin main', 'public', $calls) === null, '.env.example was refused');
        foreach (['id_ed25519', 'server.pem', 'credentials.json', 'cert.p12'] as $name) {
            check(PublishScan::credentialName("a/$name"), "$name is not a credential file name");
        }
        foreach (['id_ed25519.pub', 'credentials.php', 'keyboard.txt', 'README.md'] as $name) {
            check(!PublishScan::credentialName($name), "$name counted as a credential file name");
        }
        testRmTree($tree);
    },
    'publish-check: a gh issue comment with a password in the body is refused; a private repository is not read' => function () {
        $tree = pubRepo('https://github.com/example/demo.git', 'a.txt', "a\n");
        $pw = 'hun' . 'ter2' . mt_rand(1000, 9999);
        $command = "gh issue comment 12 --repo example/demo --body \"it fails with db_password=$pw on start\"";
        $d = pubAnswer($tree, $command, 'public', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'db_password=REDACTED') !== false && strpos($d['message'], $pw) === false,
            'the comment was not refused with the value masked: ' . json_encode($d));
        check($calls === ['github.com example/demo'], 'visibility not asked for -R: ' . json_encode($calls));
        check(pubAnswer($tree, $command, 'private', $calls) === null, 'a comment in a private repository was held');
        file_put_contents("$tree/repo/notes.md", "token: " . pubToken() . "\n");
        $d = pubAnswer($tree, 'gh gist create notes.md', 'private', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'notes.md line 1: token: REDACTED') !== false && $calls === [], 'a gist file was not read: ' . json_encode($d));
        testRmTree($tree);
    },
    'publish-check: unknown visibility counts as public, and with no session the block stays' => function () {
        $tree = pubRepo('https://github.com/example/demo.git', 'cfg.yml', 'secret: ' . pubToken() . "\n");
        $d = pubAnswer($tree, 'git push', 'unknown', $calls);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'visibility unknown, treated as public') !== false
            && strpos($d['message'], 'cfg.yml:1: secret: REDACTED') !== false, 'unknown visibility was not scanned: ' . json_encode($d));
        for ($i = 0; $i < 2; $i++) {
            $d = pubAnswer($tree, 'git push', 'unknown', $calls, '');
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'A yes cannot be checked here') !== false, 'no session: ' . json_encode($d));
        }
        testRmTree($tree);
    },
    'publish-check: a push to a remote that is no public hosting service, ssh, scp and rsync are never read' => function () {
        $tree = pubRepo('git@backup.example.net:me/demo.git', 'cfg.yml', 'secret: ' . pubToken() . "\n");
        foreach (['git push', 'git push origin main', 'git push ssh://git@backup.example.net/me/demo.git main', 'git push ../other main',
            'rsync -a . me@github.com:x/', 'scp .env me@backup.example.net:', 'ssh me@backup.example.net "git push"', 'git push --dry-run'] as $command) {
            $d = pubAnswer($tree, $command, 'public', $calls);
            check($d === null && $calls === [], "$command was looked at: " . json_encode($d) . json_encode($calls));
        }
        // An ~/.ssh/config alias for github.com is GitHub.
        mkdir("$tree/home/.ssh");
        file_put_contents("$tree/home/.ssh/config", "Host gh-work\n  HostName github.com\n  User git\n");
        pubGit("$tree/repo", 'remote set-url origin gh-work:example/demo.git');
        $d = pubAnswer($tree, 'git push', 'public', $calls);
        check(is_array($d) && $d['action'] === 'block' && $calls === ['github.com example/demo'], 'an alias for github.com was not read: ' . json_encode($calls));
        testRmTree($tree);
    },
    'publish-check: the script answers nothing for other commands and blocks a payload it cannot read' => function () {
        $script = dirname(__DIR__) . '/pikkumies/hooks/guard/publish-check/publish-check.php';
        list(, $out) = runPhp($script, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls -la']]));
        check(trim($out) === '', 'an ordinary command got an answer: ' . $out);
        list(, $out) = runPhp($script, [], 'not json');
        $d = json_decode($out, true);
        check(is_array($d) && $d['action'] === 'block', 'an unreadable payload was not blocked: ' . $out);
    },
];
