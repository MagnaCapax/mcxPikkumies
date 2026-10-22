<?php
/** lib/HermesUsage.php (ADR-0109): model requests of a run from Hermes' session store, over the session's lineage only. */
return [
    'HermesUsage: requests are the positive differences over the final session, its parents and its helper sessions, not other sessions' => function () {
        if (!class_exists('SQLite3')) {
            check(HermesUsage::snapshot('/nonexistent/state.db') === null, 'without SQLite3 nothing is read');
            return;
        }
        $dir = testTempDir('pk-hermes-usage');
        $db = "$dir/state.db";
        try {
            $s = new SQLite3($db);
            $s->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, parent_session_id TEXT, api_call_count INTEGER DEFAULT 0)');
            $s->exec("INSERT INTO sessions VALUES ('old', NULL, 7), ('other', NULL, 3)");
            $s->close();
            $before = HermesUsage::snapshot($db);
            check($before === ['old' => 7, 'other' => 3], json_encode($before));
            $s = new SQLite3($db);
            // The run resumed 'old' (+2), compression made 'tip' its child (+4), a helper agent ran under 'tip' (+5); 'other' moved too (+9).
            $s->exec("UPDATE sessions SET api_call_count = 9 WHERE id = 'old'");
            $s->exec("UPDATE sessions SET api_call_count = 12 WHERE id = 'other'");
            $s->exec("INSERT INTO sessions VALUES ('tip', 'old', 4), ('helper', 'tip', 5), ('stranger', NULL, 1), ('sibling', 'old', 6)");
            $s->close();
            check(HermesUsage::requests($before, 'tip', $db) === 11, 'lineage of tip (a sibling under old is another run): ' . var_export(HermesUsage::requests($before, 'tip', $db), true));
            check(HermesUsage::requests($before, 'missing', $db) === null, 'an unknown session is unknown, not 0');
            check(HermesUsage::requests(null, 'tip', $db) === null, 'no snapshot, no figure');
            check(HermesUsage::snapshot("$dir/none.db") === null, 'a missing store reads as null');
        } finally {
            testRmTree($dir);
        }
    },
];
