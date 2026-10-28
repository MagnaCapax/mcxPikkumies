<?php
/**
 * Interval hook unit (ADR-0067, ADR-0096): the goal review, a check that the agent has not drifted from what the user
 * asked. Polled after a finished answer; it answers {"skip": true} until it is due by the run's time and tokens
 * (lib/SessionCounters.php run file, lib/HookRunCount.php): the first review after FIRST_AFTER seconds and FIRST_TOKENS
 * tokens, then at most every THEN_EVERY seconds and only after THEN_TOKENS more (hooks.goalReview.firstAfter,
 * firstTokens, thenEvery, thenTokens). A call with no usable answer is tried again after 600, then 1200, then 1800
 * seconds; after the third in a row it stops until the next regular review is due. The ledger also keeps the account's
 * goal-review calls GOAL_REVIEW_GAP seconds apart, whatever the session. Then one call to the cheapest light model
 * (GoalReview::model) with every request whole, the skills, tool calls and agent texts from the tail, at most 30 000
 * estimated tokens (lib/GoalReviewPrompt.php), and {"context": <fixed text with the scores>, "quotes", "state"}.
 * Skipped and logged: a scheduled session, hooks.llm.disabled, no counter, no request, the daily cap. One line per run
 * goes to local/logs/goal-review/: outcome, scores, sentence names, counts, never a text. Reminders only, never a block.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/GoalReview.php';
require_once dirname(__DIR__, 3) . '/lib/GoalReviewPrompt.php';
require_once dirname(__DIR__, 3) . '/lib/HookLedger.php';
require_once dirname(__DIR__, 3) . '/lib/HookLlm.php';
require_once dirname(__DIR__, 3) . '/lib/HookRunCount.php';
require_once dirname(__DIR__, 3) . '/lib/HookSpool.php';
require_once dirname(__DIR__, 3) . '/lib/PathName.php';
require_once dirname(__DIR__, 3) . '/tools/system/credits/CreditsLine.php';

const GOAL_REVIEW_DAILY = 24;       // model calls a day per account unless hooks.dailyCalls.goal-review says otherwise
const GOAL_REVIEW_MAX_TOKENS = 300; // 13 short lines; HookLlm adds the reasoning allowance on top
const GOAL_REVIEW_LOG_DAYS = 7;
const GOAL_REVIEW_GATES = ['firstAfter' => 300, 'firstTokens' => 75000, 'thenEvery' => 1800, 'thenTokens' => 600000];
const GOAL_REVIEW_RETRY = 600;      // seconds after a call that gave no usable answer, doubling up to RETRY_MAX
const GOAL_REVIEW_RETRY_MAX = 1800;
const GOAL_REVIEW_FAILS = 3;        // failed calls in a row before it waits for the next regular review
const GOAL_REVIEW_GAP = 600;        // seconds between two goal-review calls of the account (HookLedger)

/** One JSONL line per run: outcome, scores, counts; never a request, a reply or a call. */
function goalReviewLog(array $line)
{
    $dir = Config::localDir() . '/logs/goal-review';
    $old = umask(0077);
    if (is_dir($dir) || @mkdir($dir, 0700, true)) {
        $file = "$dir/" . gmdate('Ymd') . '.jsonl';
        $new = !is_file($file);
        @file_put_contents($file, json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z')] + $line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        $files = $new ? (array) glob("$dir/[0-9]*.jsonl") : [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - GOAL_REVIEW_LOG_DAYS)) as $f) {
            @unlink($f);
        }
    }
    umask($old);
}

/** The gate settings: hooks.goalReview.<name> when a whole number of 0 or more, else the default. */
function goalReviewGates(array $config)
{
    $out = [];
    foreach (GOAL_REVIEW_GATES as $k => $default) {
        $v = isset($config['hooks']['goalReview'][$k]) ? $config['hooks']['goalReview'][$k] : null;
        $out[$k] = is_int($v) && $v >= 0 ? $v : $default;
    }
    return $out;
}

