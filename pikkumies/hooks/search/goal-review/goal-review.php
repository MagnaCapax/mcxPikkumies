<?php
/**
 * Interval hook unit (ADR-0067, ADR-0071): the goal review. Given the session's records since its last run (stdin,
 * hooks/worker.php), it asks the light model (lib/HookLlm.php: the agent's own endpoint and key) ten fixed questions
 * about the user's requests and the agent's work, and answers {"context": <fixed text>, "quotes": [requests], "state"}.
 * Skipped and logged: a scheduled session, hooks.llm.disabled, no request in the window (chat, follow-ups, Pikkumies'
 * own resume texts), and a window whose only request is the first turn's, which answers {"skip": true} so that request
 * is reviewed with the next ones. Every call is reserved in the daily ledger first (lib/HookLedger.php), all rounds at once.
 * One line per run goes to local/logs/goal-review/: the outcome, the answers, the ids shown, never the texts.
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/GoalReview.php';
require_once dirname(__DIR__, 3) . '/lib/HookLedger.php';
require_once dirname(__DIR__, 3) . '/lib/HookLlm.php';
require_once dirname(__DIR__, 3) . '/lib/HookQuotes.php';

const GOAL_REVIEW_DAILY = 24;       // light-model calls a day per account unless hooks.dailyCalls.goal-review says otherwise
const GOAL_REVIEW_SECONDS = 90;     // of the unit's 100 s timeout, for all its model calls together
const GOAL_REVIEW_MAX_TOKENS = 300;
const GOAL_REVIEW_LOG_DAYS = 7;

/** One JSONL line per run: outcome, answers, ids, counts; never a request, a reply or a call. */
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

$in = json_decode((string) stream_get_contents(STDIN), true);
$records = is_array($in) && isset($in['records']) && is_array($in['records']) ? $in['records'] : [];
$state = is_array($in) && isset($in['state']) && is_array($in['state']) ? $in['state'] : [];
$config = Config::load();
$w = GoalReviewWindow::read($records);
// Whether something shown last time was then used: a tool's script or a page's path in this window's calls.
$shownBefore = isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [];
$log = ['session' => is_array($in) && isset($in['session']) && is_string($in['session']) ? $in['session'] : '',
    'used' => array_values(array_filter($shownBefore, function ($p) use ($w) { return is_string($p) && strpos($w['used'], basename($p)) !== false; }))];
list($why, $keep) = GoalReviewWindow::skip($w, $config);
$rounds = isset($config['hooks']['goalReview']['rounds']) && (int) $config['hooks']['goalReview']['rounds'] === 10 ? 10 : 1;
if ($why === '' && !HookLedger::spend(Config::localDir(), 'goal-review', HookLedger::cap($config, 'goal-review', GOAL_REVIEW_DAILY), $rounds)) {
    $why = 'daily-cap';
}
if ($why !== '') {
    goalReviewLog($log + ['outcome' => "skipped:$why"]);
    if ($keep) {
        echo json_encode(['skip' => true]) . "\n";
    }
    exit(0);
}
$w['requests'] = GoalReviewWindow::fit($w['requests'], GoalReviewWindow::BUDGET['requests'], 1000);
list($docs, $tools) = GoalReviewWindow::candidates($w, $config);
$answers = [];
$start = microtime(true);
for ($k = 0; $k < $rounds; $k++) {
    // Each round sees the last round's answers and may change them; only the last round's answers are used.
    $timeout = (int) max(1, min(40, floor((GOAL_REVIEW_SECONDS - (microtime(true) - $start)) / ($rounds - $k))));
    $parsed = GoalReview::parse(HookLlm::chat(GoalReview::messages($w, $docs, $tools, $answers), $timeout, GOAL_REVIEW_MAX_TOKENS), count($w['requests']), $docs, $tools);
    if ($parsed === null) {
        goalReviewLog($log + ['outcome' => 'discarded', 'round' => $k + 1, 'calls' => $k + 1]);
        exit(0); // an answer that is not exactly the ten allowed lines: nothing of it is used
    }
    $answers = $parsed;
}
$text = GoalReview::render($answers, $docs, $tools, empty($state['checklistShown']));
$ids = array_values(array_unique(array_filter(explode(',', $answers[4] . ',' . $answers[5]), function ($id) { return $id !== 'none'; })));
$shown = array_map(function ($id) use ($docs, $tools) { return $id[0] === 'T' ? $tools[$id][1] : $docs[$id][1]; }, $text === '' ? [] : $ids);
goalReviewLog($log + ['outcome' => 'ran', 'answers' => $answers, 'ids' => $text === '' ? [] : $ids, 'chars' => strlen($text), 'calls' => $rounds]);
$newState = ['checklistShown' => !empty($state['checklistShown']) || strpos($text, GoalReview::CHECKLIST) !== false, 'shown' => $shown];
$quotes = array_map(function ($r) { return GoalReviewWindow::cut($r, HookQuotes::CHARS); }, $w['requests']);
echo json_encode($text === '' ? ['state' => $newState] : ['context' => $text, 'quotes' => $quotes, 'state' => $newState], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
