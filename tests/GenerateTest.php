<?php
/** lib/Units.php + lib/Generate.php on a throwaway tools tree. */
function generateFixture()
{
    $tmp = sys_get_temp_dir() . '/pk-gen-' . getmypid() . '-' . mt_rand();
    $unit = function ($cat, $name, $skill, $usage, $readme = "# x\n") use ($tmp) {
        @mkdir("$tmp/tools/$cat/$name", 0700, true);
        file_put_contents("$tmp/tools/$cat/$name/skill.md", $skill);
        if ($usage !== null) {
            file_put_contents("$tmp/tools/$cat/$name/USAGE.md", $usage);
        }
        if ($readme !== null) {
            file_put_contents("$tmp/tools/$cat/$name/README.md", $readme);
        }
    };
    $unit('torrent-clients', 'rtorrent', "---\nname: rtorrent\ndescription: Control rTorrent: list, add, pause.\n---\n# rTorrent\n\nBody text.\n",
        "# rtorrent\n\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| list | `[--limit N]` | no | Torrents. |\n| add | `<file\\|magnet>` | yes | Add one. |\n\nExamples.\n");
    file_put_contents("$tmp/tools/torrent-clients/rtorrent/rtorrent.php", "<?php\n");
    $unit('torrent-clients', 'broken', "---\nname: wrongname\ndescription: x\n---\nbody\n", null);
    $unit('server-admin', 'server-admin', "---\nname: server-admin\ndescription: Root work.\n---\nSteps.\n", "# server-admin\n\nA procedure.\n");
    return $tmp;
}

