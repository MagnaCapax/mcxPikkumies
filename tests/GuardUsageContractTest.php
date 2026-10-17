<?php
/**
 * Every USAGE.md example through the delete guard: an example of a verb whose `changes` is `deletes` or
 * `loads` must be asked (approve; `loads` with a pikkumies-load: rule_key), and an example of a `no` verb
 * must run unasked. `yes` verbs are not checked here (some ask, such as `--replace`, most do not).
 * Units without a verbs table are skipped, so a unit being written does not break the suite.
 * An example is a `php <script> <verb> ...` line inside a ```sh block; `X=<path>` lines define `$X`.
 */
if (!function_exists('guardUsageExamples')) {
    /** [[verb, command], ...] for the php example lines of one USAGE.md. */
    function guardUsageExamples($usage)
    {
        $out = [];
        preg_match_all('/^```sh\n(.*?)^```/ms', $usage, $blocks);
        foreach ($blocks[1] as $block) {
            $vars = [];
            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if (preg_match('/^([A-Z][A-Z0-9_]*)=(\S+)$/', $line, $m)) {
                    $vars[$m[1]] = $m[2];
                    continue;
                }
                if (!preg_match('/^php\s+(\S+)\s+([a-z][a-z0-9-]*)(\s|$)/', $line, $m)) {
                    continue;
                }
                $script = $m[1];
                if (preg_match('/^\$([A-Z][A-Z0-9_]*)$/', $script, $vm) && isset($vars[$vm[1]])) {
                    $script = $vars[$vm[1]];
                }
                if (substr($script, -4) === '.php') {
                    $out[] = [$m[2], $line];
                }
            }
        }
        return $out;
    }
}

return [
    'guard contract: each USAGE example of a deleting or loading verb is asked, of a read-only verb runs unasked' => function () {
        $guard = dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php';
        $checked = 0;
        $bad = [];
        foreach ((array) glob(dirname(__DIR__) . '/pikkumies/tools/*/*/USAGE.md') as $file) {
            $usage = (string) file_get_contents($file);
            $changes = [];
            foreach (Units::verbs($usage) as $v) {
                $changes[$v['verb']] = strtolower(preg_replace('/[\s(].*/', '', trim($v['changes'], '` '))); // "no (contacts the trackers)" is no
            }
            if (!$changes) {
                continue; // no verbs table with a changes column
            }
            $unit = basename(dirname(dirname($file))) . '/' . basename(dirname($file));
            foreach (guardUsageExamples($usage) as $ex) {
                list($verb, $cmd) = $ex;
                if (!isset($changes[$verb]) || !in_array($changes[$verb], ['deletes', 'loads', 'no'], true)) {
                    continue;
                }
                list(, $out) = runPhp($guard, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
                $d = json_decode($out, true);
                $action = is_array($d) ? $d['action'] : 'none';
                $want = $changes[$verb] === 'no' ? 'none' : 'approve';
                $prefix = $changes[$verb] === 'loads' ? 'pikkumies-load:' : 'pikkumies-delete:';
                if ($action !== $want || ($want === 'approve' && strpos($d['rule_key'], $prefix) !== 0)) {
                    $bad[] = "$unit $verb ({$changes[$verb]}): $cmd -> $action";
                }
                $checked++;
            }
        }
        check($checked >= 50, "too few USAGE examples checked ($checked): the example parser no longer reads the USAGE files");
        check(!$bad, "USAGE examples the guard answers wrongly:\n" . implode("\n", $bad));
    },
];
