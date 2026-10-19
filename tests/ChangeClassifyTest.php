<?php
/** lib/ChangeClassify.php (ADR-0067): which tool calls change data, read from the call; and FileopsClassify::words' new redirects key. */
return [
    'change: shell commands are a change, a read or unknown, by verb, flag, redirect and wrapper' => function () {
        $writes = [
            'cat a > /etc/x', 'echo hi >> ~/.bashrc', 'cmd>out', 'ls &>file', 'echo x >|f', 'echo 1>f', 'sudo rm a', 'ls | xargs rm', 'env X=1 rm f',
            'timeout 5 cp a b', 'nice -n 19 mv a b', 'sudo -u bob rm x', 'for f in *; do rm $f; done', 'sed -i s/a/b/ f', 'perl -pi -e s/a/b/ f',
            'sort -o out in', 'echo x | tee f', 'git commit -m x', 'git branch -D old', 'systemctl --user restart x', 'docker rm c', 'apt-get install x',
            'find . -delete', 'find . -exec rm {} \;', 'tar xzf a.tgz', 'crontab -r', 'kill 123', 'mkdir -p d', 'rsync -a a/ b/',
        ];
        $reads = [
            'ls -la', 'ls 2>/dev/null', 'grep -i x f', 'ls -i', 'rg -i x', "awk '\$1>5' f", "grep '>' f", 'echo "a>b"', 'echo x >&2', 'cat a 2>&1 | head',
            'df -h; du -sh x', 'ps aux | grep x | head', 'git status', 'git -C /x log', 'git branch', 'systemctl status nginx', 'docker ps', 'find . -name x',
            'find . -exec grep x {} +', 'tar tzf a.tgz', 'crontab -l', 'command -v rm', 'echo x | tee /dev/null', 'rsync -an a/ b/', 'cd /tmp && ls', 'sed -n 1p f',
        ];
        $unknown = ["php -r 'if(\$a>1){}'", 'bash -c "ls"', 'unknowncmd x', '$CMD x', 'eval "$x"', 'ls $(pwd)', 'echo `date`', 'python3 x.py', 'git fetch', 'sudo -s'];
        foreach ([[$writes, true], [$reads, false], [$unknown, 'unknown']] as $set) {
            foreach ($set[0] as $cmd) {
                $got = ChangeClassify::command($cmd);
                check($got === $set[1], "$cmd: " . json_encode($got) . ', want ' . json_encode($set[1]));
            }
        }
    },
    'change: tools: file writes change, reads never do, a blocked call never ran, process and schedule actions by kind, code is unknown' => function () {
        $rows = [
            ['write_file', ['path' => 'x', 'content' => 'y'], 'ok', true], ['patch', ['path' => 'x'], 'ok', true], ['write_file', ['path' => 'x'], 'blocked', false],
            ['terminal', ['command' => 'rm -rf x'], 'blocked', false], ['terminal', ['command' => 'rm x'], 'error', true], ['read_file', ['path' => '/etc/passwd'], 'ok', false],
            ['search_files', ['pattern' => 'x'], 'ok', false], ['cronjob_manage', ['action' => 'list'], 'ok', false], ['cronjob_manage', ['action' => 'create'], 'ok', true],
            ['process_manage', ['action' => 'poll'], 'ok', false], ['process_manage', ['action' => 'kill'], 'ok', true],
            ['process_manage', ['action' => 'write', 'data' => "rm -f x\n"], 'ok', true], ['process_manage', ['action' => 'submit', 'data' => 'y'], 'ok', 'unknown'],
            ['execute_code', ['code' => 'print(1)'], 'ok', 'unknown'], ['memory', ['action' => 'add'], 'ok', 'unknown'], ['terminal', 'not an array', 'ok', false],
        ];
        foreach ($rows as $r) {
            $got = ChangeClassify::isChange($r[0], $r[1], $r[2]);
            check($got === $r[3], "{$r[0]} " . json_encode($r[1]) . " ({$r[2]}): " . json_encode($got));
        }
    },
    'change: a Pikkumies tool unit\'s verb is read from its USAGE.md changes column, loading it only for a tool-unit path' => function () {
        $tmp = testTempDir('pk-change-units');
        try {
            @mkdir("$tmp/tools/media/plex", 0700, true);
            file_put_contents("$tmp/tools/media/plex/USAGE.md", "# plex\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n"
                . "| status | | no | shows it |\n| scan | | loads | scans |\n| restart | | yes | restarts |\n| remove | `<id>` | deletes | removes |\n| report | | sends | sends |\n");
            $rows = [['php ~/pikkumies/tools/media/plex/plex.php status', false], ['php ~/pikkumies/tools/media/plex/plex.php --json scan', false],
                ['php tools/media/plex/plex.php restart', true], ['~/pikkumies/tools/media/plex/plex.php remove 7', true], ['php tools/media/plex/plex.php report', true],
                ['php tools/media/plex/plex.php', false], ['php tools/media/plex/plex.php frobnicate', 'unknown'], ['php tools/media/nope/nope.php status', 'unknown'],
                ['php tools/media/plex/other.php status', 'unknown'], ['php tools/../plex/plex.php status', 'unknown']];
            foreach ($rows as $r) {
                $got = ChangeClassify::command($r[0], "$tmp/tools");
                check($got === $r[1], "{$r[0]}: " . json_encode($got) . ', want ' . json_encode($r[1]));
            }
        } finally {
            testRmTree($tmp);
        }
    },
    'change: words() gains redirects with the shell\'s quoting, and its words and complex stay what they were for the fileops fixtures' => function () {
        $rows = ['cat a > /etc/x' => [['>', '/etc/x']], 'echo hi >> ~/.bashrc' => [['>>', '~/.bashrc']], 'ls 2>/dev/null' => [['>', '/dev/null']], 'cmd>out' => [['>', 'out']],
            'echo >|f' => [['>|', 'f']], 'echo x >&2' => [['>&', '2']], "awk '\$1>5' f" => [], 'echo "a>b"' => [], 'cat <<EOF' => [['<<', 'EOF']], 'echo >' => [['>', '']]];
        foreach ($rows as $cmd => $want) {
            $got = FileopsClassify::words($cmd)['redirects'];
            check($got === $want, "$cmd: " . json_encode($got));
        }
        // Taken from FileopsClassify before redirects existed: words, complex and classify() of these commands, as a sha1.
        $cmds = ['chmod -R 755 ~/x', 'cat a > /etc/x', 'ls 2>/dev/null | head', "find . -exec chmod 644 {} \\;", 'mv "a b" c', "echo 'x;y' && rm -rf \$HOME/z",
            'sudo chown -R u:u /srv', 'x=1; cmd>out 2>&1', 'cat <<EOF', "awk '\$1>5' f", 'echo "a>b" | tee -a log', 'ls &>file; echo >|g', "printf '%s\\n' *.txt", '# comment only', "a\\ b\\\nc"];
        $out = [];
        foreach ($cmds as $c) {
            $w = FileopsClassify::words($c);
            $out[] = [$w['words'], $w['complex']];
            $out[] = FileopsClassify::classify($c);
        }
        check(sha1(json_encode($out)) === 'b383322382b6de01b7e5a0e7ac1cf91a9019af0c', 'words()/classify() changed for the fileops fixtures: ' . sha1(json_encode($out)));
    },
];
