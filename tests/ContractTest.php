<?php
/**
 * Authoring contract for shipped skills and packs, checked the way Hermes reads them (found in the
 * 0.19.0 source; the tests that read the installed Hermes run wherever pikkumies/hermes exists, ADR-0033).
 * A frontmatter Hermes cannot parse does not fail loudly: it logs a warning and lists the skill
 * with an empty description (agent/prompt_builder.py). Descriptions over 1024 characters are
 * cut silently (tools/skills_tool.py). A skill name that exists twice cannot be loaded
 * (tools/skills_tool.py, "Ambiguous skill name").
 */

// The names Hermes already uses (tools, toolsets, slash commands) are Units::RESERVED_NAMES in lib; Units::all() reports
// a unit with one of them, or with a name another unit has, as an error at runtime too (ADR-0055).

/** Every shipped skill source: the skill.md of each tool unit, at any depth (Units::all, ADR-0055). */
function shippedSkillFiles()
{
    return array_map(function ($u) {
        return $u['dir'] . '/skill.md';
    }, Units::all());
}

/** [name, description] from a SKILL.md, or a string describing what is wrong. */
function skillFrontmatter($file)
{
    $text = (string) file_get_contents($file);
    if (!preg_match('/\A---\n(.*?)\n---\n/s', $text, $m)) {
        return 'no frontmatter block (--- ... ---) at the top';
    }
    $fields = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) {
            return "line is not a single-line key: value: $line";
        }
        $fields[$kv[1]] = $kv[2];
    }
    foreach (['name', 'description'] as $k) {
        if (!isset($fields[$k]) || $fields[$k] === '') {
            return "missing $k";
        }
    }
    $desc = $fields['description'];
    if ($desc[0] === '"') {
        if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $desc, $q)) {
            return 'description has an unterminated quote';
        }
        $desc = stripcslashes($q[1]);
    } elseif (strpos($desc, ': ') !== false || strpos($desc, ' #') !== false) {
        return 'unquoted description contains ": " or " #", which YAML misreads; quote it';
    }
    return [$fields['name'], $desc];
}

