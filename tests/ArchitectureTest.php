<?php
/**
 * Cross-cutting lint/contract checks for the PMSS-to-mcxPikkumies CI port review (verdict 2026-10-16):
 * every check here encodes a rule verified to hold today, against the real file set
 * (`git ls-files`, not `find`, per the verdict's own correction), not a re-derivation of production logic.
 */

/**
 * Every .php file under pikkumies/lib, tools and hooks, at any depth (units may nest, ADR-0055), read fresh each call
 * (no caching: builders are editing concurrently). Symlinks are not followed. $root: a test tree instead of the repo.
 */
if (!function_exists('actTrackedPhp')) {
    function actTrackedPhp($root = null)
    {
        $root = $root === null ? dirname(__DIR__) : $root;
        $out = [];
        foreach (['lib', 'tools', 'hooks'] as $top) {
            $dirs = is_dir("$root/pikkumies/$top") ? ["$root/pikkumies/$top"] : [];
            while ($dirs) {
                $dir = array_shift($dirs);
                foreach ((array) scandir($dir) as $name) {
                    $p = "$dir/$name";
                    if ($name === '.' || $name === '..' || is_link($p)) {
                        continue;
                    }
                    if (is_dir($p)) {
                        $dirs[] = $p;
                    } elseif (substr($name, -4) === '.php') {
                        $out[] = $p;
                    }
                }
            }
        }
        sort($out);
        return $out;
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
    'architecture: the PHP lint list reaches files at any depth under lib, tools and hooks' => function () {
        $tmp = testTempDir('pk-arch');
        try {
            foreach (['lib/a.php', 'tools/email/imap/fetch/lib/deep.php', 'hooks/guard/x/y/z.php', 'tools/email/README.md'] as $f) {
                @mkdir(dirname("$tmp/pikkumies/$f"), 0700, true);
                file_put_contents("$tmp/pikkumies/$f", "<?php\n");
            }
            $got = array_map(function ($f) use ($tmp) {
                return substr($f, strlen("$tmp/pikkumies/"));
            }, actTrackedPhp($tmp));
            check($got === ['hooks/guard/x/y/z.php', 'lib/a.php', 'tools/email/imap/fetch/lib/deep.php'], 'listed: ' . json_encode($got));
            check(count(actTrackedPhp()) >= 200, 'the repository list shrank: ' . count(actTrackedPhp()));
        } finally {
            testRmTree($tmp);
        }
    },
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
    'architecture: every relative link in tracked markdown resolves to a file or directory, relative to its own file, and every mention of a local ADR is linked (ADR-0036, ADR-0060)' => function () {
        $root = dirname(__DIR__);
        $pages = [];
        exec('cd ' . escapeshellarg($root) . ' && git ls-files "*.md" 2>/dev/null', $pages, $code);
        if ($code !== 0) {
            skip('git cannot list the tracked markdown here (no git, or not a checkout); CI runs this check');
        }
        check(count($pages) >= 200, 'expected every tracked .md file from git ls-files; found ' . count($pages));
        $checked = array_fill_keys(array_merge(['README.md'], array_map('basename', (array) glob("$root/docs/*.md"))), 0);
        $adrs = [];
        foreach ((array) glob("$root/docs/adr/[0-9][0-9][0-9][0-9]-*.md") as $f) {
            $adrs[substr(basename($f), 0, 4)] = $f;
        }
        $mentions = 0;
        foreach ($pages as $name) {
            $text = (string) file_get_contents("$root/$name");
            // A mention of a local ADR outside a link (another project's ADR is a link to it) needs a link to that ADR's file
            // somewhere in the file, except in the ADR itself and in the core doctrine, which is copied into SOUL.md.
            $linked = array_map(function ($t) {
                return substr(basename($t), 0, 5);
            }, Links::targets($text));
            $prose = preg_match('#^pikkumies/doctrine/(soul|voice|doctrine|safety)\.md$#', $name) ? '' : preg_replace('/\[[^\]\n]*\]\([^)\n]*\)/', '', Links::prose($text));
            preg_match_all('/\bADR-(\d{4})\b/', $prose, $adr);
            foreach (array_unique($adr[1]) as $num) {
                $mentions += isset($adrs[$num]) ? 1 : 0; // a number with no local file (planned, or another project's) is not checked
                check(!isset($adrs[$num]) || strpos($name, "docs/adr/$num-") === 0 || in_array("$num-", $linked, true),
                    "$name mentions ADR-$num without a link to it; link its first mention: [ADR-$num](" . (isset($adrs[$num]) ? Links::relative("$root/$name", $adrs[$num]) : '') . ')');
            }
            preg_match_all('/\[ADR-(\d{4})\]\((?![A-Za-z][A-Za-z0-9+.-]*:)([^)\s#]+)/', Links::prose($text), $adrLinks, PREG_SET_ORDER);
            foreach ($adrLinks as $al) { // a link written [ADR-NNNN] must lead to ADR NNNN's own file
                check(strpos(basename($al[2]), "$al[1]-") === 0, "$name links [ADR-$al[1]] to '$al[2]', which is not that ADR's file");
            }
            foreach (Links::targets($text) as $link) { // code, front matter, web links skipped
                $key = $name === 'README.md' ? $name : (dirname($name) === 'docs' ? basename($name) : 'rest');
                $checked[$key] = (isset($checked[$key]) ? $checked[$key] : 0) + 1;
                check($link[0] !== '/' && Links::resolve($link, "$root/$name") !== false, "$name links to '$link', which is absolute or does not exist");
            }
        }
        foreach ($checked as $key => $n) {
            check($n >= 1, "no relative links were found to check in $key (it may have changed shape)"); // the README and every docs/ page link
        }
        check(count($checked) >= 6 && array_sum($checked) >= 48 && $mentions >= 150, 'only ' . array_sum($checked) . " links and $mentions ADR mentions were checked; this check may have become vacuous");
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
            "$root/pikkumies/tools/media/transcribe/TranscribeSecret.php" => 'OpenAI-compatible',
            "$root/pikkumies/tools/media/transcribe/TranscribeCloud.php" => 'OpenAI-compatible',
            "$root/pikkumies/tools/media/transcribe/transcribe.php" => 'OpenAI-compatible',
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
    'architecture: network egress (curl_init/fsockopen/stream_socket_client) appears only in lib/Http.php, rtorrent/Scgi.php and email/lib/MailTransport.php' => function () {
        // MailTransport is the one mail connection (ADR-0053): imaps, pop3s and smtp(s) over TLS, which Http (http/https only) does not speak.
        $allowed = [dirname(__DIR__) . '/pikkumies/lib/Http.php', dirname(__DIR__) . '/pikkumies/tools/torrent-clients/rtorrent/Scgi.php',
            dirname(__DIR__) . '/pikkumies/tools/email/lib/MailTransport.php'];
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
