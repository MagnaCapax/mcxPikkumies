<?php
/** The OpenClaw umbrella skill, on a throwaway state tree; no launcher or model run. */
return [
    'openclaw: state directory follows this process environment, else HOME' => function () {
        foreach ([null, '', '/tmp/openclaw-state', '0'] as $value) {
            testWithEnv(['OPENCLAW_STATE_DIR' => $value], function () use ($value) {
                $expected = $value === null || $value === '' ? '/home/fixtureuser/.openclaw' : $value;
                check(OpenClawSkill::stateDir('/home/fixtureuser') === $expected, 'wrong state directory');
            });
        }
    },
    'openclaw: no state directory means nothing is created' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            check(OpenClawSkill::sync("$tmp/absent", '/runtime') === '', 'absent app reported an error');
            check(!file_exists("$tmp/absent"), 'created a state directory for an absent app');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: an existing state directory gets one marked skill with resolved paths' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            check(OpenClawSkill::sync($tmp, '/runtime') === '', 'sync failed');
            $text = (string) file_get_contents("$tmp/skills/pikkumies/SKILL.md");
            check(strpos($text, "---\nname: pikkumies\ndescription: \"") === 0, 'missing frontmatter');
            check(strpos($text, "\n---\n" . OpenClawSkill::MARKER . "\n") !== false, 'marker must follow frontmatter');
            check(strpos($text, '{launcher}') === false && strpos($text, '{index}') === false, 'unresolved placeholders');
            check(strpos($text, '/runtime/tools/INDEX.md') !== false, 'wrong index path');
            check(strpos($text, 'env PIKKUMIES_DEPTH=1 PIKKUMIES_WRAPUP_DETACH=1 /runtime/bin/pikkumies -- ') !== false, 'wrong ask command');
            $lines = explode("\n", $text);
            check(is_string(json_decode(substr($lines[2], strlen('description: ')), true)), 'description is not JSON quoted');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: an unmarked skill belongs to the user and stays byte for byte' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            mkdir("$tmp/skills/pikkumies", 0700, true);
            $path = "$tmp/skills/pikkumies/SKILL.md";
            $own = "---\nname: pikkumies\n---\nMy own skill.\r\n";
            file_put_contents($path, $own);
            check(OpenClawSkill::sync($tmp, '/runtime') === '', 'own skill reported an error');
            check(file_get_contents($path) === $own, 'overwrote the user skill');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: unchanged sync preserves inode and mtime' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            check(OpenClawSkill::sync($tmp, '/runtime') === '', 'initial sync failed');
            $path = "$tmp/skills/pikkumies/SKILL.md";
            check(touch($path, time() - 60), 'could not age the file');
            clearstatcache();
            $before = stat($path);
            check(OpenClawSkill::sync($tmp, '/runtime') === '', 'unchanged sync failed');
            clearstatcache();
            $after = stat($path);
            check($before['ino'] === $after['ino'] && $before['mtime'] === $after['mtime'], 'unchanged file rewritten');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: a marked old skill is refreshed for the new root' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            check(OpenClawSkill::sync($tmp, '/old-runtime') === '', 'old root sync failed');
            check(OpenClawSkill::sync($tmp, '/new-runtime') === '', 'new root sync failed');
            $text = file_get_contents("$tmp/skills/pikkumies/SKILL.md");
            check($text === OpenClawSkill::text('/new-runtime'), 'marked skill not refreshed');
            check(strpos($text, '/old-runtime') === false, 'old root left in skill');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: a write failure is returned as one plain error line' => function () {
        $tmp = testTempDir('pk-openclaw');
        try {
            file_put_contents("$tmp/skills", 'blocks the directory');
            $error = OpenClawSkill::sync($tmp, '/runtime');
            check($error === "cannot write $tmp/skills/pikkumies/SKILL.md", 'write failure not reported plainly');
        } finally {
            testRmTree($tmp);
        }
    },
    'openclaw: only explicit asks use the launcher, tool commands use php' => function () {
        $text = OpenClawSkill::text('/runtime');
        check(substr_count($text, 'bin/pikkumies') === 2, 'expected two ask forms');
        check(preg_match('/bin\/pikkumies(?! -- | --prompt-file)/', $text) === 0, 'launcher could route a tool typo to a model');
        foreach (['env PIKKUMIES_DEPTH=1 php <script> --help',
            'env PIKKUMIES_DEPTH=1 php <script> <verb> [arguments] [--human]',
            "--command 'env PIKKUMIES_DEPTH=1 php <script> <verb> --human'"] as $command) {
            check(strpos($text, $command) !== false, 'missing php tool command: ' . $command);
        }
        $reference = "Where a tool's own text says `pikkumies <tool>`, use this php form instead.";
        check(strpos($text, $reference) !== false, 'missing tool-text override');
        check(preg_match('/`pikkumies\s+[^`]+`/', str_replace($reference, '', $text)) === 0, 'name-form tool instruction');
    },
    'openclaw: named documentation, ticket verbs and index change markers still exist' => function () {
        $units = [];
        foreach (Units::all(dirname(__DIR__) . '/pikkumies/tools') as $unit) {
            $units[$unit['name']] = $unit;
        }
        foreach (['kb', 'wiki', 'ticket'] as $name) {
            check(isset($units[$name]) && $units[$name]['errors'] === [], 'missing or broken unit: ' . $name);
        }
        $usage = file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/ticket/USAGE.md');
        foreach (['draft', 'bundle', 'send', '--by user', '--no-conversation'] as $word) {
            check(strpos($usage, $word) !== false, 'ticket usage drift: ' . $word);
        }
        $index = Units::renderIndex([$units['ticket']]);
        foreach (['draft*', 'bundle*', 'send*'] as $verb) {
            check(strpos($index, $verb) !== false, 'changing verb lost its marker: ' . $verb);
        }
        check(strpos($index, 'list*') === false, 'read-only verb marked as changing');
    },
];
