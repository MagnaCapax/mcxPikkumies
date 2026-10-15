<?php
/**
 * Two real OS processes racing the per-run state bin/pikkumies touches on every `run`
 * (ADR-0022): skill/INDEX generation (lib/Generate.php) and the PATH block (lib/PathInstall.php).
 * Both must come out correct — not merely "didn't crash" — with neither instance's own state lost.
 */

/** Start a PHP script with args, without waiting; returns the proc_open handle + its pipes. */
function startPhp($script, array $args)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
}

/** Wait for a startPhp() process; returns [exit code, stdout+stderr]. */
function finishPhp($started)
{
    list($p, $pipes) = $started;
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

return [
    'concurrency: two instances regenerating skills/INDEX.md at once never leave a half-written file' => function () {
        $tmp = sys_get_temp_dir() . '/pk-race-gen-' . getmypid() . '-' . mt_rand();
        @mkdir("$tmp/tools/arr/jackett", 0700, true);
        file_put_contents("$tmp/tools/arr/jackett/skill.md", "---\nname: jackett\ndescription: Search indexers.\n---\nBody.\n");
        file_put_contents("$tmp/tools/arr/jackett/USAGE.md", "# jackett\n\nNo verbs.\n");
        file_put_contents("$tmp/tools/arr/jackett/README.md", "# x\n");
        $script = "$tmp/race.php";
        $lib = dirname(__DIR__) . '/pikkumies/lib';
        file_put_contents($script, '<?php
require ' . var_export("$lib/Config.php", true) . ';
require ' . var_export("$lib/Units.php", true) . ';
require ' . var_export("$lib/Generate.php", true) . ';
usleep(mt_rand(0, 15000));
$r = Generate::run([], $argv[1], $argv[2], $argv[3]);
echo $r["error"] === "" ? "ok:" . $r["skills"] : "FAIL:" . $r["error"];
');
        $a = startPhp($script, ["$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md"]);
        $b = startPhp($script, ["$tmp/tools", "$tmp/out", "$tmp/tools/INDEX.md"]);
        list($ca, $oa) = finishPhp($a);
        list($cb, $ob) = finishPhp($b);
        check($ca === 0 && $cb === 0 && strpos($oa, 'ok:1') === 0 && strpos($ob, 'ok:1') === 0, "a concurrent generate failed: $oa / $ob");
        $skill = (string) @file_get_contents("$tmp/out/arr/jackett/SKILL.md");
        check(strpos($skill, "---\nname: jackett\n") === 0 && strpos($skill, 'Body.') !== false, "SKILL.md corrupted or half-written: $skill");
        $index = (string) @file_get_contents("$tmp/tools/INDEX.md");
        check(strpos($index, 'jackett') !== false, "INDEX.md corrupted or half-written: $index");
        exec('rm -rf ' . escapeshellarg($tmp));
    },
    'concurrency: two instances writing the PATH block at once never duplicate it or lose foreign lines' => function () {
        $tmp = sys_get_temp_dir() . '/pk-race-path-' . getmypid() . '-' . mt_rand();
        @mkdir($tmp, 0700, true);
        file_put_contents("$tmp/bashrc", "alias ll='ls -la'\n");
        $script = "$tmp/race.php";
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/PathInstall.php', true) . ';
usleep(mt_rand(0, 15000));
echo PathInstall::ensureFile($argv[1], $argv[2]) ? "wrote" : "noop";
');
        $a = startPhp($script, ["$tmp/bashrc", '/opt/a/bin']);
        $b = startPhp($script, ["$tmp/bashrc", '/opt/a/bin']);
        list($ca, $oa) = finishPhp($a);
        list($cb, $ob) = finishPhp($b);
        check($ca === 0 && $cb === 0, "a concurrent ensureFile crashed: $oa / $ob");
        $content = (string) @file_get_contents("$tmp/bashrc");
        check(substr_count($content, PathInstall::MARK_START) === 1, "the block was duplicated under concurrency: $content");
        check(strpos($content, "alias ll='ls -la'\n") === 0, "a foreign line was lost under concurrency: $content");
        check(strpos($content, '/opt/a/bin') !== false, "the PATH block itself went missing: $content");
        exec('rm -rf ' . escapeshellarg($tmp));
    },
];
