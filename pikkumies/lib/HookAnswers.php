<?php
/**
 * HookAnswers: reading a hook unit's output and merging the units' answers into one, for hooks/orchestrator.php
 * (ADR-0030, ADR-0054). Each unit's output is read the way Hermes reads a shell hook (agent/shell_hooks.py, Hermes
 * v2026.9.24: _evaluate_result, _parse_response); the merge rules are described in the orchestrator's header.
 */
require_once __DIR__ . '/HookUnits.php';

class HookAnswers
{
    const BLOCK_EXIT = 2;                 // agent/shell_hooks.py BLOCK_EXIT_CODE
    const DEFAULT_BLOCK = 'Blocked by shell hook.';
    const STDERR_LIMIT = 400;
    const CONTEXT_MAX = 9000;

    /** Python truthiness of a decoded JSON value (json_decode without assoc: objects are stdClass). */
    public static function truthy($v)
    {
        return is_object($v) ? (bool) get_object_vars($v) : (bool) $v;
    }

    /** agent/shell_hooks.py _parse_response: stdout -> directive array or null. */
    public static function parse($event, $stdout)
    {
        $stdout = trim((string) $stdout);
        if ($stdout === '') {
            return null;
        }
        $data = json_decode($stdout);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
            return null;
        }
        $get = function ($k) use ($data) {
            return property_exists($data, $k) ? $data->$k : null;
        };
        if ($event === 'pre_verify') {
            // agent/shell_hooks.py _parse_pre_verify: "continue" (Hermes) or "block" (Claude-Code Stop)
            // both mean keep going; no message is a no-op.
            $action = self::truthy($get('action')) ? $get('action') : $get('decision');
            $message = self::truthy($get('message')) ? $get('message') : $get('reason');
            $action = strtolower(trim((string) $action));
            return in_array($action, ['continue', 'block'], true) && is_string($message) && trim($message) !== ''
                ? ['action' => 'continue', 'message' => trim($message)] : null;
        }
        if ($event !== 'pre_tool_call') {
            $context = $get('context');
            return is_string($context) && trim($context) !== '' ? ['context' => $context] : null;
        }
        // Dialects in check order: Hermes "action", then Claude-Code "decision" (_PRE_TOOL_DIALECTS).
        $dialects = [['action', 'message', 'reason', 'args'], ['decision', 'reason', 'message', 'tool_input']];
        foreach ($dialects as $d) {
            if ($get($d[0]) === 'block') {
                $raw = self::truthy($get($d[1])) ? $get($d[1]) : $get($d[2]);
                return ['action' => 'block', 'message' => is_string($raw) && $raw !== '' ? $raw : self::DEFAULT_BLOCK];
            }
        }
        foreach ($dialects as $d) {
            if ($get($d[0]) === 'modify' && is_object($get($d[3]))) {
                return ['action' => 'modify', 'args' => $get($d[3])];
            }
        }
        if ($get('action') === 'approve') {
            $out = ['action' => 'approve'];
            foreach (['message', 'rule_key'] as $k) {
                if (is_string($get($k)) && trim($get($k)) !== '') {
                    $out[$k] = trim($get($k));
                }
            }
            return $out;
        }
        return null;
    }

    /**
     * agent/shell_hooks.py _evaluate_result for one unit: [directive|null, outcome for the log].
     * One addition: a failClosed unit that exits non-zero with no answer (a crash whose error went to
     * stderr) blocks; Hermes would let that call run.
     */
    public static function evaluate($event, array $unit, array $r)
    {
        $id = $unit['category'] . '/' . $unit['name'];
        $failClosed = $unit['failClosed'] && $event === 'pre_tool_call';
        $closed = function ($reason) use ($id) {
            return [['action' => 'block', 'message' => "Pikkumies hook $id failed closed: $reason. Not run; tell the user this safety check is failing."], 'failed-closed'];
        };
        if ($r['error'] !== '' || $r['timedOut']) {
            $reason = $r['error'] !== '' ? $r['error'] : "timed out after {$unit['timeout']}s";
            return $failClosed ? $closed($reason) : [null, $r['timedOut'] ? 'timeout' : 'error'];
        }
        if ($r['code'] === self::BLOCK_EXIT && $event === 'pre_tool_call') {
            $parsed = self::parse($event, $r['stdout']);
            if (is_array($parsed) && $parsed['action'] === 'block') {
                return [$parsed, 'block'];
            }
            $stderr = substr(trim($r['stderr']), 0, self::STDERR_LIMIT);
            return [['action' => 'block', 'message' => $stderr !== '' ? $stderr : self::DEFAULT_BLOCK], 'block'];
        }
        $stdout = trim($r['stdout']);
        $parsed = self::parse($event, $stdout);
        if ($parsed === null && $failClosed && $stdout !== '' && !is_object(json_decode($stdout))) {
            return $closed('unparseable output (expected a JSON object)');
        }
        if ($parsed === null && $failClosed && $r['code'] !== 0) {
            return $closed("exited with code {$r['code']}");
        }
        if ($parsed === null) {
            return [null, $r['code'] === 0 ? 'none' : 'exit-' . $r['code']];
        }
        return [$parsed, isset($parsed['action']) ? $parsed['action'] : 'context'];
    }

    /** hermes_cli/plugins.py _get_pre_tool_call_directive_details over the units' directives, as one hook answer. */
    public static function mergePreToolCall(array $results)
    {
        $modified = null;
        $approves = [];
        $units = []; // the unit of each approve, set by the orchestrator; never part of the answer
        foreach ($results as $result) {
            if (!is_array($result) || !isset($result['action'])) {
                continue;
            }
            if ($result['action'] === 'modify') {
                $partial = isset($result['args']) && is_object($result['args']) ? get_object_vars($result['args']) : [];
                if ($partial) {
                    $modified = array_merge($modified === null ? [] : $modified, $partial);
                }
                continue;
            }
            if (!in_array($result['action'], ['block', 'approve'], true)) {
                continue;
            }
            $message = isset($result['message']) && is_string($result['message']) && $result['message'] !== '' ? $result['message'] : null;
            if ($result['action'] === 'block' && $message === null) {
                continue; // a block needs a message (it becomes the tool result)
            }
            if ($result['action'] === 'block') {
                return ['action' => 'block', 'message' => $message];
            }
            $approve = ['action' => 'approve'];
            if ($message !== null) {
                $approve['message'] = $message;
            }
            if (isset($result['rule_key']) && is_string($result['rule_key']) && trim($result['rule_key']) !== '') {
                $approve['rule_key'] = trim($result['rule_key']);
            }
            $approves[] = $approve;
            $units[] = isset($result['unit']) && is_string($result['unit']) ? $result['unit'] : 'a hook';
        }
        // One shell hook answers with one directive (agent/shell_hooks.py _parse_pre_tool_call), so an
        // approve together with a modify keeps the approve: asking the user is the safety part.
        if (count($approves) > 1) {
            return self::mergeApproves($approves, $units);
        }
        if ($approves) {
            return $approves[0];
        }
        return $modified === null ? null : ['action' => 'modify', 'args' => (object) $modified];
    }

    /**
     * Two or more approves (ADR-0054): one question holding every unit's question unaltered, in run order, so no unit can
     * replace another's (the delete guard's command and per-command key). The rule_key hashes the sorted keys, so Hermes'
     * "always" answer covers exactly this set, whatever the order. Too long to show in full: a block, never a cut question.
     */
    public static function mergeApproves(array $approves, array $units = [])
    {
        $keys = [];
        $parts = [];
        foreach ($approves as $i => $a) {
            $text = isset($a['message']) ? $a['message'] : 'a Pikkumies hook asks about this call';
            $keys[] = isset($a['rule_key']) ? $a['rule_key'] : 'message:' . sha1($text);
            $parts[] = '(' . ($i + 1) . ') ' . $text;
        }
        sort($keys);
        $message = 'Pikkumies: ' . count($approves) . ' checks ask about this call. ' . implode(' ', $parts);
        if (!HookUnits::approveShown($message)) {
            // Name the units: the cause may be two checks asking about one call, which splitting the call does not cure.
            return ['action' => 'block', 'message' => 'Pikkumies safety: ' . count($approves) . ' checks ask about this call (' . implode(', ', $units ?: ['unnamed'])
                . '), and their questions together are too long to show the user in full. Not run. Tell the user which checks asked; split the call into smaller steps, or the user can change one of those checks.'];
        }
        return ['action' => 'approve', 'message' => $message, 'rule_key' => 'pikkumies-multi:' . substr(sha1(implode("\n", $keys)), 0, 16)];
    }

    /** hermes_cli/plugins.py get_pre_verify_continue_message: the first unit's continue answer wins; the rest are ignored. */
    public static function mergePreVerify(array $results)
    {
        foreach ($results as $result) {
            if (is_array($result) && isset($result['action']) && $result['action'] === 'continue') {
                return $result;
            }
        }
        return null;
    }

    /** agent/turn_context.py: contexts joined by a blank line; capped at self::CONTEXT_MAX characters. */
    public static function mergeContext(array $results)
    {
        $parts = [];
        foreach ($results as $result) {
            if (is_array($result) && isset($result['context'])) {
                $parts[] = $result['context'];
            }
        }
        if (!$parts) {
            return null;
        }
        $text = implode("\n\n", $parts);
        if (preg_match('/^.{0,' . self::CONTEXT_MAX . '}/su', $text, $m)) {
            $text = $m[0];
        } else {
            $text = substr($text, 0, self::CONTEXT_MAX); // not valid UTF-8: cut by bytes
        }
        return ['context' => $text];
    }
}
