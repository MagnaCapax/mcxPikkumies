<?php
/**
 * tools/seedbox/services/ServicesList.php::memories (ADR-0104): a memory whose frontmatter `service` array
 * names a nick is found wherever it is filed (lessons/<any topic>/, reference/, research/), with its
 * `categories` frontmatter carried along; a memory with no `service` frontmatter at all keeps being found
 * only through the pre-ADR-0104 fallback (a body line `service: <nick>`, and only under
 * memory/lessons/services/ or memory/reference/ — the two directories skill.md always named). Called
 * directly, the same way LessonsTest.php exercises Lessons.php, rather than through the full services.php
 * CLI harness (tests/ServicesTest.php already covers that the CLI wires show() to this method; ADR-0084's
 * fixture there is also left unchanged by this ADR, which this file's tests confirm at the unit level).
 */
require_once dirname(__DIR__) . '/pikkumies/tools/seedbox/services/ServicesList.php';

if (!function_exists('slmFreshMemory')) {
    /** A throwaway memory/ tree; returns [the memory dir, the temp root to remove]. */
    function slmFreshMemory()
    {
        $tmp = testTempDir('pk-services-memories');
        mkdir("$tmp/memory", 0700, true);
        return $tmp;
    }
}

return [
    'ServicesList::memories: a file with service/categories frontmatter is found wherever it is filed (lessons/<any topic>/, reference/, research/), each with its own categories, and a nick not in that file\'s service array is not' => function () {
        $tmp = slmFreshMemory();
        try {
            mkdir("$tmp/memory/lessons/torrent-clients", 0700, true);
            mkdir("$tmp/memory/reference", 0700, true);
            mkdir("$tmp/memory/research", 0700, true);
            file_put_contents("$tmp/memory/lessons/torrent-clients/20261021-pm1-and-pm2.md",
                "---\ndate: 2026-10-21\ntopic: torrent-clients\nsources: [\"x\"]\nservice: [\"pm1\",\"pm2\"]\ncategories: [\"seedbox\",\"backup\"]\n---\n# pm1 and pm2 share a quirk\n\nbody\n");
            file_put_contents("$tmp/memory/reference/pm1-facts.md", "---\nservice: [\"pm1\"]\n---\n# pm1 reference facts\n\nbody\n");
            file_put_contents("$tmp/memory/research/pm1-deep-dive.md", "---\nservice: [\"pm1\"]\ncategories: [\"nas\"]\n---\n# pm1 research\n\nbody\n");

            $pm1 = ServicesList::memories('pm1', "$tmp/memory");
            check(count($pm1) === 3, 'pm1 must find all three: ' . json_encode($pm1));
            check(array_column($pm1, 'path') === ['memory/lessons/torrent-clients/20261021-pm1-and-pm2.md', 'memory/reference/pm1-facts.md', 'memory/research/pm1-deep-dive.md'], 'pm1 paths: ' . json_encode($pm1));
            $byPath = [];
            foreach ($pm1 as $rec) {
                $byPath[$rec['path']] = $rec;
            }
            check($byPath['memory/lessons/torrent-clients/20261021-pm1-and-pm2.md']['categories'] === ['seedbox', 'backup'], 'joint categories: ' . json_encode($byPath));
            check($byPath['memory/reference/pm1-facts.md']['categories'] === null, 'no categories frontmatter must be null: ' . json_encode($byPath));
            check($byPath['memory/research/pm1-deep-dive.md']['categories'] === ['nas'], 'research categories: ' . json_encode($byPath));
            check($byPath['memory/lessons/torrent-clients/20261021-pm1-and-pm2.md']['title'] === 'pm1 and pm2 share a quirk', 'title: ' . json_encode($byPath));

            $pm2 = ServicesList::memories('pm2', "$tmp/memory");
            check(array_column($pm2, 'path') === ['memory/lessons/torrent-clients/20261021-pm1-and-pm2.md'], 'pm2 must find only the joint file: ' . json_encode($pm2));

            check(ServicesList::memories('pm3', "$tmp/memory") === [], 'a nick absent from every service array must find nothing');
        } finally {
            testRmTree($tmp);
        }
    },
    'ServicesList::memories: a file with no service frontmatter still matches the pre-ADR-0104 body-text convention ("service: <nick>"), but only under memory/lessons/services/ and memory/reference/ — the same two directories as before, with categories always null; a nick absent from any services list is unaffected' => function () {
        $tmp = slmFreshMemory();
        try {
            mkdir("$tmp/memory/lessons/services", 0700, true);
            mkdir("$tmp/memory/reference", 0700, true);
            // Old convention: frontmatter present (date/topic/sources), but no structured `service` key — the line lives in the body.
            file_put_contents("$tmp/memory/lessons/services/20261020-hdd1-disk.md", "---\ndate: 2026-10-20\ntopic: services\nsources: [\"x\"]\n---\n# hdd1 fills up on Sundays\n\nservice: hdd1\ntags: seedbox\n");
            // Old convention with no frontmatter block at all.
            file_put_contents("$tmp/memory/reference/hdd1-facts.md", "# hdd1 facts\nservice: hdd1\n");

            // memories() takes only a nick and a memory directory — it never reads Services::all() or any services
            // list, so a removed (or never-added) service's old memories are found exactly the same way.
            $hdd1 = ServicesList::memories('hdd1', "$tmp/memory");
            check(array_column($hdd1, 'title') === ['hdd1 fills up on Sundays', 'hdd1 facts'], 'legacy fallback: ' . json_encode($hdd1));
            check($hdd1[0]['categories'] === null && $hdd1[1]['categories'] === null, 'legacy categories must be null: ' . json_encode($hdd1));
        } finally {
            testRmTree($tmp);
        }
    },
    'ServicesList::memories: a file whose frontmatter service key is not a JSON array (the old body-text convention wrote it as a bare word, never JSON — e.g. "service: hdd1") falls through to the pre-ADR-0104 body-text check instead of being silently dropped' => function () {
        $tmp = slmFreshMemory();
        try {
            mkdir("$tmp/memory/lessons/services", 0700, true);
            file_put_contents("$tmp/memory/lessons/services/20261021-hdd1-malformed.md",
                "---\nservice: hdd1\n---\n# hdd1 malformed frontmatter\n\nthe frontmatter's own \"service: hdd1\" line already satisfies the legacy regex, since it scans the whole file, not only the body.\n");

            $hdd1 = ServicesList::memories('hdd1', "$tmp/memory");
            check(count($hdd1) === 1 && $hdd1[0]['title'] === 'hdd1 malformed frontmatter' && $hdd1[0]['categories'] === null,
                'a non-JSON-array service value must fall through to the legacy check, never be dropped: ' . json_encode($hdd1));
            check(ServicesList::memories('pm9', "$tmp/memory") === [], 'a different nick must still find nothing in this file');
        } finally {
            testRmTree($tmp);
        }
    },
    'ServicesList::memories: the pre-ADR-0104 body-text convention is found only under lessons/services/ and reference/ — the documented limit of the fallback — so the same "service: <nick>" line under a different lessons/<topic>/ is not found' => function () {
        $tmp = slmFreshMemory();
        try {
            mkdir("$tmp/memory/lessons/other-topic", 0700, true);
            file_put_contents("$tmp/memory/lessons/other-topic/20261020-stray.md", "---\ndate: 2026-10-20\ntopic: other-topic\nsources: [\"x\"]\n---\n# a stray note\n\nservice: hdd1\n");

            check(ServicesList::memories('hdd1', "$tmp/memory") === [], 'a body-text convention outside lessons/services/ and reference/ must not be found (matches today)');
        } finally {
            testRmTree($tmp);
        }
    },
    'ServicesList::memories: an invalid element in the categories frontmatter array (not a string, or a string that fails Services::NICK_RE — e.g. a hand-edited file, or a number if an older buggy save ever wrote one) is dropped on read instead of being handed back as-is' => function () {
        $tmp = slmFreshMemory();
        try {
            mkdir("$tmp/memory/reference", 0700, true);
            file_put_contents("$tmp/memory/reference/pm1-mixed-categories.md", "---\nservice: [\"pm1\"]\ncategories: [\"nas\", \"Bad Tag\", 1080, true, \"seedbox\"]\n---\n# pm1 mixed categories\n\nbody\n");

            $pm1 = ServicesList::memories('pm1', "$tmp/memory");
            check(count($pm1) === 1, 'must still find the file: ' . json_encode($pm1));
            check($pm1[0]['categories'] === ['nas', 'seedbox'], 'only the valid NICK_RE-matching string categories must survive: ' . json_encode($pm1));
        } finally {
            testRmTree($tmp);
        }
    },
];
