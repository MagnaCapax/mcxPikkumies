<?php
/**
 * HookPending: what an interval hook unit's run left for the agent (ADR-0067), one pending context and one pending
 * notice per unit and session (<sha1>.context.json, <sha1>.notice.json beside its other state; the newest replaces the
 * older), written through a temporary file and a rename under <sha1>.plock, the lock take() uses.
 * - A context reaches the agent at the session's next pre_llm_call.
 * - A notice holds the next tool call that may change data (ChangeClassify: true or 'unknown'), once, as a block whose
 *   message is the notice, only when no other unit blocked or asked about that call; at most holdsPerTurn times a turn
 *   (the unit says 1 to 3, default 1) and never twice within HOLD_GAP seconds. A notice still pending at the next
 *   pre_llm_call is shown there as context instead, so it is never lost.
 * Delivery never waits for a lock (LOCK_NB: busy means not now) and never fails a call: it is advisory, never an allow.
 * Caps (over one, that part is dropped and logged): context 4000 characters, notice 900, state 4096 bytes. Text a unit
 * passes on is the unit's to flatten and label as untrusted; the orchestrator cannot tell it from the unit's own words.
 * A context may come with quotes of the user's requests (lib/HookQuotes.php, ADR-0071), shown only when verified.
 */
require_once __DIR__ . '/HookAsync.php';
require_once __DIR__ . '/HookLog.php';
require_once __DIR__ . '/ChangeClassify.php';
require_once __DIR__ . '/HookQuotes.php';

class HookPending
{
    const CONTEXT_MAX = 4000;
    const NOTICE_MAX = 900;
    const STATE_MAX = 4096;
    const HOLD_GAP = 60;
    const HOLDS_MAX = 3;

    /** Keep a run's answer ($out, the unit's decoded stdout). Returns the state to save, or null to keep the old one. */
    public static function store($localDir, array $u, $hash, array $out)
    {
        $id = HookInterval::id($u);
        $f = HookAsync::path($localDir, $u, $hash, '');
        foreach (['context' => self::CONTEXT_MAX, 'notice' => self::NOTICE_MAX] as $kind => $max) {
            if (!isset($out[$kind]) || !is_string($out[$kind]) || trim($out[$kind]) === '') {
                continue;
            }
            if (HookUnits::charCount($out[$kind]) > $max || ($kind === 'notice' && isset($out['noticeOn']) && $out['noticeOn'] !== 'change')) {
                HookLog::add('async', $id, 0, "$kind-dropped");
                continue;
            }
            $holds = isset($out['holdsPerTurn']) && is_int($out['holdsPerTurn']) ? max(1, min(self::HOLDS_MAX, $out['holdsPerTurn'])) : 1;
            $item = ['text' => trim($out[$kind]), 'holds' => $holds, 'ts' => time()];
            if ($kind === 'context' && isset($out['quotes'])) {
                $item['quotes'] = HookQuotes::clean($out['quotes'], $id);
            }
            $json = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            self::locked($f, true, function () use ($f, $kind, $json) {
                HookAsync::put("$f.$kind.json", $json);
            });
        }
        if (!isset($out['state']) || !is_array($out['state'])) {
            return null;
        }
        if (strlen((string) json_encode($out['state'])) > self::STATE_MAX) {
            HookLog::add('async', $id, 0, 'state-dropped');
            return null;
        }
        return $out['state'];
    }

    /** The session's pending contexts and notices as one text ('' when none), each taken once and labelled with its unit. */
    public static function contexts($localDir, array $units, $hash, array $history = [])
    {
        $parts = [];
        foreach ($units as $id => $u) {
            foreach (['context', 'notice'] as $kind) {
                $p = self::take($localDir, $u, $hash, $kind);
                if ($p !== null) {
                    $quoted = isset($p['quotes']) && is_array($p['quotes']) ? HookQuotes::shown($p['quotes'], $history) : '';
                    $parts[] = sprintf('Pikkumies %s (background check from session records): %s', $id, ($quoted !== '' ? "$quoted\n" : '') . $p['text']);
                    HookLog::add('async', $id, 0, "$kind-shown");
                }
            }
        }
        return implode("\n\n", $parts);
    }

    /** A hold for this pre_tool_call, as a block answer, or null. Only stat()s until some unit has a notice waiting. */
    public static function hold($localDir, array $units, $hash, $tool, $args, $turnId)
    {
        $change = null;
        foreach ($units as $id => $u) {
            $f = HookAsync::path($localDir, $u, $hash, '');
            if (!is_file("$f.notice.json") || is_link("$f.notice.json")) {
                continue;
            }
            $change = $change === null ? ChangeClassify::isChange($tool, $args) : $change;
            $held = json_decode((string) @file_get_contents("$f.held"), true);
            $held = is_array($held) ? $held + ['turn' => '', 'count' => 0, 'at' => 0] : ['turn' => '', 'count' => 0, 'at' => 0];
            if ($change === false || time() - (int) $held['at'] < self::HOLD_GAP) {
                continue;
            }
            $same = $held['turn'] === (string) $turnId && (string) $turnId !== '';
            $p = self::take($localDir, $u, $hash, 'notice', function ($n) use ($same, $held) {
                return !$same || (int) $held['count'] < $n['holds'];
            });
            if ($p === null) {
                continue;
            }
            HookAsync::put("$f.held", json_encode(['turn' => (string) $turnId, 'count' => $same ? (int) $held['count'] + 1 : 1, 'at' => time()]));
            HookLog::add('async', $id, 0, 'held');
            return ['action' => 'block', 'message' => sprintf('Pikkumies %s (background check from session records): %s', $id, $p['text'])];
        }
        return null;
    }

    /**
     * Take one pending item: ['text', 'holds'] or null (none, a symlink, its lock busy, or $accept refused it, which
     * leaves it pending). Read and removed under the lock, so parallel callers get it exactly once.
     */
    public static function take($localDir, array $u, $hash, $kind, callable $accept = null)
    {
        $f = HookAsync::path($localDir, $u, $hash, '');
        $file = "$f.$kind.json";
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $got = null;
        self::locked($f, false, function () use ($file, $accept, &$got) {
            $d = is_link($file) ? null : json_decode((string) @file_get_contents($file, false, null, 0, 65536), true);
            $ok = is_array($d) && isset($d['text']) && is_string($d['text']);
            if ($ok && ($accept === null || $accept($d + ['holds' => 1]))) {
                $got = $d + ['holds' => 1];
                @unlink($file);
            } elseif (!$ok && is_file($file)) {
                @unlink($file); // unreadable: never shown, never kept
            }
        });
        return $got;
    }

    /** Run $work under the unit's pending lock; $wait false: give up when it is busy. */
    private static function locked($f, $wait, callable $work)
    {
        $old = umask(0077);
        if ($wait && !is_dir(dirname($f))) {
            @mkdir(dirname($f), 0700, true); // a writer outside a worker run (a synchronous caller)
        }
        $fh = @fopen("$f.plock", 'c');
        umask($old);
        if ($fh === false) {
            return;
        }
        if (flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            $work();
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }
}
