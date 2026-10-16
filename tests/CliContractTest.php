<?php
/**
 * Contract edges of lib/Cli.php and lib/CliHuman.php that CliTest.php and PagingTotalTest.php do not cover:
 * a status-only verb return shape, --jsonl/--human precedence, redaction of a thrown error's own message/hint,
 * the secret-field regex at exact-name boundaries, an option value that collides with a flag name, negative/zero
 * byte rendering, the error shape every real shipped tool unit answers with on a bad verb, and the NaN/Infinite
 * record defect (review 2026-10-16).
 */

/** A throwaway tool exercising edges beyond CliTest.php's fixture. */
if (!function_exists('cctFixtureTool')) {
    function cctFixtureTool()
    {
        $dir = testTempDir('pk-cct');
        $lib = dirname(__DIR__) . '/pikkumies/lib';
        $tool = "$dir/fixture.php";
        file_put_contents($tool, '<?php
require ' . var_export("$lib/Cli.php", true) . ';
require ' . var_export("$lib/CliHuman.php", true) . ';
Cli::run($argv, [
    "statusonly" => function ($args, $opt) { return ["status" => ["ok" => true, "verified" => true]]; },
    "err" => function ($args, $opt) { throw new CliError("tracker refused us", "see https://t.example/a.php?passkey=0123456789abcdef9z"); },
    "nan" => function ($args, $opt) { return [["name" => "a", "rate" => NAN], ["name" => "b", "rate" => 1.5]]; },
    "sids" => function ($args, $opt) { return [["sid" => "x", "auth" => "y", "authority" => "z", "sidebar" => "w", "ok" => 1]]; },
    "fields" => function ($args, $opt) { return [["id" => "a", "human" => "no", "n" => 1]]; },
], "usage: fixture.php statusonly|err|nan|sids|fields [--human]", [], ["fields"]);
');
        return $tool;
    }
}

if (!function_exists('cctLines')) {
    function cctLines($out)
    {
        return array_map(function ($l) {
            return json_decode($l, true);
        }, array_values(array_filter(explode("\n", $out), 'strlen')));
    }
}

return [
    'cli: a verb returning only [\'status\' => [...]] with no \'records\' key is not rendered as status-only; it becomes one bogus record' => function () {
        list($code, $out) = runPhp(cctFixtureTool(), ['statusonly']);
        $l = cctLines($out);
        check($code === 0 && count($l) === 2, "expected one bogus record + status line: $out");
        check($l[0] === ['status' => ['ok' => true, 'verified' => true]], 'the status-shaped return did not become a literal record: ' . json_encode($l[0]));
        check($l[1]['verified'] === true, "the verb's own status key was also merged into the status line: " . json_encode($l[1]));
    },
    'cli: --jsonl is accepted as a no-op global flag, and --human decides the render mode even with --jsonl also given' => function () {
        list($code, $out) = runPhp(cctFixtureTool(), ['fields', '--jsonl']);
        check($code === 0 && strpos($out, '"id":"a"') !== false, '--jsonl alone must never be an unknown-option error: ' . $out);
        list($code, $out) = runPhp(cctFixtureTool(), ['fields', '--limit', '1', '--human', '--jsonl']);
        check($code === 0 && strpos($out, 'id: a') !== false, '--human output did not win over --jsonl: ' . $out);
        check(strpos($out, '{') === false, 'JSON leaked into --human output when --jsonl was also given: ' . $out);
    },
    "cli: a CliError's message and hint are redacted exactly like a record field" => function () {
        list($code, $out) = runPhp(cctFixtureTool(), ['err']);
        $l = cctLines($out);
        check($code === 1 && $l[0]['ok'] === false, "error shape wrong: $out");
        check(strpos($out, '0123456789abcdef9z') === false, "a passkey in the error hint reached stdout: $out");
        check(strpos($l[0]['hint'], 'REDACTED') !== false, 'the hint was not redacted at all: ' . json_encode($l[0]));
    },
    "cli: clean()'s secret regex drops the exact field names 'sid'/'auth' but keeps 'authority'/'sidebar'" => function () {
        list(, $out) = runPhp(cctFixtureTool(), ['sids']);
        $l = cctLines($out);
        check(!isset($l[0]['sid']) && !isset($l[0]['auth']), "exact 'sid'/'auth' field names were not dropped: $out");
        check($l[0]['authority'] === 'z' && $l[0]['sidebar'] === 'w' && $l[0]['ok'] === 1, "a near-miss field name was dropped as if secret: $out");
    },
    "cli: parse() does not reinterpret an option's own value even when it matches a known flag name" => function () {
        list($verb, $args, $opt) = Cli::parse(['x', 'fields', '--fields', 'human'], ['fields'], []);
        check($opt['fields'] === 'human', "the value 'human' of --fields was taken as the --human flag instead: " . json_encode($opt));
        check(!isset($opt['human']), '--human was set from a value string that merely says "human": ' . json_encode($opt));
    },
    "clihuman: bytes() keeps the sign of a negative value through the unit ladder, and zero bytes render as '0 B'" => function () {
        check(CliHuman::bytes(-2048) === '-2.0 KiB', 'a negative KiB value lost its sign or unit: ' . CliHuman::bytes(-2048));
        check(CliHuman::bytes(-500) === '-500 B', 'a negative sub-KiB value lost its sign: ' . CliHuman::bytes(-500));
        check(CliHuman::bytes(0) === '0 B', "exact zero must render as '0 B', not '0.0 B': " . CliHuman::bytes(0));
    },
    'contract: every real shipped tool-unit script exits 2 with a valid {"ok":false,...} final line on a bad verb' => function () {
        $checked = 0;
        foreach (Units::all() as $u) {
            if ($u['script'] === '') {
                continue;
            }
            list($code, $out) = runPhp($u['script'], ['this-verb-does-not-exist-anywhere']);
            $lines = cctLines($out);
            $last = end($lines);
            check($code === 2, "{$u['name']}: a bad verb exited $code, not 2: $out");
            check(is_array($last) && $last['ok'] === false && isset($last['error']), "{$u['name']}: no valid error status line on a bad verb: $out");
            $checked++;
        }
        check($checked >= 10, "too few real units checked ($checked); Units::all() may be misconfigured for this test");
    },
    'cli: a NaN/Infinite-valued field becomes an error record on its own line, never a silent empty stdout line (verified defect, fixed)' => function () {
        list($code, $out) = runPhp(cctFixtureTool(), ['nan']);
        $l = cctLines($out);
        check($code === 0, "exit code: $out");
        check(count($l) === 3, "expected 2 records + status, got " . count($l) . " lines (a blank line breaks the one-JSON-object-per-line contract): " . var_export($out, true));
        check($l[0]['record'] === 0 && strpos($l[0]['error'], 'JSON') !== false, 'the NaN record did not become an error record: ' . json_encode($l[0]));
        check($l[1]['name'] === 'b' && $l[1]['rate'] === 1.5, 'the following good record was lost or corrupted: ' . json_encode($l[1]));
        foreach (explode("\n", trim($out)) as $line) {
            check($line !== '', 'a blank stdout line was printed');
        }
    },
];