return [
    'skills: frontmatter parses, name matches its directory, description fits Hermes limits' => function () {
        check(count(shippedSkillFiles()) > 0, 'no skills found');
        foreach (shippedSkillFiles() as $file) {
            $dir = basename(dirname($file));
            $fm = skillFrontmatter($file);
            check(is_array($fm), is_array($fm) ? '' : "$dir: $fm");
            list($name, $desc) = $fm;
            check($name === $dir, "$dir: name '$name' must equal the directory name");
            check(strlen($desc) <= 1024, "$dir: description over 1024 characters is cut by Hermes");
        }
    },
    'skills: names are unique and do not shadow a Hermes built-in' => function () {
        $tools = explode(' ', Units::RESERVED_NAMES);
        $seen = [];
        foreach (shippedSkillFiles() as $file) {
            $name = basename(dirname($file));
            check(!isset($seen[$name]), isset($seen[$name]) ? "skill '$name' exists twice ($seen[$name] and $file); Hermes cannot load an ambiguous name" : '');
            $seen[$name] = $file;
            check(!in_array($name, $tools, true), "skill '$name' has a name Hermes already uses (tool, toolset or command)");
        }
    },
    'units: every tool unit has skill.md, USAGE.md, README.md and, with a script, a verbs table' => function () {
        $units = Units::all();
        check(count($units) >= 4, 'tool units missing');
        foreach ($units as $u) {
            check(!$u['errors'], "{$u['category']}/{$u['name']}: " . implode('; ', $u['errors']));
        }
    },
    'hooks: every shipped hook unit is complete (a broken one would block every tool call, ADR-0030)' => function () {
        $units = HookUnits::all();
        check(count($units) >= 5, 'hook units missing: ' . count($units));
        foreach ($units as $u) {
            check($u['errors'] === [], "hooks/{$u['category']}/{$u['name']}: " . implode('; ', $u['errors']));
        }
    },
    'units: a plain recursive count of skill.md and hook.md files matches what the walker found (a dropped unit fails here)' => function () {
        // Independent of lib/Tree.php: the tests above take their unit lists from the walker, so one unit it drops would vanish from them too.
        $count = function ($root, $marker) {
            $n = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getFilename() === $marker && !preg_match('#(^|/)(lib|\.[^/]*)/#', substr($f->getPathname(), strlen($root) + 1))) {
                    $n++;
                }
            }
            return $n;
        };
        check(count(Units::all()) === $count(Units::toolsDir(), 'skill.md'), 'tool units: ' . count(Units::all()) . ', skill.md files: ' . $count(Units::toolsDir(), 'skill.md'));
        check(count(HookUnits::all()) === $count(HookUnits::hooksDir(), 'hook.md'), 'hook units: ' . count(HookUnits::all()) . ', hook.md files: ' . $count(HookUnits::hooksDir(), 'hook.md'));
    },
    'units: the verbs in USAGE.md are exactly the verbs the tool answers to' => function () {
        foreach (Units::all() as $u) {
            if ($u['script'] === '') {
                continue;
            }
            list(, $out) = runPhp($u['script'], ['--verbs']);
            $code = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
            $doc = array_column($u['verbs'], 'verb');
            sort($code);
            sort($doc);
            check($code === $doc, "{$u['name']}: USAGE.md lists " . implode(',', $doc) . ' but the tool answers to ' . implode(',', $code));
            foreach ($doc as $verb) {
                list($c) = runPhp($u['script'], [$verb, '--help']);
                check($c === 0, "{$u['name']} $verb --help exited $c");
            }
        }
    },
    'units: names do not collide with a skill Hermes ships' => function () {
        $src = HermesInstall::sourceDir();
        if ($src === '') {
            skip('Hermes is not installed in pikkumies/hermes here (CI); the reserved-name check above still runs');
        }
        $bundled = [];
        foreach (['skills', 'optional-skills'] as $d) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . "/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getFilename() === 'SKILL.md' && preg_match('/^name:\s*"?([^"\n]+)/m', (string) file_get_contents((string) $f), $m)) {
                    $bundled[trim($m[1])] = true;
                }
            }
        }
        foreach (Units::all() as $u) {
            check(!isset($bundled[$u['name']]), "{$u['name']} is also the name of a skill Hermes ships; skill_view would refuse both");
        }
    },
    'units: every tool of a category has the verbs its category README lists (ADR-0015)' => function () {
        // Named exemptions only, each with its reason and named in its category README.
        $exempt = ['backup/remote' => 'ADR-0049: archives streamed over ssh, not a snapshot repository',
            'torrent-clients/find' => 'finds which client/service has a torrent before any shared verb can use its id; its own single verb, not the per-client contract above',
            'torrent-clients/move' => 'not a client: it moves torrents between boxes through the client units',
            'torrent-clients/session-rewrite' => 'offline session-file path rewrite for a stopped client, not a live client; see tools/torrent-clients/session-rewrite/README.md'];
        foreach (Tree::scan(Units::toolsDir(), 'skill.md')['categories'] as $category => $dir) {
            $readme = "$dir/README.md";
            $shared = is_file($readme) ? array_column(Units::verbs((string) file_get_contents($readme)), 'verb') : [];
            if (!$shared) {
                continue;
            }
            foreach (Units::all() as $u) {
                if ($u['category'] === $category && isset($exempt[$u['category'] . '/' . $u['name']])) {
                    check(strpos((string) file_get_contents($readme), "`{$u['name']}`") !== false, "{$u['name']} is exempt but its category README does not say so");
                    continue;
                }
                if ($u['category'] === $category) {
                    $missing = array_diff($shared, array_column($u['verbs'], 'verb'));
                    check(!$missing, $u['name'] . ' lacks the category verbs: ' . implode(', ', $missing));
                }
            }
        }
    },
    'units: the shared-verb check above is gated by whether the category README documents a verbs table, never by how many tools the category has' => function () {
        $dir = testTempDir('pk-contract-verbshare');
        try {
            // One tool, but its own category README documents a verbs table the tool does not match:
            // being the only tool in the category must not exempt it.
            $solo = "$dir/tools/solo/onlyone";
            mkdir($solo, 0700, true);
            file_put_contents("$solo/skill.md", "---\nname: onlyone\ndescription: d\n---\nBody.\n");
            file_put_contents("$solo/USAGE.md", "# x\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| show | a | no | Shows. |\n");
            file_put_contents("$solo/README.md", "# x\n");
            file_put_contents("$dir/tools/solo/README.md", "# solo\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| list | a | no | Lists, not shown by onlyone. |\n");
            // Two tools, but the category README documents no verbs table at all: both must be exempt.
            foreach (['alpha', 'beta'] as $name) {
                $u = "$dir/tools/pair/$name";
                mkdir($u, 0700, true);
                file_put_contents("$u/skill.md", "---\nname: $name\ndescription: d\n---\nBody.\n");
                file_put_contents("$u/USAGE.md", "# x\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| $name-only | a | no | Only on $name. |\n");
                file_put_contents("$u/README.md", "# x\n");
            }
            file_put_contents("$dir/tools/pair/README.md", "# pair\n\nNo shared verb table here.\n");
            $units = Units::all("$dir/tools");
            $failures = [];
            foreach (Tree::scan("$dir/tools", 'skill.md')['categories'] as $category => $catDir) {
                $readme = "$catDir/README.md";
                $shared = is_file($readme) ? array_column(Units::verbs((string) file_get_contents($readme)), 'verb') : [];
                if (!$shared) {
                    continue;
                }
                foreach ($units as $u) {
                    if ($u['category'] === $category) {
                        $missing = array_diff($shared, array_column($u['verbs'], 'verb'));
                        if ($missing) {
                            $failures[] = $u['name'] . ' lacks: ' . implode(',', $missing);
                        }
                    }
                }
            }
            check($failures === ['onlyone lacks: list'], 'the single-tool category with a documented verbs table must still be held to it, and the two-tool category with no README verb table must be exempt: ' . json_encode($failures));
        } finally {
            testRmTree($dir);
        }
        // defect this catches: ADR-0015's shared-verb contract is gated on the category README actually
        // documenting a verbs table (`if (!$shared) continue`), never on the tool count; conflating the
        // two would wrongly exempt a single-tool category that DOES document shared verbs, or wrongly
        // demand a shared table from a multi-tool category that documents none.
    },
    'units: removed commands are not mentioned in units, doctrine or docs' => function () {
        $root = dirname(__DIR__);
        $unitFiles = [];
        foreach (Units::all() as $u) {
            $unitFiles = array_merge($unitFiles, (array) glob($u['dir'] . '/*.md'));
        }
        foreach (HookUnits::all() as $u) {
            $unitFiles = array_merge($unitFiles, (array) glob($u['dir'] . '/*.php'));
        }
        $files = array_merge($unitFiles, (array) glob("$root/pikkumies/doctrine/*.md"), (array) glob("$root/pikkumies/doctrine/SOP/*.md"), (array) glob("$root/pikkumies/lib/*.php"), (array) glob("$root/pikkumies/skills/*/SKILL.md"), ["$root/README.md", "$root/AGENTS.md"], (array) glob("$root/docs/*.md"));
        foreach ($files as $f) {
            $text = (string) @file_get_contents($f);
            check(!preg_match('#\bpacks/|pikkumies packs\b|tools/system/status\.php#', $text), basename(dirname($f)) . '/' . basename($f) . ' still mentions packs or a moved tool path');
        }
    },
    'hermes contract: the installed source still behaves the way the design relies on' => function () {
        $src = HermesInstall::sourceDir();
        if ($src === '') {
            skip('Hermes is not installed in pikkumies/hermes here (CI); checked wherever it is installed');
        }
        $facts = [
            'agent/skill_utils.py' => ['"SKILL.md" in files', 'SKILL_PROMPT_DESC_LIMIT = 60'],            // ADR-0015: exact file name; 60-char index
            'agent/shell_hooks.py' => ['data.get("action") == "approve"', 'raw.get("fail_closed"'],          // ADR-0020
            'hermes_cli/plugins.py' => ['rule_key=details.rule_key or tool_name'],                         // ADR-0020: per-command rule_key
            'hermes_cli/config_defaults.py' => ['"keyless_fallback": True', '"keyless_rescue": True'],     // ADR-0021: we turn both off
            'tools/tool_output_limits.py' => ['DEFAULT_MAX_BYTES = 50_000'],                                 // ADR-0016: our budget stays below
            'tools/approval_context.py' => ['_VALID_MODES = ("manual", "smart", "off")'],                    // ADR-0073: we set off
            'hermes_cli/config.py' => ['def _deep_merge('],                                                   // ADR-0020: approvals.mode alone keeps the other defaults
            'hermes_cli/_parser.py' => ['_query_group.add_argument("--query-file"'],                          // ADR-0052: the launcher's --prompt-file is chat's --query-file
        ];
        foreach ($facts as $file => $needles) {
            $text = (string) @file_get_contents($src . '/' . $file);
            foreach ($needles as $n) {
                check(strpos($text, $n) !== false, "Hermes $file no longer contains '$n': re-check the ADR that relies on it");
            }
        }
    },
];
