<?php
/**
 * The backup safety layer's hooks (ADR-0099): the delete guard's backup check (copy first without a working backup,
 * the latest-backups table before a dangerous delete, once per command per session, snapped targets and scratch files
 * pass, installs and appends untouched) and the pre-edit copies (private folders, symlinks refused, size and session caps).
 */
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/GuardBackup.php';
$_bsHookScript = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : null;
$_SERVER['SCRIPT_FILENAME'] = __FILE__; // keep the hook's own entry point from running when it is required here
require_once dirname(__DIR__) . '/pikkumies/hooks/guard/pre-edit-copy/pre-edit-copy.php';
$_SERVER['SCRIPT_FILENAME'] = $_bsHookScript;

if (!function_exists('bshCache')) {
    /** Writes a status cache with one restic method in $state, holding $n backups. */
    function bshCache($local, $state, $n, $now)
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['name' => "bk-$i", 'time' => $now - 3600 * ($i + 1), 'bytes' => 2048];
        }
        $m = ['kind' => 'restic', 'where' => 'sftp:u@box:r', 'latest_at' => $now - 3600, 'latest_bytes' => 2048, 'prev_bytes' => 2048,
            'verified_at' => $now - 60, 'verify_ok' => true, 'error' => $state === 'broken' ? 'unreachable' : null, 'backups' => $rows];
        BackupStatus::write($local, BackupStatus::judge([$m], $now));
    }

    /** guardBackup() for a terminal command with HOME set to $home. */
    function bshGuard($cmd, $why, $local, $home, $session = 's1', $now = 1792483200)
    {
        return testWithEnv(['HOME' => $home], function () use ($cmd, $why, $local, $session, $now) {
            return guardBackup($cmd, $why, ['session_id' => $session, 'tool_input' => ['command' => $cmd]], $local, $now);
        });
    }

    /** A copy of lib, the delete guard and config: the guard writes its state into this tree's local/. */
    function bshTree()
    {
        $t = testTempDir('pk-bsh-tree');
        $src = dirname(__DIR__) . '/pikkumies';
        mkdir("$t/pikkumies/hooks/guard", 0700, true);
        mkdir("$t/pikkumies/local", 0700, true);
        mkdir("$t/fixturehome", 0700, true);
        exec('cp -a ' . escapeshellarg("$src/lib") . ' ' . escapeshellarg("$src/config") . ' ' . escapeshellarg("$t/pikkumies/")
            . ' && cp -a ' . escapeshellarg("$src/hooks/guard/guard-destructive") . ' ' . escapeshellarg("$t/pikkumies/hooks/guard/"));
        return $t;
    }

    /** The delete guard's decoded answer in a tree, or null. */
    function bshRun($tree, array $payload)
    {
        return testWithEnv(['HOME' => "$tree/fixturehome"], function () use ($tree, $payload) {
            list(, $out) = runPhp("$tree/pikkumies/hooks/guard/guard-destructive/guard-destructive.php", [], json_encode($payload));
            return json_decode($out, true);
        });
    }
}