/** '' when a review is due now, else why not: the first one by time and tokens since the start, later ones since the last. */
function goalReviewDue(array $run, array $state, array $g, $now)
{
    $last = isset($state['lastReviewAt']) ? (int) $state['lastReviewAt'] : 0;
    $fails = isset($state['fails']) ? max(1, (int) $state['fails']) : 1;
    if (isset($state['lastAttemptAt']) && $now - (int) $state['lastAttemptAt'] < min(GOAL_REVIEW_RETRY_MAX, GOAL_REVIEW_RETRY * (1 << min(4, $fails - 1)))) {
        return 'retry-wait';
    }
    if ($last === 0) {
        return $now - $run['since'] >= $g['firstAfter'] && $run['tokens'] >= $g['firstTokens'] ? '' : 'not-due';
    }
    $mark = isset($state['tokensAtLastReview']) ? (int) $state['tokensAtLastReview'] : 0;
    return $now - $last >= $g['thenEvery'] && $run['tokens'] - $mark >= $g['thenTokens'] ? '' : 'not-due';
}

$in = json_decode((string) stream_get_contents(STDIN), true);
$hash = is_array($in) && isset($in['session']) && is_string($in['session']) ? $in['session'] : '';
$state = is_array($in) && isset($in['state']) && is_array($in['state']) ? $in['state'] : [];
$config = Config::load();
$local = Config::localDir();
$now = time();
$started = microtime(true);
// The whole spool: every request since the start, not only those since the last run. The worker's records stand in when
// the spool cannot be read.
list($records) = HookSpool::read($local, $hash, 0);
if (!$records) {
    $records = is_array($in) && isset($in['records']) && is_array($in['records']) ? $in['records'] : [];
}
$w = GoalReviewWindow::read($records);
$run = SessionCounters::readRun($local, HookRunCount::key($hash));
$m = $run !== null && isset($run['memory']) ? $run['memory'] : 0;
$o = $run !== null && isset($run['outside']) ? $run['outside'] : 0;
// Follow-through: after a note, whether lookups were then made and how the next on-task score came out.
$shownBefore = isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [];
$log = ['session' => $hash, 'used' => array_values(array_filter($shownBefore, function ($p) use ($w) { return is_string($p) && strpos($w['used'], PathName::base($p)) !== false; }))];
$why = $w['scheduled'] ? 'scheduled' : (!empty($config['hooks']['llm']['disabled']) ? 'llm-disabled' : ($run === null ? 'no-counter' : ''));
$keep = false;
if ($why === '') {
    $why = goalReviewDue($run, $state, goalReviewGates($config), $now);
    $keep = $why !== '';
}
if ($why === '' && !$w['requests']) {
    $why = 'no-request';
}
if ($why === '' && !HookLedger::spend($local, 'goal-review', HookLedger::cap($config, 'goal-review', GOAL_REVIEW_DAILY), 1, GOAL_REVIEW_GAP, $spent)) {
    $why = $spent; // daily-cap, no-ledger, or gap: another session's review spent under GOAL_REVIEW_GAP ago
    $keep = $spent === 'gap';
}
$gates = $run === null ? null : ['minutes' => intdiv($now - $run['since'], 60), 'used' => $run['tokens'],
    'sinceLast' => $run['tokens'] - (isset($state['tokensAtLastReview']) ? (int) $state['tokensAtLastReview'] : 0)];
