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
    'units: frontmatter and verbs table parsing' => function () {
        list($meta, $body) = Units::frontmatter("---\nname: a\ndescription: \"b: c\"\n---\nbody\n");
        check($meta === ['name' => 'a', 'description' => 'b: c'] && $body === "body\n", 'frontmatter parse');
        $v = Units::verbs("text\n| verb | arguments | changes | what it does |\n|---|---|---|---|\n| show | `<id>` | no | One \\| two. |\n\nafter\n| x | y | z | w |\n");
        check(count($v) === 1 && $v[0]['verb'] === 'show' && $v[0]['does'] === 'One | two.', 'verbs parse: ' . json_encode($v));
    },
];
