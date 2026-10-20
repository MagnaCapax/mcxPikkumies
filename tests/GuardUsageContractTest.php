<?php
/**
 * Every USAGE.md example through the delete guard: an example of a verb whose `changes` is `deletes` or
 * `loads` must be asked (approve; `loads` with a pikkumies-load: rule_key), and an example of a `no` verb
 * must run unasked. A /feedback or /ticket `sends` example (data leaving the machine) goes through its send hook
 * (feedback-send, ticket-send) in a throwaway tree with a real draft under that id and must be asked (approve,
 * pikkumies-send: rule_key); at least one of them uses an absolute path. `yes` verbs are not checked here (some ask, such as `--replace`, most do not).
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
        $sends = [];
        foreach (Units::all() as $u) { // every unit at any depth (ADR-0055)
            $file = $u['dir'] . '/USAGE.md';
            if (!is_file($file)) {
                continue;
            }
            $usage = (string) file_get_contents($file);
            $changes = [];
            foreach (Units::verbs($usage) as $v) {
                $changes[$v['verb']] = strtolower(preg_replace('/[\s(].*/', '', trim($v['changes'], '` '))); // "no (contacts the trackers)" is no
            }
            if (!$changes) {
                continue; // no verbs table with a changes column
            }
            $unit = $u['category'] . '/' . $u['name'];
            foreach (guardUsageExamples($usage) as $ex) {
                list($verb, $cmd) = $ex;
                if (isset($changes[$verb]) && $changes[$verb] === 'sends') {
                    if (in_array($unit, ['system/feedback', 'system/ticket'], true)) { // other units' sends have their own approval (email: ADR-0053)
                        $sends[] = [$unit, $verb, $cmd];
                    }
                    continue;
                }
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
        // Data leaving the machine (ADR-0056, ADR-0089): each send example, its path moved into a tree at ~/pikkumies/pikkumies
        // and its id replaced by a real draft's, must be asked by its unit's hook, never let through and never blocked.
        $tmp = realpath(testTempDir('pk-guard-send'));
        $root = "$tmp/home/pikkumies/pikkumies";
        foreach (['lib', 'config', 'tools/system/feedback', 'hooks/guard/feedback-send', 'tools/system/ticket', 'hooks/guard/ticket-send'] as $dir) {
            @mkdir("$root/$dir", 0700, true);
            foreach ((array) glob(dirname(__DIR__) . "/pikkumies/$dir/*.*") as $src) {
                copy($src, "$root/$dir/" . basename($src));
            }
        }
        require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackDraft.php';
        require_once dirname(__DIR__) . '/pikkumies/tools/system/ticket/TicketDraft.php';
        $drafts = ['system/feedback' => FeedbackDraft::create('contract test', null, [], 1792300000, "$root/local/state/feedback")['id'],
            'system/ticket' => TicketDraft::create('contract test', 'contract test', 'contract@example.com', 'Medium', '', [], 1792300000, "$root/local/state/ticket")['id']];
        $absolute = 0;
        foreach ($sends as $ex) {
            list($unit, $verb, $cmd) = $ex;
            $absolute += preg_match('#^php\s+/#', $cmd);
            $run = preg_replace(['#^php\s+/\S*?/pikkumies/pikkumies/#', '#\b[0-9a-f]{32}\s*$#'], ["php $tmp/home/pikkumies/pikkumies/", $drafts[$unit]], $cmd);
            $hook = $unit === 'system/ticket' ? 'ticket-send' : 'feedback-send';
            list(, $out) = testWithEnv(['HOME' => "$tmp/home"], function () use ($root, $run, $hook) {
                return runPhp("$root/hooks/guard/$hook/$hook.php", [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $run]]));
            });
            $d = json_decode($out, true);
            if (!is_array($d) || $d['action'] !== 'approve' || strpos($d['rule_key'], 'pikkumies-send:') !== 0) {
                $bad[] = "$unit $verb (sends): $cmd -> " . (is_array($d) ? $d['action'] . ' ' . $d['message'] : 'none');
            }
            $checked++;
        }
        testRmTree($tmp);
        check($sends && $absolute > 0, 'no send example with an absolute path was checked');
        check($checked >= 50, "too few USAGE examples checked ($checked): the example parser no longer reads the USAGE files");
        check(!$bad, "USAGE examples the guard answers wrongly:\n" . implode("\n", $bad));
    },
];