return [
    'generate: SKILL.md from skill.md + USAGE verbs; broken units skipped and reported' => function () {
        $tmp = generateFixture();
        $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
        check($r['error'] === '' && $r['skills'] === 2, 'expected 2 skills: ' . json_encode($r));
        check(isset($r['problems']['torrent-clients/broken']), 'broken unit not reported');
        $skill = (string) @file_get_contents("$tmp/out/torrent-clients/rtorrent/SKILL.md");
        check(strpos($skill, "---\nname: rtorrent\ndescription: \"Control rTorrent: list, add, pause.\"\n---\n") === 0, 'frontmatter not regenerated safely: ' . substr($skill, 0, 120));
        check(strpos($skill, 'Generated from tools/torrent-clients/rtorrent/skill.md') !== false && strpos($skill, 'Body text.') !== false, 'header or body missing');
        check(strpos($skill, "| add | `<file\\|magnet>` | yes | Add one. |") !== false, 'verbs table or escaped pipe lost');
        check(strpos($skill, "php $tmp/tools/torrent-clients/rtorrent/rtorrent.php <verb>") !== false, 'run line missing');
        $index = (string) file_get_contents("$tmp/tools/INDEX.md");
        check(strpos($index, "## torrent-clients\n- **rtorrent**") !== false && strpos($index, 'Verbs: list, add*.') !== false, 'index line wrong: ' . $index);
        check(strpos($index, 'broken') === false, 'broken unit listed in the index');
    },
    'generate: unchanged files are not rewritten; removed and disabled units disappear' => function () {
        $tmp = generateFixture();
        Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
        $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
        check($r['written'] === 0 && $r['removed'] === 0, 'second run rewrote files: ' . json_encode($r));
        $r = Generate::run(['server-admin'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
        check($r['removed'] === 1 && !is_file("$tmp/out/server-admin/server-admin/SKILL.md"), 'disabled category still generated');
        exec('rm -rf ' . escapeshellarg("$tmp/tools/torrent-clients/rtorrent"));
        Generate::run(['server-admin'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
        check(!is_file("$tmp/out/torrent-clients/rtorrent/SKILL.md"), 'removed unit left a stale skill');
        check(strpos((string) file_get_contents("$tmp/tools/INDEX.md"), 'rtorrent') === false, 'removed unit left in the index');
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'generate: a nested unit is generated under its path; disabled or removed, its skill and empty dirs go (ADR-0055)' => function () {
        $tmp = generateFixture();
        try {
            foreach (['email/imap/fetch', 'media/ffmpeg', 'media-servers/plex'] as $path) {
                $name = basename($path);
                mkdir("$tmp/tools/$path", 0700, true);
                file_put_contents("$tmp/tools/$path/skill.md", "---\nname: $name\ndescription: Fixture $name.\n---\nBody.\n");
                file_put_contents("$tmp/tools/$path/USAGE.md", "# $name\n\nNo verbs.\n");
                file_put_contents("$tmp/tools/$path/README.md", "# $name\n");
            }
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['skills'] === 5 && is_file("$tmp/out/email/imap/fetch/SKILL.md"), 'nested skill not generated: ' . json_encode($r));
            check(strpos((string) file_get_contents("$tmp/tools/INDEX.md"), "\n## email/imap\n- **fetch**") !== false, 'nested index heading');
            $r = Generate::run(['media'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['removed'] === 1 && !is_file("$tmp/out/media/ffmpeg/SKILL.md") && !is_dir("$tmp/out/media") && is_file("$tmp/out/media-servers/plex/SKILL.md"), 'media off must keep media-servers: ' . json_encode($r));
            $r = Generate::run(['media', 'email'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['removed'] === 1 && !is_dir("$tmp/out/email"), 'a disabled parent left a nested skill or its directories: ' . json_encode($r));
            Generate::run(['media'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            touch("$tmp/out/email/imap/fetch/.tmp-SKILL.md-1");
            exec('rm -rf ' . escapeshellarg("$tmp/tools/email"));
            $r = Generate::run(['media'], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['removed'] === 2 && !is_dir("$tmp/out/email") && is_dir("$tmp/out"), 'a removed nested unit left a stale skill, a temporary or a directory: ' . json_encode($r));
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    },
    'generate: the stale-skill cleanup never goes through a symlinked or dot directory in the generated dir (ADR-0055)' => function () {
        $tmp = generateFixture();
        try {
            Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            mkdir("$tmp/elsewhere/skill", 0700, true);
            file_put_contents("$tmp/elsewhere/skill/SKILL.md", 'not ours');
            symlink("$tmp/elsewhere", "$tmp/out/linked");
            mkdir("$tmp/out/.hidden/skill", 0700, true);
            file_put_contents("$tmp/out/.hidden/skill/SKILL.md", 'not ours either');
            $r = Generate::run([], "$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md");
            check($r['removed'] === 0 && is_file("$tmp/elsewhere/skill/SKILL.md") && is_link("$tmp/out/linked"), 'the cleanup went through a symlink: ' . json_encode($r));
            check(is_file("$tmp/out/.hidden/skill/SKILL.md"), 'the cleanup went into a dot directory');
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    },
    'units: frontmatter and verbs table parsing' => function () {
        list($meta, $body) = Units::frontmatter("---\nname: a\ndescription: \"b: c\"\n---\nbody\n");
        check($meta === ['name' => 'a', 'description' => 'b: c'] && $body === "body\n", 'frontmatter parse');
        $v = Units::verbs("text\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| show | `<id>` | no | One \\| two. |\n\nafter\n| x | y | z | w |\n");
        check(count($v) === 1 && $v[0]['verb'] === 'show' && $v[0]['does'] === 'One | two.', 'verbs parse: ' . json_encode($v));
    },
    'generate: containers is left out when no docker CLI is on PATH or in ~/bin, recomputed each call, whatever the daemon does (ADR-0034)' => function () {
        $dir = testTempDir('pk-gen-docker');
        try {
            mkdir("$dir/bin");
            mkdir("$dir/home/bin", 0700, true);
            testWithEnv(['PATH' => "$dir/bin", 'HOME' => "$dir/home"], function () use ($dir) {
                check(Generate::disabledCategories([]) === ['containers'], 'no docker anywhere: containers off');
                check(Generate::disabledCategories(['tools' => ['disabled' => ['containers']]]) === ['containers'], 'switched off and missing: listed once');
                touch("$dir/home/bin/docker");
                chmod("$dir/home/bin/docker", 0755);
                check(Generate::disabledCategories([]) === [], '~/bin/docker counts (PMSS puts helpers there); a down daemon does not remove the category');
                unlink("$dir/home/bin/docker");
                touch("$dir/bin/docker");
                check(Generate::disabledCategories([]) === ['containers'], 'a docker file that is not executable is not a CLI');
                chmod("$dir/bin/docker", 0755);
                check(Generate::disabledCategories([]) === [], 'docker on PATH: containers on');
            });
            Generate::$dockerPresentOverride = false;
            check(in_array('containers', Generate::disabledCategories(Config::defaults()), true), 'the test seam turns the gate off');
            Generate::$dockerPresentOverride = true;
            check(Generate::disabledCategories(['tools' => ['disabled' => ['seedbox']]]) === ['seedbox'], 'with docker present only the user\'s list counts');
        } finally {
            Generate::$dockerPresentOverride = null;
            testRmTree($dir);
        }
    },
];
