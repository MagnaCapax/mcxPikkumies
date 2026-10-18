<?php
/**
 * guard-destructive on Hermes' V4A patches (ADR-0020 amendment 2026-10-19): the `patch` tool's V4A form has no `path`
 * and names its files in `*** Update/Add/Delete File:` and `*** Move File: a -> b` headers. Each named file is checked
 * like a path against the stored-password and AI-key rules, with the same answer; a harmless patch gets no opinion.
 */
if (!function_exists('guardPatchRun')) {
    /** The guard's decoded answer for one tool call (null for no answer); $ini are extra -d settings for the PHP run. */
    function guardPatchRun($tool, array $input, array $ini = [])
    {
        $cmd = escapeshellarg(PHP_BINARY);
        foreach ($ini as $k => $v) {
            $cmd .= ' -d ' . escapeshellarg("$k=$v");
        }
        $cmd .= ' ' . escapeshellarg(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php');
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['tool_name' => $tool, 'tool_input' => $input]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        check($code === 0, "guard exited $code: $out");
        return trim($out) === '' ? null : json_decode($out, true);
    }

    /** The answer for a V4A patch with these lines. */
    function guardPatchV4a(array $lines, array $ini = [])
    {
        return guardPatchRun('patch', ['mode' => 'patch', 'patch' => implode("\n", $lines)], $ini);
    }
}

return [
    'guard patch: a V4A Update, Add, Delete or Move of the AI key or a stored password gets the path form\'s answer' => function () {
        foreach (['~/.pikkumiesKey', '/home/u/.virsilipasKey', '~/pikkumies/local/secrets/sonarr', '/home/u/.mcxPikkumies/pikkumies/local/hermes//.env'] as $file) {
            $want = guardPatchRun('patch', ['path' => $file, 'old_string' => 'a', 'new_string' => 'b']);
            check(is_array($want) && $want['action'] === 'block', "path form of $file must block: " . json_encode($want));
            $forms = [
                ['*** Begin Patch', "*** Update File: $file", '@@', '-a', '+b', '*** End Patch'],
                ['*** Begin Patch', "*** Add File: $file", '+b', '*** End Patch'],
                ['*** Begin Patch', "*** Delete File: $file", '*** End Patch'],
                ['*** Begin Patch', "*** Move File: $file -> /home/u/notes/plain.txt", '*** End Patch'],
                ['*** Begin Patch', "*** Move File: /home/u/notes/plain.txt -> $file", '*** End Patch'],
            ];
            foreach ($forms as $lines) {
                $got = guardPatchV4a($lines);
                check($got === $want, json_encode($lines) . ': expected ' . json_encode($want) . ', got ' . json_encode($got));
            }
        }
    },
    'guard patch: headers with Unicode or Python-only whitespace, tabs or CRLF are read as Hermes reads them' => function () {
        $nb = "\u{a0}";
        $fs = "\x1c"; // U+001C: whitespace to Python's \s and str.strip(), not to PCRE's \s
        $variants = [
            ["***{$nb}Update File: ~/.pikkumiesKey", '@@', '-a', '+b'],
            ["*** Update{$nb}File:{$nb}~/.pikkumiesKey{$nb}", '@@', '-a', '+b'],
            ["***{$fs}Update{$fs}File:{$fs}~/.pikkumiesKey", '@@', '-a', '+b'],
            ["***\tDelete\tFile:\t~/pikkumies/local/secrets/x\r", '*** End Patch'],
            ["*** Move File: /tmp/a{$nb}->{$fs}~/.pikkumiesKey"],
        ];
        foreach ($variants as $lines) {
            $d = guardPatchV4a($lines);
            check(is_array($d) && $d['action'] === 'block', json_encode($lines) . ': ' . json_encode($d));
        }
    },
    'guard patch: two files where only the second is guarded block; a harmless V4A patch gets no opinion' => function () {
        $d = guardPatchV4a(['*** Begin Patch', '*** Update File: /home/u/notes/todo.md', '@@', '-a', '+b', '*** Delete File: ~/.pikkumiesKey', '*** End Patch']);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'AI key') !== false, 'second file: ' . json_encode($d));
        $d = guardPatchV4a(['*** Update File: /home/u/notes/a.md', '@@', '-x', '+y', '*** Add File: ~/pikkumies/local/secrets/new', '+pw']);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'stored passwords') !== false, 'second file: ' . json_encode($d));
        $harmless = [
            ['*** Begin Patch', '*** Update File: /home/u/notes/todo.md', '@@', '-rm -rf old', '+keep ~/.pikkumiesKey out of git', '*** End Patch'],
            ['*** Begin Patch', '*** Add File: ~/myapp/.env', '+A=1', '*** Move File: ~/a.txt -> ~/b.txt', '*** Delete File: ~/old.log', '*** End Patch'],
        ];
        foreach ($harmless as $lines) {
            check(guardPatchV4a($lines) === null, json_encode($lines) . ' must get no opinion');
        }
    },
    'guard patch: a regex engine failure while reading the headers blocks, never passes' => function () {
        // No JIT and a backtrack limit of 1: the header scan fails inside PCRE.
        $d = guardPatchV4a(['*** Update File: /home/u/notes/todo.md', '@@', '-a', '+b'], ['pcre.jit' => '0', 'pcre.backtrack_limit' => '1']);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'patch too long or complex') !== false, 'regex failure: ' . json_encode($d));
    },
];