$bshRm = 'deletes or empties files';
return [
    'backup guard: a removal without a working backup is held once with the snap command, and the identical retry goes on' => function () use ($bshRm) {
        $t = testTempDir('pk-bsh');
        try {
            mkdir("$t/fixturehome", 0700);
            $a = bshGuard('rm ~/notes.txt', $bshRm, "$t/local", "$t/fixturehome");
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], "snap '$t/fixturehome/notes.txt'") !== false
                && strpos($a['message'], 'not checked yet') !== false && strpos($a['message'], 'pulsedmedia.com/storage-boxes.php') !== false, 'first: ' . json_encode($a));
            check(strpos($a['message'], 'table of the 10 latest') === false, 'a plain rm is not dangerous');
            check(bshGuard('rm ~/notes.txt', $bshRm, "$t/local", "$t/fixturehome") === null, 'the identical retry must go on');
            check(is_array(bshGuard('rm ~/other.txt', $bshRm, "$t/local", "$t/fixturehome")), 'another command is held on its own');
            check(is_array(bshGuard('rm ~/notes.txt', $bshRm, "$t/local", "$t/fixturehome", 's2')), 'another session is held on its own');
            check(bshGuard('rm ~/notes.txt', $bshRm, "$t/local", "$t/fixturehome", '') === null, 'no session id: no hold (the question still comes)');
            bshCache("$t/local", 'broken', 1, 1792483200);
            $b = bshGuard('rm ~/x.txt', $bshRm, "$t/local", "$t/fixturehome");
            check(is_array($b) && strpos($b['message'], 'No working backup (broken)') !== false, 'broken state: ' . json_encode($b));
        } finally {
            testRmTree($t);
        }
    },
    'backup guard: a dangerous delete shows the 10 latest backups once, also with a working backup; a plain removal then passes' => function () use ($bshRm) {
        $t = testTempDir('pk-bsh');
        try {
            mkdir("$t/fixturehome", 0700);
            bshCache("$t/local", 'working', 12, 1792483200);
            check(bshGuard('rm ~/notes.txt', $bshRm, "$t/local", "$t/fixturehome") === null, 'a working backup: a plain rm is not held');
            $a = bshGuard('rm -rf ~/olddir', $bshRm, "$t/local", "$t/fixturehome");
            check(is_array($a) && strpos($a['message'], 'dangerous change') !== false && strpos($a['message'], '| bk-0 |') !== false
                && strpos($a['message'], '| bk-9 |') !== false && strpos($a['message'], 'bk-10') === false && strpos($a['message'], 'snap ') === false,
                'table: ' . json_encode($a));
            check(bshGuard('rm -rf ~/olddir', $bshRm, "$t/local", "$t/fixturehome") === null, 'the retry goes on');
            $p = bshGuard('apt-get purge nginx', 'removes software packages and, with purge, their settings', "$t/local", "$t/fixturehome");
            check(is_array($p) && strpos($p['message'], 'dangerous change') !== false, 'package removal is dangerous: ' . json_encode($p));
            $r = bshGuard('rclone purge remote:old', 'rclone can delete or overwrite files', "$t/local", "$t/fixturehome");
            check(is_array($r) && strpos($r['message'], '| bk-0 |') !== false, 'every other delete rule is dangerous: ' . json_encode($r));
            check(strpos(guardBackupLatest("$t/local", 1792483200), 'Latest backup: 1 h ago, 2.0 KiB (restic).') !== false, 'question suffix');
            // An unconfirmed remote key is a line for the user in the table, never an input to the hold (ADR-0099).
            $c = BackupStatus::read("$t/local");
            $c['methods'][] = ['kind' => 'remote', 'where' => 'u@box', 'state' => 'working', 'passphrase_saved_by_user' => false, 'backups' => []];
            BackupStatus::write("$t/local", $c);
            check(bshGuard('rm ~/plain.txt', $bshRm, "$t/local", "$t/fixturehome") === null, 'an unconfirmed key must not make a plain rm wait');
            $k = bshGuard('rm -r ~/keydir', $bshRm, "$t/local", "$t/fixturehome");
            check(is_array($k) && strpos($k['message'], 'backup passphrase not confirmed saved') !== false && strpos($k['message'], 'No working backup') === false,
                'the custody line rides in the table only: ' . json_encode($k));
        } finally {
            testRmTree($t);
        }
    },
    'backup guard: a removal of files snapped in the last 30 minutes, or only of scratch files outside the home, is not held' => function () use ($bshRm) {
        $t = testTempDir('pk-bsh');
        try {
            mkdir("$t/fixturehome/d", 0700, true);
            file_put_contents("$t/fixturehome/d/a.txt", 'a');
            $now = 1792483200;
            BackupHold::saveRecent("$t/local", [realpath("$t/fixturehome/d") => $now - 600, 'package:nginx' => $now - 60]);
            check(bshGuard('rm ~/d/a.txt', $bshRm, "$t/local", "$t/fixturehome") === null, 'a file inside a snapped folder must pass');
            check(bshGuard('apt-get remove nginx', 'removes software packages and, with purge, their settings', "$t/local", "$t/fixturehome") !== null,
                'a package removal stays dangerous even when snapped (the table)');
            BackupHold::saveRecent("$t/local", [realpath("$t/fixturehome/d") => $now - 3600]);
            check(is_array(bshGuard('rm ~/d/a.txt', $bshRm, "$t/local", "$t/fixturehome", 's9')), 'a snap older than 30 minutes does not cover');
            $scratch = testTempDir('pk-bsh-scratch');
            touch("$scratch/x");
            check(bshGuard("rm $scratch/x", $bshRm, "$t/local", "$t/fixturehome", 's10') === null, 'a scratch file outside the home needs no copy');
            // cwe F9: truncate and shred follow a link, so a /tmp link into the home is not scratch.
            file_put_contents("$t/fixturehome/data.db", 'd');
            symlink("$t/fixturehome/data.db", "$scratch/link");
            check(is_array(bshGuard("truncate -s 0 $scratch/link", $bshRm, "$t/local", "$t/fixturehome", 's11')), 'a /tmp link into the home was treated as scratch');
            testRmTree($scratch);
        } finally {
            testRmTree($t);
        }
    },
    'backup guard: through the real guard, apt-get remove is a held delete; install, appends and a copy are untouched' => function () {
        $tree = bshTree();
        try {
            $call = function ($cmd, $session = 'sess') use ($tree) {
                return bshRun($tree, ['session_id' => $session, 'tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]);
            };
            foreach (['apt-get install nginx', 'apt install -y curl', 'echo "x=1" >> ~/.config/app.conf', 'cp ~/a ~/b.new', 'dpkg -l nginx'] as $cmd) {
                check($call($cmd) === null, "$cmd must stay silent: " . json_encode($call($cmd)));
            }
            $a = $call('apt-get remove nginx');
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'Pikkumies backup check') === 0 && strpos($a['message'], '--package') !== false,
                'first: ' . json_encode($a));
            $b = $call('apt-get remove nginx');
            check(is_array($b) && $b['action'] === 'approve' && $b['kind'] === 'delete' && strpos($b['message'], 'removes software packages') !== false,
                'the retry must reach the delete question: ' . json_encode($b));
            $c = bshRun($tree, ['tool_name' => 'terminal', 'tool_input' => ['command' => 'rm ~/x']]);
            check(is_array($c) && $c['action'] === 'approve', 'without a session the delete question is unchanged: ' . json_encode($c));
            check(is_dir("$tree/pikkumies/local/state/backup-hold"), 'the hold marker lives in the tree\'s local/state');
        } finally {
            testRmTree($tree);
        }
    },
    'pre-edit copy: sed -i, a > redirect and write_file copy the old file into local/tmp/pre-edit; >> and new files do not' => function () {
        $t = testTempDir('pk-pec');
        $uid = posix_geteuid();
        try {
            mkdir("$t/fixturehome", 0700);
            mkdir("$t/local", 0700);
            file_put_contents("$t/fixturehome/app.conf", "v=1\n");
            chmod("$t/fixturehome/app.conf", 0644);
            $run = function ($tool, array $in, $session = 'sx') use ($t, $uid) {
                return testWithEnv(['HOME' => "$t/fixturehome"], function () use ($tool, $in, $session, $t, $uid) {
                    return preEditCopy(['session_id' => $session, 'tool_name' => $tool, 'tool_input' => $in], "$t/local", $uid, 1792483200);
                });
            };
            $made = $run('terminal', ['command' => "sed -i -e 's/1/2/' ~/app.conf"]);
            $copy = "$t/local/tmp/pre-edit/sx" . realpath("$t/fixturehome/app.conf");
            check($made === [$copy] && file_get_contents($copy) === "v=1\n" && !file_exists("$copy.part"), 'sed -i: ' . json_encode($made));
            check((fileperms($copy) & 0777) === 0600, sprintf('copy mode %o', fileperms($copy) & 0777));
            foreach (["$t/local/tmp", "$t/local/tmp/pre-edit", "$t/local/tmp/pre-edit/sx"] as $d) {
                check((fileperms($d) & 0777) === 0700, sprintf('%s mode %o', $d, fileperms($d) & 0777));
            }
            check((int) file_get_contents("$t/local/tmp/pre-edit/sx/.used") === 4, 'the bytes copied are counted');
            check($run('terminal', ['command' => "sed -i -e 's/1/2/' ~/app.conf"]) === [], 'the same content is kept once');
            file_put_contents("$t/fixturehome/app.conf", "v=2\n");
            check(count($run('terminal', ['command' => 'echo v=3 > ~/app.conf'])) === 1, 'a > redirect of changed content: a second copy');
            check($run('terminal', ['command' => 'echo v=4 >> ~/app.conf']) === [], 'an append is not a rewrite');
            check($run('terminal', ['command' => 'sed -n p ~/app.conf']) === [], 'sed without -i rewrites nothing');
            check($run('write_file', ['path' => "$t/fixturehome/new.conf"]) === [], 'a new file has nothing to copy');
            check(count($run('write_file', ['path' => "$t/fixturehome/app.conf"], 'sy')) === 1, 'write_file of an existing file is copied');
            check(strpos((string) @file_get_contents("$t/local/state/hook-notes/" . sha1('sx') . '.jsonl'), 'tmp/pre-edit/sx') !== false
                || count((array) glob("$t/local/state/hook-notes/*")) > 0, 'the session note was not left');
            check(!is_dir("/tmp/pk-$uid/pre-edit/sx"), 'nothing goes to the shared /tmp any more');
        } finally {
            testRmTree($t);
        }
    },
    'pre-edit copy: a symlinked or foreign tmp folder turns copies off with one note; a file over 1 MiB, a full session and a dot session id copy nothing' => function () {
        $t = testTempDir('pk-pec');
        $uid = posix_geteuid();
        try {
            mkdir("$t/fixturehome", 0700);
            mkdir("$t/local", 0700);
            mkdir("$t/evil", 0700); // closed like a real private folder, so only the link check can refuse it
            file_put_contents("$t/fixturehome/a.conf", "a\n");
            $run = function ($session = 'sz', $file = 'a.conf') use ($t, $uid) {
                return testWithEnv(['HOME' => "$t/fixturehome"], function () use ($session, $file, $t, $uid) {
                    return preEditCopy(['session_id' => $session, 'tool_name' => 'write_file', 'tool_input' => ['path' => "$t/fixturehome/$file"]], "$t/local", $uid, 1792483200);
                });
            };
            symlink("$t/evil", "$t/local/tmp");
            check($run() === [] && count(scandir("$t/evil")) === 2, 'a symlinked local/tmp must not be followed');
            $notes = (string) @file_get_contents("$t/local/state/hook-notes/" . sha1('sz') . '.jsonl');
            check(substr_count($notes, 'Pre-edit copies are off') === 1, 'one note that copies are off: ' . $notes);
            $run();
            check(substr_count((string) @file_get_contents("$t/local/state/hook-notes/" . sha1('sz') . '.jsonl'), 'Pre-edit copies are off') === 1,
                'the off note comes once per session');
            unlink("$t/local/tmp");
            check($run('..') === [] && $run('.') === [], 'a dot session id is not a folder name');
            file_put_contents("$t/fixturehome/big.conf", str_repeat('x', 1048577));
            check($run('sz', 'big.conf') === [], 'a file over 1 MiB is not copied');
            check(count($run()) === 1, 'the ordinary case copies');
            file_put_contents("$t/local/tmp/pre-edit/sz/.used", (string) 52428799);
            file_put_contents("$t/fixturehome/a.conf", "b\n");
            check($run() === [], 'a session at its 50 MB cap copies nothing more');
            chmod("$t/local/tmp", 0755);
            check(count($run('sw')) === 1 && (fileperms("$t/local/tmp") & 0777) === 0700, 'a folder of this account left open is closed before use');
        } finally {
            testRmTree($t);
        }
    },
    'pre-edit copy: the copy is of the file that was checked (same inode), whole, and named only once complete' => function () {
        $t = testTempDir('pk-pec');
        try {
            file_put_contents("$t/a", "aaaa\n");
            file_put_contents("$t/b", "bb\n");
            check(preEditCopyOpen("$t/a", stat("$t/b"), "$t/x.part") === null && !file_exists("$t/x.part"), 'another inode than the checked one must not be copied');
            check(preEditCopyOpen("$t/a", stat("$t/a"), "$t/y.part") === 5 && file_get_contents("$t/y.part") === "aaaa\n", 'the checked file is copied whole');
            file_put_contents("$t/z.part", 'planted');
            check(preEditCopyOpen("$t/a", stat("$t/a"), "$t/z.part") === null && file_get_contents("$t/z.part") === 'planted', 'never over an existing name');
            $st = stat("$t/a");
            $st['size'] = 4; // the file grew after stat(): the copy must match what the opened file holds, read through fstat
            check(preEditCopyOpen("$t/a", $st, "$t/w.part") === 5, 'the size comes from the opened file');
        } finally {
            testRmTree($t);
        }
    },
    'pre-edit copy: root and sudo are the same rule: a sudo sed -i of another account\'s file is copied through sudo -n cat; without sudo it is not' => function () {
        $t = testTempDir('pk-pec');
        $uid = posix_geteuid();
        $target = '/etc/passwd'; // owned by root and readable on every test host; nothing writes it
        try {
            mkdir("$t/local", 0700);
            if ($uid === 0 || @fileowner($target) === $uid || @filesize($target) > 1048576) {
                skip('needs a small file of another owner');
            }
            $calls = [];
            $fake = function ($argv) use (&$calls, $target) {
                $calls[] = $argv;
                return ['code' => 0, 'out' => (string) file_get_contents($target), 'err' => ''];
            };
            $copy = function ($cmd, $session) use ($t, $uid, $fake) {
                return preEditCopy(['session_id' => $session, 'tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]], "$t/local", $uid, 1792483200, $fake);
            };
            check($copy("sed -i 's/x/y/' $target", 's1') === [] && !$calls, 'without sudo another account\'s file is not copied');
            $made = $copy("sudo sed -i 's/x/y/' $target", 's2');
            check($calls && $calls[0] === ['sudo', '-n', '--', 'cat', '--', $target], 'copied through sudo -n cat: ' . json_encode($calls));
            check(count($made) === 1 && file_get_contents($made[0]) === file_get_contents($target) && (fileperms($made[0]) & 0777) === 0600, 'sudo copy: ' . json_encode($made));
            $no = function () {
                return ['code' => 1, 'out' => '', 'err' => 'sudo: a password is required'];
            };
            check(preEditCopy(['session_id' => 's3', 'tool_name' => 'terminal', 'tool_input' => ['command' => "sudo truncate -s 0 $target"]], "$t/local", $uid, 1792483200, $no) === [],
                'no passwordless sudo: no copy, nothing half-made');
            check(!glob("$t/local/tmp/pre-edit/s3/etc/*"), 'no .part left behind');
        } finally {
            testRmTree($t);
        }
    },
    'pre-edit copy: the hook itself prints nothing and exits 0' => function () {
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/pre-edit-copy/pre-edit-copy.php', [],
            json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => 'ls']]));
        check($code === 0 && $out === '', "exit $code, output: $out");
    },
];
