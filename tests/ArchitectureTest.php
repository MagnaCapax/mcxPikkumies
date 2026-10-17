<?php
/**
 * Cross-cutting lint/contract checks for the PMSS-to-mcxPikkumies CI port review (verdict 2026-10-16):
 * every check here encodes a rule verified to hold today, against the real file set
 * (`git ls-files`, not `find`, per the verdict's own correction), not a re-derivation of production logic.
 */

/** Every tracked file under $dir with a .md/.php extension, read fresh each call (no caching: builders are editing concurrently). */
if (!function_exists('actTrackedPhp')) {
    function actTrackedPhp()
    {
        $root = dirname(__DIR__);
        return array_merge((array) glob("$root/pikkumies/lib/*.php"), (array) glob("$root/pikkumies/tools/*/*.php"),
            (array) glob("$root/pikkumies/tools/*/*/*.php"), (array) glob("$root/pikkumies/hooks/*/*/*.php"),
            (array) glob("$root/pikkumies/hooks/*.php"));
    }
}

/** $file's source with every // and /* *\/ comment blanked out (token-based: safe across PHP 7.3/8.2/8.4, never
 * mis-stripping a string literal that merely contains "//"). */
if (!function_exists('actStripComments')) {
    function actStripComments($file)
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }
}

return [
    'adr: every docs/adr/NNNN-*.md has an H1 "# ADR-NNNN: ..." matching its own file number, and no number repeats' => function () {
        $root = dirname(__DIR__);
        $files = (array) glob("$root/docs/adr/*.md");
        check(count($files) >= 30, 'the ADR directory is missing or nearly empty: ' . count($files) . ' files found');
        $seen = [];
        foreach ($files as $f) {
            $base = basename($f, '.md');
            check(preg_match('/^(\d{4})-/', $base, $m) === 1, "ADR file name does not start with NNNN-: $base");
            $num = $m[1];
            check(!isset($seen[$num]), "ADR number $num is used twice: " . (isset($seen[$num]) ? $seen[$num] : '') . " and $base");
            $seen[$num] = $base;
            $first = strtok((string) file_get_contents($f), "\n");
            check($first !== false && strncmp($first, "# ADR-$num: ", strlen("# ADR-$num: ")) === 0,
                "$base: H1 does not start with '# ADR-$num: ': " . var_export($first, true));
        }
    },
    'architecture: no function, method or class name under pikkumies/ is snake_case (AGENTS.md: camelCase)' => function () {
        $magic = ['__construct', '__destruct', '__call', '__callstatic', '__get', '__set', '__isset', '__unset',
            '__tostring', '__invoke', '__clone', '__sleep', '__wakeup', '__serialize', '__unserialize', '__set_state', '__debuginfo'];
        foreach (actTrackedPhp() as $f) {
            $code = actStripComments($f);
            if (preg_match_all('/\bfunction\s+&?([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $m)) {
                foreach ($m[1] as $name) {
                    check(in_array(strtolower($name), $magic, true) || strpos($name, '_') === false,
                        basename($f) . ": snake_case function/method name '$name'");
                }
            }
            if (preg_match_all('/\bclass\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $m)) {
                foreach ($m[1] as $name) {
                    check(strpos($name, '_') === false, basename($f) . ": snake_case class name '$name'");
                }
            }
        }
    },
    'architecture: no var_dump(), printing print_r() or printing var_export() call under lib, tools or hooks' => function () {
        foreach (actTrackedPhp() as $f) {
            $code = actStripComments($f);
            check(strpos($code, 'var_dump(') === false, basename($f) . ': var_dump() always prints; it would corrupt the JSONL contract');
            foreach (['print_r', 'var_export'] as $fn) {
                if (preg_match_all('/\b' . $fn . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $hit) {
                        $tail = substr($code, $hit[1], 400);
                        check(preg_match('/,\s*true\s*\)/', $tail) === 1, basename($f) . ": $fn() without the return-string flag (prints instead of returning)");
                    }
                }
            }
        }
    },
    'architecture: README.md and docs/*.md relative links resolve to a real file' => function () {
        $root = dirname(__DIR__);
        $pages = array_merge(["$root/README.md"], (array) glob("$root/docs/*.md"));
        check(count($pages) >= 5, 'expected the README and the docs/ pages (ADR-0036); found ' . count($pages));
        foreach ($pages as $page) {
            $name = substr($page, strlen($root) + 1);
            $text = (string) file_get_contents($page);
            check(preg_match_all('/\]\(([^)]+)\)/', $text, $m) > 0, "no links found in $name; this check would be vacuous");
            $checked = 0;
            foreach ($m[1] as $link) {
                if (strpos($link, '://') !== false || $link === '' || $link[0] === '#') {
                    continue; // external or anchor-only
                }
                $checked++;
                check(is_file(dirname($page) . "/$link"), "$name links to '$link', which does not exist");
            }
            check($checked >= 1, "no relative links were found to check in $name (it may have changed shape)");
        }
    },
    'architecture: the README\'s first code block is the one-line install (ADR-0036)' => function () {
        $root = dirname(__DIR__);
        $text = (string) file_get_contents("$root/README.md");
        check(preg_match('/```[a-z]*\n(.*?)\n```/s', $text, $m) === 1, 'README.md has no code block');
        $line = trim($m[1]);
        check(strpos($line, "\n") === false, 'the first code block is not a single line: ' . $line);
        check(strpos($line, 'https://raw.githubusercontent.com/MagnaCapax/mcxPikkumies/main/install.sh') !== false && preg_match('/&& bash \S+$/', $line) === 1, 'the first code block is not the install.sh one-liner: ' . $line);
        check(is_file("$root/install.sh") && is_executable("$root/install.sh"), 'install.sh is missing or not executable');
    },
    'architecture: every tests/*Test.php top-level test name is globally unique (static scan, never re-requiring the files)' => function () {
        $seen = [];
        foreach (glob(__DIR__ . '/*Test.php') as $f) {
            $code = actStripComments($f);
            if (!preg_match_all("/\n\s*'((?:[^'\\\\]|\\\\.)*)'\s*=>\s*function/", $code, $m)) {
                continue;
            }
            foreach ($m[1] as $name) {
                $name = stripcslashes($name);
                check(!isset($seen[$name]), "test name used twice, shadowing coverage: '$name' in " . basename($f) . ' and '
                    . (isset($seen[$name]) ? $seen[$name] : ''));
                $seen[$name] = basename($f);
            }
        }
        check(count($seen) > 50, 'too few test names found (' . count($seen) . '); the scan pattern may not match this repo\'s test shape');
    },
    'architecture: no tests/*Test.php reaches the real pikkumies/local/ directory outside a sys_get_temp_dir() fixture' => function () {
        foreach (glob(__DIR__ . '/*Test.php') as $f) {
            if (basename($f) === 'ArchitectureTest.php') {
                continue; // this file's own string literals name the pattern it looks for
            }
            $code = actStripComments($f);
            check(!preg_match('#dirname\(__DIR__[^)]*\)\s*\.\s*[\'"]/pikkumies/local#', $code),
                basename($f) . ': appears to build a path into the real pikkumies/local/ outside a temp-dir fixture');
        }
    },
    'architecture: no model/vendor name appears under pikkumies/ outside the 3 doctrine-sanctioned lines (AGENTS.md: provider-agnostic)' => function () {
        $root = dirname(__DIR__);
        // Allowed by FILE + a safe exact substring, not by line number: other builders edit these files
        // concurrently (orchestrator.php's own "Claude-Code" comment moved from line 53 to 72 mid-session),
        // so a line-number allowlist is a false-failure trap, not a guard.
        $allowed = [
            "$root/pikkumies/lib/Provider.php" => 'OpenAI-compatible',
            "$root/pikkumies/lib/Config.php" => 'OpenAI-compatible',
            "$root/pikkumies/hooks/orchestrator.php" => 'Claude-Code',
        ];
        $deny = '/\b(openai|anthropic|claude|chatgpt|gpt-?[0-9]|gemini|mistral|cohere|llama|grok|deepseek|ollama|qwen)\b/i';
        foreach (actTrackedPhp() as $f) {
            foreach (file($f) as $n => $line) {
                if (!preg_match($deny, $line)) {
                    continue;
                }
                $lineNo = $n + 1;
                check(isset($allowed[$f]) && strpos($line, $allowed[$f]) !== false,
                    "$f:$lineNo names a model/vendor outside the sanctioned allowlist: " . trim($line));
            }
        }
    },
    'architecture: every real shipped tool-unit script starts with <?php or a shebang, never a missing opening tag' => function () {
        $checked = 0;
        foreach (Units::all() as $u) {
            if ($u['script'] === '') {
                continue;
            }
            $head = (string) file_get_contents($u['script'], false, null, 0, 2);
            check($head === '<?' || $head === '#!', "{$u['name']}: {$u['script']} does not start with <?php or a shebang: " . var_export($head, true));
            $checked++;
        }
        check($checked >= 10, "too few unit scripts checked ($checked)");
    },
    'architecture: lib/Cli.php (the one shared CLI contract) does not silently balloon past its measured size' => function () {
        // Measured 2026-10-16: Cli.php is 216 lines. This is a per-file tripwire on the single file every tool
        // unit depends on, not a repo-wide ceiling (Detect.php, DocSources.php and DocSite.php are legitimately
        // larger today, so a blanket lib/*.php limit at this size would fail immediately and be false).
        $n = count(file(dirname(__DIR__) . '/pikkumies/lib/Cli.php'));
        check($n <= 216 + 80, "Cli.php has grown to $n lines, past the 216+80 soft ceiling measured this session");
    },
    'architecture: network egress (curl_init/fsockopen/stream_socket_client) appears only in lib/Http.php and rtorrent/Scgi.php' => function () {
        $allowed = [dirname(__DIR__) . '/pikkumies/lib/Http.php', dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Scgi.php'];
        foreach (actTrackedPhp() as $f) {
            if (in_array($f, $allowed, true)) {
                continue;
            }
            foreach (['curl_init', 'fsockopen', 'stream_socket_client'] as $fn) {
                check(strpos(actStripComments($f), $fn . '(') === false, basename($f) . ": calls $fn() outside the one allowed network-egress file for its kind");
            }
        }
    },
    'architecture: no tracked *.py file exists anywhere in the repository (AGENTS.md: no Python)' => function () {
        $out = [];
        exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git ls-files "*.py" 2>/dev/null', $out);
        check($out === [], 'tracked Python files found (AGENTS.md forbids Python in this repository): ' . implode(', ', $out));
    },
    'php73: no 7.4+ arrow function, PHP8 match statement, ??=, ?->, str_contains family or typed property exists (comment-stripped)' => function () {
        foreach (actTrackedPhp() as $f) {
            $code = actStripComments($f);
            check(!preg_match('/\bfn\s*\(/', $code), basename($f) . ': a 7.4+ arrow function (fn(...) =>) would parse-error under PHP 7.3');
            check(!preg_match('/(?<![a-zA-Z0-9_])match\s*\(/', $code), basename($f) . ': a PHP8 match(...) {} statement would parse-error under PHP 7.3');
            check(strpos($code, '??=') === false, basename($f) . ': the 7.4+ null-coalescing assignment ??= would parse-error under PHP 7.3');
            check(strpos($code, '?->') === false, basename($f) . ': the PHP8 nullsafe operator ?-> would parse-error under PHP 7.3');
            check(!preg_match('/\bstr_(contains|starts_with|ends_with)\s*\(/', $code), basename($f) . ': a PHP8 str_*() helper would be undefined under PHP 7.3');
            check(!preg_match('/^\s*(public|private|protected)\s+(static\s+)?(int|string|bool|float|array|\?[A-Za-z]|[A-Z][A-Za-z0-9]*)\s+\$[a-zA-Z_]/m', $code),
                basename($f) . ': a 7.4+ typed property declaration would parse-error under PHP 7.3');
        }
    },
];
