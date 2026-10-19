<?php
/**
 * ADR-0076: pikkumies-configure note-add, notes and note-remove. A note is added without a question (the shipped guards
 * give no answer to the plain commands), one dated line each; secret-looking text, an unknown or ambiguous target, an
 * oversize note or file, a symlink on the way and an open local/ are refused with nothing written.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureNotes.php';
if (!function_exists('notesTree')) {
    require __DIR__ . '/LocalNotesTest.php';
}
if (!function_exists('notesRefused')) {
    /** $fn must throw a CliError whose message contains $words, and leave local/tools as it was. */
    function notesRefused($root, callable $fn, $words)
    {
        $before = shell_exec('ls -lR ' . escapeshellarg("$root/local/tools") . ' 2>&1');
        try {
            $fn();
        } catch (CliError $e) {
            check(strpos($e->getMessage(), $words) !== false, 'refused for another reason: ' . $e->getMessage());
            check(shell_exec('ls -lR ' . escapeshellarg("$root/local/tools") . ' 2>&1') === $before, 'a refusal changed local/tools');
            return;
        }
        throw new Exception("not refused (expected: $words)");
    }
}

return [
    'configure notes: note-add, notes and note-remove through the real tool, one dated line each, 0600, logged' => function () {
        $root = notesTree(['torrent-clients/rtorrent', 'email/imap/fetch']);
        $src = dirname(__DIR__) . '/pikkumies';
        try {
            exec('cp -r ' . escapeshellarg("$src/lib") . ' ' . escapeshellarg("$root/lib"));
            @mkdir("$root/tools/system", 0700, true);
            foreach (['pikkumies-configure', 'credits'] as $unit) {
                exec('cp -r ' . escapeshellarg("$src/tools/system/$unit") . ' ' . escapeshellarg("$root/tools/system/$unit"));
            }
            $tool = "$root/tools/system/pikkumies-configure/pikkumies-configure.php";
            file_put_contents(dirname($root) . '/note.txt', "Fetch only\nfolders the user named\n");
            list($code, $out) = runPhp($tool, ['note-add', 'rtorrent', '--text', 'Restart it only through the rtorrent tool']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['path'] === 'tools/torrent-clients/rtorrent' && $l[0]['kind'] === 'unit' && $l[0]['n'] === 1
                && $l[0]['note'] === '- ' . gmdate('Y-m-d') . ': Restart it only through the rtorrent tool' && strpos($l[0]['applies'], 'next start') !== false, $out);
            list($code, $out) = runPhp($tool, ['note-add', 'tools/email/imap/', '--text-file', dirname($root) . '/note.txt']);
            check($code === 0 && cliLines($out)[0]['path'] === 'tools/email/imap' && cliLines($out)[0]['kind'] === 'category', $out);
            runPhp($tool, ['note-add', 'torrent-clients/rtorrent', '--text=Second rule']);
            $file = "$root/local/tools/torrent-clients/rtorrent/DOCTRINE.md";
            $d = gmdate('Y-m-d');
            check(file_get_contents($file) === "- $d: Restart it only through the rtorrent tool\n- $d: Second rule\n", 'file: ' . file_get_contents($file));
            check((fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'modes');
            check(file_get_contents("$root/local/tools/email/imap/DOCTRINE.md") === "- $d: Fetch only folders the user named\n", 'line breaks become spaces');
            list($code, $out) = runPhp($tool, ['notes']);
            $rows = cliLines($out);
            check($code === 0 && count($rows) === 4 && $rows[1]['path'] === 'tools/torrent-clients/rtorrent' && $rows[2]['n'] === 2
                && $rows[2]['state'] === 'waiting for the next start', $out);
            LocalHooks::noteChanges($root); // a start by the user records them
            list(, $out) = runPhp($tool, ['notes', 'rtorrent']);
            $rows = cliLines($out);
            check(count($rows) === 3 && $rows[0]['state'] === 'in use' && $rows[1]['note'] === "- $d: Second rule", $out);
            list($code, $out) = runPhp($tool, ['note-remove', 'rtorrent', '1']);
            $l = cliLines($out);
            check($code === 0 && $l[0]['removed'] === "- $d: Restart it only through the rtorrent tool" && $l[0]['left'] === 1
                && strpos($l[0]['undo'], "note-add torrent-clients/rtorrent --text='Restart it only through the rtorrent tool'") !== false, $out);
            list($code, $out) = runPhp($tool, ['note-remove', 'rtorrent', '2']);
            check($code === 1 && strpos($out, 'has 1 note, no note 2') !== false, $out);
            runPhp($tool, ['note-remove', 'rtorrent', '1']);
            check(!file_exists($file) && !file_exists(dirname($file)) && is_dir("$root/local/tools"), 'the last note takes its file and empty directories');
            $log = array_map('json_decode', array_values(array_filter(explode("\n", file_get_contents("$root/local/state/note-changes.jsonl")))));
            check(count($log) === 5 && $log[3]->verb === 'remove' && $log[3]->note === "- $d: Restart it only through the rtorrent tool"
                && (fileperms("$root/local/state/note-changes.jsonl") & 0777) === 0600, 'log');
            list($code, $out) = runPhp($tool, ['note-add', 'rtorrent']);
            check($code === 1 && strpos($out, 'either --text or --text-file') !== false, $out);
        } finally {
            testRmTree(dirname($root));
        }
    },
    'configure notes: a note that looks like a password, key or token is refused and nothing is written' => function () {
        $root = notesTree(['torrent-clients/rtorrent']);
        // Built from parts, so this file holds no secret-shaped literal.
        $secrets = [
            'api' . '_key=' . 'abcd1234efgh5678',
            'pass' . 'word: ' . 'hunter2hunter2',
            'use s' . 'k-' . str_repeat('ab12', 5) . ' for it',
            'gh' . 'p_' . str_repeat('aB3', 11),
            'the tracker is https://tracker.example.org/announce?pass' . 'key=' . str_repeat('0a', 10),
            'curl -u admin:' . 's3cret' . 'value https://example.org',
            '-----BEGIN OPENSSH ' . 'PRIVATE KEY-----' . "\nAAAAB3NzaC1yc2E\n-----END OPENSSH " . 'PRIVATE KEY-----',
        ];
        try {
            ConfigureNotes::add('rtorrent', 'A first, harmless rule', $root);
            foreach ($secrets as $text) {
                notesRefused($root, function () use ($root, $text) {
                    ConfigureNotes::add('rtorrent', $text, $root);
                }, 'looks like it holds a password, key or token');
            }
            check(count(LocalNotes::entries(file_get_contents("$root/local/tools/torrent-clients/rtorrent/DOCTRINE.md"))) === 1, 'a secret was written');
            ConfigureNotes::add('rtorrent', 'Keep the monkey keyboard bypass compass words: they are not secrets', $root);
        } finally {
            testRmTree(dirname($root));
        }
    },
    'configure notes: an unknown or ambiguous target, an oversize note or file, a symlink on the way and an open local/ are refused' => function () {
        $root = notesTree(['torrent-clients/rtorrent', 'media/fetch', 'email/fetch']);
        $elsewhere = dirname($root) . '/elsewhere';
        try {
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('no-such-tool', 'x', $root);
            }, 'no shipped tool or tool category named "no-such-tool"');
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('fetch', 'x', $root);
            }, 'no shipped tool or tool category named "fetch"');
            check(ConfigureNotes::target('media/fetch', $root) === ['media/fetch', 'unit'], 'category/tool picks one of two same-named tools');
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('rtorrent', str_repeat('x', LocalNotes::NOTE_MAX + 1), $root);
            }, 'one note is at most');
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('rtorrent', " \n\t ", $root);
            }, 'the note is empty');
            for ($i = 0; $i < 8; $i++) {
                ConfigureNotes::add('torrent-clients', str_repeat('y', LocalNotes::NOTE_MAX - 20), $root);
            }
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('torrent-clients', str_repeat('y', LocalNotes::NOTE_MAX - 20), $root);
            }, 'would pass ' . HermesConfig::DOCTRINE_MAX . ' bytes');
            mkdir($elsewhere, 0700);
            symlink($elsewhere, "$root/local/tools/media");
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('media/fetch', 'x', $root);
            }, 'is a symlink or not this account\'s directory');
            check(scandir($elsewhere) === ['.', '..'], 'a note was written through a symlink');
            unlink("$root/local/tools/media");
            chmod("$root/local", 0755);
            notesRefused($root, function () use ($root) {
                ConfigureNotes::add('rtorrent', 'x', $root);
            }, 'open to others');
            notesRefused($root, function () use ($root) {
                ConfigureNotes::notes(null, $root);
            }, 'local notes are off');
        } finally {
            @chmod("$root/local", 0700);
            testRmTree(dirname($root));
        }
    },
    'configure notes: the shipped guards give no answer to note-add or note-remove, so no question reaches the user' => function () {
        $guards = dirname(__DIR__) . '/pikkumies/hooks/guard';
        $tool = 'php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php';
        foreach (["$tool note-add rtorrent --text=\"Restart it only through the rtorrent tool\"", "$tool note-add torrent-clients --text-file /tmp/notes1/note.txt",
            "$tool notes", "$tool note-remove rtorrent 1"] as $cmd) {
            foreach (['guard-destructive', 'config-change'] as $hook) {
                list($code, $out) = runPhp("$guards/$hook/$hook.php", [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
                check($code === 0 && trim($out) === '', "$hook answered $cmd: $out");
            }
        }
    },
];
