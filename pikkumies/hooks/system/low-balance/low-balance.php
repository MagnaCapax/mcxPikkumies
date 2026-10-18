<?php
/**
 * Hook unit (pre_llm_call, run by hooks/orchestrator.php, ADR-0030). The low-balance notice of ADR-0050: once per
 * session, when the credits line's own cache (local/state/credits/line.json, written by `credits line`, the start
 * banner and the tmux status line) is under 10 minutes old, for this endpoint, key and model, and
 * CreditsFormat::isLow says the record is low, the turn gets one sentence with the numbers. Never makes a request:
 * a stale or missing cache is silence. lowBalance.disabled turns it off. Fails open; a prompt, not a limit (AGENTS.md).
 */
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Provider.php';
require_once dirname(__DIR__, 3) . '/tools/system/credits/CreditsLine.php';

const LOW_BALANCE_SESSION_RE = '/^[A-Za-z0-9._-]{1,128}$/';
const LOW_BALANCE_MAX_AGE = 600;         // the line's cache counts for 10 minutes; older says nothing about now
const LOW_BALANCE_MARKER_DAYS = 7;       // once-per-session markers older than this are removed

$payload = json_decode((string) stream_get_contents(STDIN), true);
$session = is_array($payload) && isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : '';
if (!preg_match(LOW_BALANCE_SESSION_RE, $session)) {
    exit(0); // no usable session id: "once per session" cannot be kept
}
$config = Config::load();
if (!empty($config['lowBalance']['disabled'])) {
    exit(0);
}
$record = CreditsLine::cached(Provider::resolve($config), CreditsLine::stateDir(), LOW_BALANCE_MAX_AGE);
if ($record === null || !CreditsFormat::isLow($record)) {
    exit(0);
}
$dir = Config::localDir() . '/state/low-balance';
if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) {
    exit(0);
}
$marker = @fopen("$dir/" . sha1($session), 'x'); // 'x' fails when it exists: told already this session
if ($marker === false) {
    exit(0);
}
fclose($marker);
foreach ((array) glob("$dir/*") as $old) {
    if (@filemtime($old) < time() - LOW_BALANCE_MARKER_DAYS * 86400) {
        @unlink($old);
    }
}
$facts = CreditsFormat::short((int) $record['balance']) . ' credits left';
if (isset($record['pct_left']) && is_numeric($record['pct_left'])) {
    $facts .= ', ' . (int) floor($record['pct_left']) . '% of the newest grant';
}
if (isset($record['tokens_min']) && is_int($record['tokens_min'])) {
    $facts .= ', at least ' . CreditsFormat::short($record['tokens_min']) . ' tokens';
}
echo json_encode(['context' => "AI credits are low ($facts): prefer the cheapest steps that do the job, and tell the user once that credits are running low."],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
