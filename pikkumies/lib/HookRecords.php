<?php
/**
 * HookRecords: what the interval hook units read about a session (ADR-0067), built from the hook payloads the
 * orchestrator already gets. Kinds, each with turn_id:
 * - user (pre_llm_call): the user's message, at most 4000 characters, and first_turn;
 * - assistant and synthetic user rows (post_llm_call): the rows of this turn after its user row in conversation_history
 *   (agent/turn_finalizer.py), each assistant text at most 1500 characters; a user row Hermes added itself (a
 *   verification nudge, conversation_compression.py _SYNTHETIC_USER_FLAGS) has synthetic true. When the turn's user
 *   row is gone (compacted), only the reply is kept, with compacted true;
 * - reply (post_llm_call): the turn's final answer, at most 3000 characters;
 * - tool (post_tool_call): tool, args as JSON (2000), status, error_type, error (300), exit_code (terminal), and change
 *   (lib/ChangeClassify.php). A tool's output is never kept.
 * Every text is cut to its cap first, then goes through Redact::secrets; a record whose redaction fails is dropped,
 * never kept unredacted.
 */
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/Redact.php';
require_once __DIR__ . '/ChangeClassify.php';

class HookRecords
{
    const CAPS = ['user' => 4000, 'assistant' => 1500, 'reply' => 3000, 'args' => 2000, 'error' => 300];

    /** The records one hook event adds (unnumbered; lib/HookSpool.php numbers and stores them). */
    public static function forEvent($event, array $payload)
    {
        $extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
        $turn = isset($extra['turn_id']) && is_scalar($extra['turn_id']) ? (string) $extra['turn_id'] : '';
        $out = [];
        if ($event === 'pre_llm_call') {
            $first = !empty($extra['is_first_turn']) && $extra['is_first_turn'] !== 'false';
            $out[] = ['kind' => 'user', 'text' => self::text(isset($extra['user_message']) ? $extra['user_message'] : ''), 'first_turn' => $first];
        } elseif ($event === 'post_llm_call') {
            $out = self::turnRows($extra);
        } elseif ($event === 'post_tool_call') {
            $out[] = self::tool($payload, $extra);
        }
        $kept = [];
        foreach ($out as $r) {
            $r = self::redact($r);
            if ($r !== null) {
                $kept[] = ['turn_id' => $turn] + $r;
            }
        }
        return $kept;
    }

    /** This turn's assistant rows, synthetic user rows and reply, from post_llm_call's extra. */
    private static function turnRows(array $extra)
    {
        $history = isset($extra['conversation_history']) && is_array($extra['conversation_history']) ? array_values($extra['conversation_history']) : [];
        $asked = trim(self::text(isset($extra['user_message']) ? $extra['user_message'] : ''));
        $reply = self::text(isset($extra['assistant_response']) ? $extra['assistant_response'] : '');
        $start = -1;
        for ($i = count($history) - 1; $i >= 0 && $asked !== ''; $i--) {
            $row = $history[$i];
            if (is_array($row) && isset($row['role']) && $row['role'] === 'user' && !self::synthetic($row)
                && self::endsWith(trim(self::text(isset($row['content']) ? $row['content'] : '')), $asked)) {
                $start = $i;
                break;
            }
        }
        $out = [];
        if ($start < 0) {
            return [['kind' => 'reply', 'text' => $reply, 'compacted' => true]];
        }
        $rows = array_slice($history, $start + 1);
        foreach ($rows as $k => $row) {
            $text = is_array($row) ? self::text(isset($row['content']) ? $row['content'] : '') : '';
            $role = is_array($row) && isset($row['role']) ? $row['role'] : '';
            if (trim($text) === '' || ($role === 'assistant' && $k === count($rows) - 1 && trim($text) === trim($reply))) {
                continue; // a row of only tool calls, or the final answer itself (kept as the reply)
            }
            if ($role === 'assistant') {
                $out[] = ['kind' => 'assistant', 'text' => $text];
            } elseif ($role === 'user') {
                $out[] = ['kind' => 'user', 'text' => $text, 'first_turn' => false, 'synthetic' => true];
            }
        }
        $out[] = ['kind' => 'reply', 'text' => $reply];
        return $out;
    }

    private static function tool(array $payload, array $extra)
    {
        $tool = isset($payload['tool_name']) && is_string($payload['tool_name']) ? $payload['tool_name'] : '';
        $args = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
        $status = isset($extra['status']) && is_string($extra['status']) ? $extra['status'] : '';
        $r = ['kind' => 'tool', 'tool' => $tool,
            'args' => (string) json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'status' => $status, 'error_type' => isset($extra['error_type']) && is_string($extra['error_type']) ? $extra['error_type'] : '',
            'error' => isset($extra['error_message']) && is_string($extra['error_message']) ? $extra['error_message'] : '',
            'change' => ChangeClassify::isChange($tool, $args, $status)];
        if ($tool === 'terminal') {
            $result = isset($extra['result']) ? $extra['result'] : null;
            $d = is_string($result) ? json_decode(substr($result, 0, 1048576), true) : (is_array($result) ? $result : null);
            $r['exit_code'] = is_array($d) && isset($d['exit_code']) && is_numeric($d['exit_code']) ? (int) $d['exit_code'] : null;
        }
        return $r;
    }

    /** Each text field cut to its cap, then redacted; null when redaction failed. */
    private static function redact(array $r)
    {
        foreach (['text' => $r['kind'], 'args' => 'args', 'error' => 'error'] as $field => $cap) {
            if (!isset($r[$field])) {
                continue;
            }
            try {
                $r[$field] = Redact::secrets(self::cut((string) $r[$field], self::CAPS[$cap]));
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $r;
    }

    /** Text of a message content: a string, or the text parts of a list (multimodal) joined. */
    public static function text($content)
    {
        if (is_string($content)) {
            return $content;
        }
        $out = [];
        foreach (is_array($content) ? $content : [] as $part) {
            if (is_string($part)) {
                $out[] = $part;
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $out[] = $part['text'];
            }
        }
        return implode("\n", $out);
    }

    /** A user row Hermes wrote itself: a flag like _pre_verify_synthetic or _dropped_toolcall_nudge is set. */
    public static function synthetic(array $row)
    {
        foreach ($row as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && preg_match('/_(synthetic|nudge)$/', $k) && $v) {
                return true;
            }
        }
        return false;
    }

    /** At most $max characters (whole UTF-8 characters when the text is valid UTF-8). */
    public static function cut($s, $max)
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return preg_match('/^.{0,' . (int) $max . '}/su', $s, $m) ? $m[0] : substr($s, 0, $max);
    }

    private static function endsWith($haystack, $needle)
    {
        return $needle === '' || (strlen($haystack) >= strlen($needle) && substr($haystack, -strlen($needle)) === $needle);
    }
}
