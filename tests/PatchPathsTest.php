<?php
/**
 * lib/PatchPaths.php: the files a V4A patch names, read the way Hermes' patch_parser.py reads them (one line at a time,
 * Python's Unicode whitespace, str.strip()), and the config-change hook that uses it (ADR-0059). cfgHookTree() and
 * cfgHook() are defined in ConfigChangeHookTest.php; this file loads them when run alone.
 */
if (!function_exists('cfgHookTree')) {
    require __DIR__ . '/ConfigChangeHookTest.php';
}

return [
    'patch paths: every header kind, both sides of a Move, trimmed; hunk lines are not headers' => function () {
        $nb = "\u{a0}";
        $patch = implode("\n", [
            '*** Begin Patch',
            "*** Update File:  a/one.txt{$nb} ",
            '@@ ctx @@',
            '+*** Delete File: not/a/header',
            ' *** Add File: not/either',
            "*** Add File: b/two.txt\r",
            '*** Delete File: c/three.txt',
            "*** Move File: d/src.txt{$nb}->\x1fe/dst.txt",
            '*** End Patch',
        ]);
        $got = PatchPaths::v4a($patch);
        check($got === ['a/one.txt', 'b/two.txt', 'c/three.txt', 'd/src.txt', 'e/dst.txt'], json_encode($got));
        check(PatchPaths::v4a("just text\n@@\n-a\n+b") === [], 'no headers, no paths');
    },
    'patch paths: U+001C to U+001F count as whitespace, and an empty header never swallows the next line' => function () {
        $got = PatchPaths::v4a("***\x1cUpdate\x1dFile:\x1e~/.pikkumiesKey\x1f\n@@\n-a\n+b");
        check($got === ['~/.pikkumiesKey'], 'Python-only whitespace: ' . json_encode($got));
        // Hermes skips the empty first header (its (.+) needs a character on that line) and applies the second.
        $got = PatchPaths::v4a("*** Update File:\n*** Update File: local/config.php\n@@\n-a\n+b");
        check($got === ['local/config.php'], 'empty header: ' . json_encode($got));
    },
    'patch paths: a regex engine failure returns null, for the caller to block' => function () {
        $code = 'require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/PatchPaths.php', true) . '; echo json_encode(PatchPaths::v4a("*** Update File: a.txt\n@@\n-a\n+b"));';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -d pcre.backtrack_limit=1 -r ' . escapeshellarg($code);
        $out = shell_exec($cmd);
        check(trim((string) $out) === 'null', 'regex failure: ' . var_export($out, true));
    },
    'config hook: a V4A header the old reading missed, Python-only whitespace or after an empty header, is asked' => function () {
        $tree = cfgHookTree();
        try {
            foreach (["***\x1cUpdate File: $tree/pikkumies/local/config.php\n@@\n-a\n+b", "*** Update File:\n*** Update File: local/config.php\n@@\n-a\n+b"] as $p) {
                $d = cfgHook($tree, 'patch', ['patch' => $p]);
                check(is_array($d) && $d['action'] === 'approve', json_encode($p) . ': ' . json_encode($d));
            }
        } finally {
            testRmTree($tree);
        }
    },
];