if ($why !== '') {
    goalReviewLog($log + ['outcome' => "skipped:$why", 'gates' => $gates]);
    if ($keep) {
        echo json_encode(['skip' => true]) . "\n"; // the next finished answer looks again
    }
    exit(0);
}
list($docs, $tools) = GoalReviewWindow::candidates($w, $config);
$nonce = bin2hex(random_bytes(4));
$p = GoalReviewPrompt::build($w, $docs, $tools, $nonce, GoalReview::SYSTEM);
$prov = Provider::resolve($config);
$model = GoalReview::model($prov, $config, CreditsLine::cachedRates($prov, CreditsLine::stateDir(), $now));
$reply = HookLlm::chat(GoalReview::messages($p['text']), GoalReview::timeout(microtime(true) - $started), GOAL_REVIEW_MAX_TOKENS, $model);
$a = GoalReview::parse($reply, $p['offered'], $p['docs'], $p['tools']);
$log += ['gates' => $gates, 'est_in' => $p['est'], 'requests' => ['total' => count($w['requests']), 'shown' => count($p['offered']), 'omitted' => $p['omitted']], 'model' => $model, 'calls' => 1];
if ($a === null) {
    // Tried again after the retry gap, the token mark kept; after GOAL_REVIEW_FAILS in a row, counted as a review, so the
    // next try waits for the regular gates instead of calling all day.
    $fails = (isset($state['fails']) ? (int) $state['fails'] : 0) + 1;
    $gaveUp = $fails >= GOAL_REVIEW_FAILS;
    goalReviewLog($log + ['outcome' => trim($reply) === '' ? 'no-answer' : 'discarded', 'fails' => $fails, 'gaveUp' => $gaveUp]);
    $next = $gaveUp ? ['lastReviewAt' => $now, 'tokensAtLastReview' => $run['tokens'], 'fails' => 0] : ['lastAttemptAt' => $now, 'fails' => $fails];
    unset($state['lastAttemptAt']);
    echo json_encode(['state' => $next + $state], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
$current = $a['current'] > 0 ? $w['requests'][$a['current']] : null;
list($text, $fired) = GoalReview::render($a, ['m' => $m, 'o' => $o, 'memoryAt' => $run !== null && isset($run['memoryAt']) ? $run['memoryAt'] : 0,
    'outsideAt' => $run !== null && isset($run['outsideAt']) ? $run['outsideAt'] : 0, 'currentAt' => $current === null ? 0 : $current['ts'],
    'since' => GoalReviewWindow::toolsSince($w, $a['current']), 'checklist' => empty($state['checklistShown']), 'docs' => $p['docs'], 'tools' => $p['tools'],
    'memoryTool' => in_array('memory', Generate::disabledCategories($config), true) ? '' : Config::shown(Config::root() . '/tools/memory/pikkumies/pikkumies.php'),
    'research' => !in_array('research', Generate::disabledCategories($config), true)]);
$follow = !empty($state['fired']) ? ['memoryRose' => $m > (int) (isset($state['m']) ? $state['m'] : 0), 'outsideRose' => $o > (int) (isset($state['o']) ? $state['o'] : 0),
    'prevOnTask' => isset($state['onTask']) ? $state['onTask'] : null, 'onTask' => $a['scores']['ONTASK']] : null;
$shown = array_map(function ($id) use ($p) { return $id[0] === 'T' ? $p['tools'][$id][1] : $p['docs'][$id][1]; }, $text === '' ? [] : array_merge($a['tools'], $a['pages']));
goalReviewLog($log + ['outcome' => 'ran', 'scores' => $a['raw'], 'current' => $a['current'], 'fired' => $fired, 'ids' => $text === '' ? [] : array_merge($a['tools'], $a['pages']),
    'chars' => strlen($text), 'follow' => $follow]);
$newState = ['lastReviewAt' => $now, 'tokensAtLastReview' => $run['tokens'], 'checklistShown' => !empty($state['checklistShown']) || in_array('checklist', $fired, true),
    'shown' => $shown, 'fired' => $fired, 'm' => $m, 'o' => $o, 'onTask' => $a['scores']['ONTASK']];
echo json_encode($text === '' ? ['state' => $newState] : ['context' => $text, 'quotes' => GoalReview::quotes($w, $a), 'state' => $newState],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
