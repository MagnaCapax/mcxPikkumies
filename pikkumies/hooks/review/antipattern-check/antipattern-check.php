<?php
/**
 * Interval hook unit (ADR-0088, ADR-0067): the common-mistakes check. Given the session's records since its last run
 * (stdin, hooks/worker.php), concatenates the kind "reply" texts and the kind "tool" calls (tool + args) since the
 * last run, reserves one call against the shared daily ledger (lib/HookLedger.php, ADR-0071), and asks
 * Antipatterns::ask() (lib/Antipatterns.php) whether that text clearly shows one of the entries in
 * doctrine/antipatterns.md, and answers {"context": Antipatterns::lines(ids), "state": {"shown": ids}}. A scheduled
 * (cron_) session: nothing, logged skipped:scheduled, the same as goal-review.php skips (ADR-0071). No reply records
 * this window: {"skip":true} (hooks/worker.php then keeps the cursor and retries at once, so tool-only records are
 * never lost), logged skipped:no-replies; antipatterns.disabled: nothing, logged skipped:disabled; the ledger spent:
 * nothing, logged skipped:daily-cap. Neither claims the run itself (hooks/worker.php already holds the unit's lock
 * and stamp before this runs, and saves the cursor and state this script returns): this script never calls
 * Antipatterns::forTurn() or beginRun()/endRun(), only the stateless ask()/parse()/lines() (ADR-0088). Shares its
 * stamp, interval and shown-ids state with the end-of-turn callers (lib/HaltRun.php, halt-guard.php): same unit id,
 * so only one caller's check happens per interval. One line per run goes to local/logs/antipatterns/
 * (Antipatterns::log()), never the text, the model's reply, or the commands.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Antipatterns.php';

$in = json_decode((string) stream_get_contents(STDIN), true);
$records = is_array($in) && isset($in['records']) && is_array($in['records']) ? $in['records'] : [];
$state = is_array($in) && isset($in['state']) && is_array($in['state']) ? $in['state'] : [];
$session = is_array($in) && isset($in['session']) && is_string($in['session']) ? $in['session'] : '';

$config = Config::load();
if (!empty($config['antipatterns']['disabled'])) {
    Antipatterns::log(['session' => $session, 'source' => 'async', 'outcome' => 'skipped:disabled', 'ids' => [], 'ungrounded_dropped' => 0, 'in_chars' => 0, 'ms' => 0]);
    exit(0);
}
if (!empty($in['scheduled'])) {
    Antipatterns::log(['session' => $session, 'source' => 'async', 'outcome' => 'skipped:scheduled', 'ids' => [], 'ungrounded_dropped' => 0, 'in_chars' => 0, 'ms' => 0]);
    exit(0);
}
$replies = [];
$commandLines = [];
foreach ($records as $r) {
    if (!is_array($r) || !isset($r['kind'])) {
        continue;
    }
    if ($r['kind'] === 'reply' && isset($r['text']) && is_string($r['text'])) {
        $replies[] = $r['text'];
    } elseif ($r['kind'] === 'tool') {
        $commandLines[] = (isset($r['tool']) && is_string($r['tool']) ? $r['tool'] : '?') . ' ' . (isset($r['args']) ? (string) $r['args'] : '');
    }
}
if (!$replies) {
    Antipatterns::log(['session' => $session, 'source' => 'async', 'outcome' => 'skipped:no-replies', 'ids' => [], 'ungrounded_dropped' => 0, 'in_chars' => 0, 'ms' => 0]);
    echo json_encode(['skip' => true]) . "\n"; // L8: keep the cursor so these tool-only records are seen again, with the next reply, instead of being lost
    exit(0);
}
if (!Antipatterns::reserve($config)) {
    Antipatterns::log(['session' => $session, 'source' => 'async', 'outcome' => 'skipped:daily-cap', 'ids' => [], 'ungrounded_dropped' => 0, 'in_chars' => 0, 'ms' => 0]);
    exit(0);
}
$start = microtime(true);
$entries = Antipatterns::entries();
$shown = isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [];
$text = implode("\n\n", $replies);
$reply = Antipatterns::ask($text, $commandLines, Antipatterns::RECORDS_MAX, $entries);
$checkedText = substr($text, -Antipatterns::RECORDS_MAX) . "\n" . Antipatterns::commandText($commandLines);
list($ids, $dropped) = Antipatterns::parse($reply, $checkedText, $shown, $entries);
$newShown = array_values(array_unique(array_merge($shown, $ids)));
Antipatterns::log(['session' => $session, 'source' => 'async', 'outcome' => $ids ? 'flagged' : 'ran', 'ids' => $ids, 'ungrounded_dropped' => $dropped,
    'in_chars' => strlen($checkedText), 'ms' => (int) round((microtime(true) - $start) * 1000)], isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0);
echo json_encode(['context' => Antipatterns::lines($ids, $entries), 'state' => ['shown' => $newShown]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
