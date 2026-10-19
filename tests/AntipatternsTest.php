<?php
/** doctrine/antipatterns.md: the list of common mistakes, read on demand and never part of SOUL.md (ADR-0063). */

/** [number => [id, shape, cue, do]] for every numbered entry line; a numbered line that does not parse is reported, not skipped. */
function antipatternEntries()
{
    $text = (string) file_get_contents(Config::root() . '/doctrine/antipatterns.md');
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\d+\. /', $line)) {
            continue;
        }
        check(preg_match('/^(\d+)\. \[([a-z0-9-]+)\] (.+?) Cue: (.+?) Do: (.+)$/', $line, $m) === 1, "entry does not parse as 'N. [id] shape. Cue: ... Do: ...': $line");
        check(substr_count($line, ' Cue: ') === 1 && substr_count($line, ' Do: ') === 1, "entry needs exactly one Cue and one Do: $line");
        $out[(int) $m[1]] = [$m[2], $m[3], $m[4], $m[5]];
    }
    return $out;
}

return [
    'antipatterns: every entry parses, numbers run 1..N, ids are unique, and the count is 40 to 60' => function () {
        $e = antipatternEntries();
        check(count($e) >= 40 && count($e) <= 60, count($e) . ' entries; the list is meant to hold 40 to 60');
        check(array_keys($e) === range(1, count($e)), 'entries are not numbered 1..' . count($e) . ' in order');
        $ids = array_column($e, 0);
        check(count(array_unique($ids)) === count($ids), 'duplicate ids: ' . implode(',', array_diff_assoc($ids, array_unique($ids))));
        foreach ($e as $n => $entry) {
            check(substr($entry[1], -1) === '.' && trim($entry[2]) !== '' && trim($entry[3]) !== '', "entry $n: the shape must end with a period and Cue and Do must not be empty");
        }
    },
    'antipatterns: the named entries are there, and the load entry says load counts uninterruptible tasks' => function () {
        $byId = [];
        foreach (antipatternEntries() as $entry) {
            $byId[$entry[0]] = $entry;
        }
        foreach (['load-is-not-cpu', 'ipv6-as-the-fix', 'zfs-or-double-parity', 'python-for-new-tools', 'delete-before-backup'] as $id) {
            check(isset($byId[$id]), "missing entry [$id]");
        }
        check(strpos($byId['load-is-not-cpu'][3], 'uninterruptible') !== false, 'the load entry must say that tasks in uninterruptible sleep count toward the load');
        check(strpos($byId['zfs-or-double-parity'][3], 'write') !== false, 'the ZFS entry must weigh write performance');
    },
    'antipatterns: the list carries no web address, home path, #N number or upper-case code name' => function () {
        $text = (string) file_get_contents(Config::root() . '/doctrine/antipatterns.md');
        // A shipped public list: general mechanics only. These are shapes, not a word list (ADR-0063).
        foreach (['/https?:\/\//' => 'a web address', '#/home/#' => 'a home path', '/#\d/' => 'a #N number', '/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/' => 'an upper-case code name'] as $re => $what) {
            check(!preg_match($re, $text, $m), "antipatterns.md contains $what: " . (isset($m[0]) ? $m[0] : ''));
        }
    },
    'antipatterns: the list is not part of SOUL.md, and the doctrine points to it' => function () {
        Generate::$dockerPresentOverride = true;
        try {
            $s = HermesConfig::renderSoul(['pmss' => true, 'family' => 'pmss', 'root' => ['available' => true, 'via' => 'sudo'], 'tenancy' => ['shared' => false]], Config::defaults());
        } finally {
            Generate::$dockerPresentOverride = null;
        }
        check(strpos($s, '[load-is-not-cpu]') === false && strpos($s, '# Common mistakes') === false, 'antipatterns.md reached SOUL.md; it is read on demand (ADR-0063)');
        check(strpos($s, 'doctrine/antipatterns.md') !== false, 'the doctrine must point the agent to doctrine/antipatterns.md');
    },
    'antipatterns: the Never line on user data stays word for word; Changing data, the Python rule and the common-mistakes pointer reach SOUL.md (ADR-0063)' => function () {
        $safety = (string) file_get_contents(Config::root() . '/doctrine/safety.md');
        check(explode("\n", $safety)[6] === "- Never delete, overwrite or truncate user data without a backup and the user's clear yes, both. Size does not matter: one byte or one terabyte.", 'safety.md line 7 changed: ' . explode("\n", $safety)[6]);
        check(strpos($safety, "## Never\n") < strpos($safety, "## Changing data\n") && strpos($safety, "## Changing data\n") < strpos($safety, "## Always\n"), 'Changing data must sit between Never and Always');
        $doctrine = (string) file_get_contents(Config::root() . '/doctrine/doctrine.md');
        $accuracy = substr($doctrine, 0, (int) strpos($doctrine, "\n## Sources and trust"));
        $memory = substr($doctrine, (int) strpos($doctrine, "\n## Memory: what goes where"));
        check(strpos($accuracy, '- **Common mistakes** are listed in doctrine/antipatterns.md') !== false, 'the common-mistakes pointer must be in the Accuracy section');
        check(strpos($memory, '- **Tools you write are never Python.**') !== false, 'the Python rule must be in the Memory section');
        foreach (['pmss' => ['pmss' => true, 'family' => 'pmss', 'root' => ['available' => true, 'via' => 'sudo']], 'generic' => ['pmss' => false, 'family' => 'generic', 'root' => ['available' => false, 'via' => 'none']]] as $name => $p) {
            $s = HermesConfig::renderSoul($p, Config::defaults(), false);
            foreach (['## Changing data', 'Review it adversarially', 'Take a backup first whenever that is feasible and sensible', 'Tools you write are never Python', 'doctrine/antipatterns.md'] as $needle) {
                check(strpos($s, $needle) !== false, "$name: '$needle' missing from SOUL.md");
            }
        }
    },
];
