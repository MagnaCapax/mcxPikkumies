<?php
/**
 * Antipatterns: the common-mistakes check (ADR-0088), read against doctrine/antipatterns.md (ADR-0063). Two callers
 * share this one class and one interval unit (review/antipattern-check, ADR-0067): the background unit
 * (hooks/review/antipattern-check/antipattern-check.php), woken by a reply or a tool record, and the end-of-turn
 * callers (lib/HaltRun.php, hooks/halt/halt-guard/halt-guard.php) through forTurn()/apply(), called right after
 * HaltJudge::evaluate(). Both read and write the SAME stamp, interval and shown-ids state (lib/HookRun.php,
 * lib/HookAsync.php), so the check never runs twice inside one interval regardless of which caller is due first;
 * HookAsync::passed()/path() and HookRun::beginRun()/endRun()/cursor()/state() are already public (verified at build
 * time: lib/HookAsync.php, lib/HookRun.php), so this class adds no accessor to either.
 *
 * One HookLlm::chat call per run, fixed instruction + a rubric built from the file (id, shape, cue only; never the
 * Do text) + the checked text, delimited with HaltJudge::DATA_NOTICE. The model may name at most two entries, one per
 * line as "id | exact words"; parse() keeps a line only when the id is real, the words (case/whitespace-normalised,
 * at least GROUND_MIN characters) occur in the checked text itself (never the model's say-so), and the id was not
 * already shown this session. What reaches the agent is always fixed PHP text from the file (lines()/resumeMessage()):
 * the model's own words are discarded after grounding, never shown (ADR-0047's injection-defence pattern, reused here).
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/HookAsync.php';
require_once __DIR__ . '/HookInterval.php';
require_once __DIR__ . '/HookRun.php';
require_once __DIR__ . '/HookLlm.php';
require_once __DIR__ . '/HookLedger.php';
require_once __DIR__ . '/HaltJudge.php'; // DATA_NOTICE only: the fixed "data, not instructions" marker text
require_once __DIR__ . '/PathName.php';

class Antipatterns
{
    const ID = 'review/antipattern-check';
    const UNIT = 'antipattern-check'; // HookLedger and hooks.dailyCalls key: the unit's bare name (ADR-0071), not category/name
    const DAILY = 48;              // light-model calls a day per account, all three callers together, unless hooks.dailyCalls.antipattern-check
    const ASSISTANT_MAX = 8000;   // characters of the one-shot/hook final answer kept (end of turn)
    const RECORDS_MAX = 12000;    // characters of the async unit's concatenated reply texts kept
    const COMMANDS_MAX = 40;      // command lines kept, most recent first discarded
    const COMMAND_CHARS = 300;    // characters kept of each command line, for the prompt and for grounding alike
    const GROUND_MIN = 12;        // characters a quoted phrase must reach to ground an id
    const MAX_IDS = 2;            // ids a single run may flag
    const LINES_MAX = 1100;       // characters of the fixed text handed to the agent (a single entry can reach ~550; two must fit)
    const ASK_TIMEOUT = 20;
    const ASK_MAX_TOKENS = 80;
    const INSTRUCTION = 'Name at most two entries whose shape the assistant text or commands clearly show. '
        . 'One per line: `id | exact words copied from the text`. Skip shapes you cannot see in the text. If none: `none`.';
    const CORRECT = 'If one of these describes your last answer, correct it in a short message. You may run read-only checks; do not change anything.';

    /** [id => ['shape', 'cue', 'do']] for every numbered line of doctrine/antipatterns.md; a line that does not parse is skipped here (tests/AntipatternsTest.php fails the file itself on that). */
    public static function entries($file = null)
    {
        $text = (string) file_get_contents($file !== null ? $file : Config::root() . '/doctrine/antipatterns.md');
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\d+\. \[([a-z0-9-]+)\] (.+?) Cue: (.+?) Do: (.+)$/', $line, $m) === 1) {
                $out[$m[1]] = ['shape' => $m[2], 'cue' => $m[3], 'do' => $m[4]];
            }
        }
        return $out;
    }

    /** "[id] shape Cue: cue" per entry, one per line; the Do text never goes in the rubric (only the model's input, never shown to the agent). */
    public static function rubric(array $entries)
    {
        $lines = [];
        foreach ($entries as $id => $e) {
            $lines[] = "[$id] {$e['shape']} Cue: {$e['cue']}";
        }
        return implode("\n", $lines);
    }

    /** The one model call: fixed instruction + rubric + the checked text, delimited and marked as data, not instructions. */
    public static function ask($text, array $commandLines, $maxChars = self::ASSISTANT_MAX, array $entries = null, $timeout = self::ASK_TIMEOUT)
    {
        $entries = $entries !== null ? $entries : self::entries();
        $rubric = self::rubric($entries);
        $cut = $maxChars > 0 ? substr((string) $text, -$maxChars) : '';
        $cmds = self::commandText($commandLines);
        $system = self::INSTRUCTION . ' ' . HaltJudge::DATA_NOTICE;
        $user = $rubric . "\n\n===ASSISTANT TEXT===\n$cut\n===END ASSISTANT TEXT===\n\n===COMMANDS===\n$cmds\n===END COMMANDS===\n";
        return HookLlm::chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], $timeout, self::ASK_MAX_TOKENS);
    }

    /** The last COMMANDS_MAX command lines, each cut to COMMAND_CHARS: what the model sees and what grounding searches against (M1). */
    public static function commandText(array $commandLines)
    {
        return implode("\n", array_map(function ($c) {
            return substr((string) $c, 0, self::COMMAND_CHARS);
        }, array_slice($commandLines, -self::COMMANDS_MAX)));
    }

    /** Reserve one call of today's cap (lib/HookLedger.php, ADR-0071) before asking; false: the cap is spent, do not ask (H-1). */
    public static function reserve(array $config)
    {
        return HookLedger::spend(Config::localDir(), self::UNIT, HookLedger::cap($config, self::UNIT, self::DAILY), 1);
    }

    /**
     * "id | words" lines from $reply, kept only when: the id is a real entry, the words (case/whitespace-normalised,
     * at least GROUND_MIN characters) occur in $checkedText, and the id is not already in $shownIds or repeated in
     * this same reply. At most MAX_IDS kept, in the order the model gave them. [ids, how many lines were dropped].
     */
    public static function parse($reply, $checkedText, array $shownIds, array $entries = null)
    {
        $entries = $entries !== null ? $entries : self::entries();
        $norm = function ($s) {
            return trim(preg_replace('/\s+/', ' ', strtolower((string) $s)));
        };
        $hay = $norm($checkedText);
        $ids = [];
        $dropped = 0;
        foreach (preg_split('/\r?\n/', (string) $reply) as $line) {
            if (!preg_match('/^\s*([a-z0-9-]+)\s*\|\s*(.+?)\s*$/i', $line, $m)) {
                continue; // no "id | words" shape on this line (e.g. "none", noise, an injected instruction): ignored, not dropped
            }
            $id = strtolower($m[1]);
            $words = $norm($m[2]);
            if (!isset($entries[$id]) || strlen($words) < self::GROUND_MIN || strpos($hay, $words) === false
                || in_array($id, $shownIds, true) || in_array($id, $ids, true)) {
                $dropped++;
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }
        return [$ids, $dropped];
    }

    /** "Common-mistake check: [id] <shape> Do: <do>" per flagged id, from the file only, joined by newlines, capped at LINES_MAX characters. */
    public static function lines(array $ids, array $entries = null)
    {
        $entries = $entries !== null ? $entries : self::entries();
        $out = [];
        foreach ($ids as $id) {
            if (isset($entries[$id])) {
                $out[] = "Common-mistake check: [$id] {$entries[$id]['shape']} Do: {$entries[$id]['do']}";
            }
        }
        $text = implode("\n", $out);
        return strlen($text) > self::LINES_MAX ? substr($text, 0, self::LINES_MAX) : $text;
    }

    /** lines($ids) plus the fixed one-shot-resume closing sentence (never the model's own words). */
    public static function resumeMessage(array $ids, array $entries = null)
    {
        $l = self::lines($ids, $entries);
        return $l === '' ? '' : $l . "\n" . self::CORRECT;
    }

    /**
     * Whether and how a verdict's message carries the flagged ids: no ids -> the verdict unchanged. A verdict that
     * already has a message (continue, done-wrapup) gets the lines appended to it, in the same outgoing message, no
     * extra turn. A verdict with no message (chat, waiting, allow, failopen) gets one resume with resumeMessage(),
     * unless antipatterns.resume is false -- the caller is the one that must also respect the run's one wrap-up slot
     * (ADR-0047: $wrappedUp in HaltRun, wrapup_sent in halt-guard) so the bound of 3 continues + 1 final resume holds.
     * ['message' => the (possibly appended) verdict message, 'resume' => a resume query, or null].
     */
    public static function apply(array $verdict, array $ids, array $config = null, array $entries = null)
    {
        if (!$ids) {
            return ['message' => $verdict['message'], 'resume' => null];
        }
        if ($verdict['message'] !== null) {
            return ['message' => rtrim((string) $verdict['message']) . "\n" . self::lines($ids, $entries), 'resume' => null];
        }
        $config = $config !== null ? $config : Config::load();
        if (isset($config['antipatterns']['resume']) && $config['antipatterns']['resume'] === false) {
            return ['message' => $verdict['message'], 'resume' => null];
        }
        return ['message' => $verdict['message'], 'resume' => self::resumeMessage($ids, $entries)];
    }

    /**
     * The end-of-turn callers' one entry point (ADR-0088), right after HaltJudge::evaluate(): due + not disabled ->
     * reserve today's ledger -> one ask()/parse() against $finalAnswer and $commands, the session's shown-ids state
     * updated, logged, and the grounded ids returned ([] when off, scheduled, not due, busy, capped, or nothing
     * grounded). $source labels the log line ('oneshot' for lib/HaltRun.php, 'hook' for halt-guard.php). $timeout
     * bounds the one model call (halt-guard.php passes its own remaining time budget, H-2).
     */
    public static function forTurn($sessionId, $finalAnswer, array $commands, $source = 'oneshot', array $config = null, $hooksDir = null, $timeout = self::ASK_TIMEOUT)
    {
        $config = $config !== null ? $config : Config::load();
        if (!empty($config['antipatterns']['disabled'])) {
            return [];
        }
        if (strpos((string) $sessionId, 'cron_') === 0) {
            return []; // a scheduled run, same as goal-review.php skips (ADR-0071): never worth a model call against a job's own session
        }
        $units = HookInterval::on($config, $hooksDir);
        if (!isset($units[self::ID])) {
            return []; // off (hooks.async.disabled, hooks.every override 0, or the unit is not shipped here): never logged, nothing ran
        }
        $u = $units[self::ID];
        $localDir = Config::localDir();
        $hash = HookAsync::sessionHash((string) $sessionId);
        $stamp = (int) @file_get_contents(HookAsync::path($localDir, $u, $hash, '.stamp'));
        if (!HookAsync::passed($stamp, $u)) {
            return []; // not due yet; the other caller or this one already ran within the interval
        }
        $lock = HookRun::beginRun($localDir, $u, $hash);
        if ($lock === false) {
            return []; // the async worker holds the run right now
        }
        if (!self::reserve($config)) {
            HookRun::endRun($localDir, $u, $hash, $lock, HookRun::cursor($localDir, $u, $hash));
            self::log(['session' => $hash, 'source' => (string) $source, 'outcome' => 'skipped:daily-cap', 'ids' => [], 'ungrounded_dropped' => 0, 'in_chars' => 0, 'ms' => 0],
                isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0);
            return [];
        }
        $start = microtime(true);
        $entries = self::entries();
        $state = HookRun::state($localDir, $u, $hash);
        $shown = isset($state['shown']) && is_array($state['shown']) ? $state['shown'] : [];
        $commandLines = [];
        foreach ($commands as $c) {
            $cmd = is_array($c) && isset($c['command']) ? (string) $c['command'] : '';
            if ($cmd !== '') {
                $commandLines[] = $cmd;
            }
        }
        $text = HaltJudge::text($finalAnswer); // L9: a multimodal/array-shaped final answer, not a bare (string) cast that would read "Array"
        $reply = self::ask($text, $commandLines, self::ASSISTANT_MAX, $entries, $timeout);
        $checkedText = substr($text, -self::ASSISTANT_MAX) . "\n" . self::commandText($commandLines);
        list($ids, $dropped) = self::parse($reply, $checkedText, $shown, $entries);
        $newShown = array_values(array_unique(array_merge($shown, $ids)));
        // L4: again() (lib/HookRun.php, called by hooks/worker.php when a collision finds this run already held) writes the stamp
        // without taking this lock, so a worker that collided with us while ask() was still waiting can leave the stamp short
        // (due again in ~30s instead of the full interval). Re-stamp "now" here, still under the lock, right before releasing it.
        HookAsync::put(HookAsync::path($localDir, $u, $hash, '.stamp'), (string) time());
        HookRun::endRun($localDir, $u, $hash, $lock, HookRun::cursor($localDir, $u, $hash), ['shown' => $newShown]);
        self::log(['session' => $hash, 'source' => (string) $source, 'outcome' => $ids ? 'flagged' : 'ran', 'ids' => $ids,
            'ungrounded_dropped' => $dropped, 'in_chars' => strlen($checkedText), 'ms' => (int) round((microtime(true) - $start) * 1000)],
            isset($config['sessions']['retentionDays']) ? (int) $config['sessions']['retentionDays'] : 0);
        return $ids;
    }

    /**
     * One JSONL line, local/logs/antipatterns/YYYYMMDD.jsonl (dir 0700, file 0600), same retention as the halt-guard
     * log (lib/HaltState::logDecision): ts, session (sha1), source, outcome, ids, ungrounded_dropped, in_chars, ms.
     * Never the assistant text, the model's reply, or the commands.
     */
    public static function log(array $rec, $retentionDays = 0)
    {
        $dir = Config::localDir() . '/logs/antipatterns';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $file = $dir . '/' . gmdate('Ymd') . '.jsonl';
        $new = !is_file($file);
        $out = ['ts' => gmdate('Y-m-d\TH:i:s\Z')] + $rec;
        $old = umask(0077);
        @file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
        if ($new && $retentionDays > 0) {
            $cut = gmdate('Ymd', time() - $retentionDays * 86400);
            foreach ((array) glob($dir . '/[0-9]*.jsonl') as $f) {
                if (PathName::base($f, '.jsonl') < $cut) {
                    @unlink($f);
                }
            }
        }
    }
}
