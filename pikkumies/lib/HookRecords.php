<?php
/**
 * HookRecords: what the interval hook units read about a session (ADR-0067), built from the hook payloads the
 * orchestrator already gets. Kinds, each with turn_id:
 * - user (pre_llm_call): the user's message, at most 12000 bytes, cut at a whole character (a longer one gets cut: its
 *   length in bytes), and first_turn: bytes, not characters, so four requests always fit the goal review's prompt. A skill invocation (Hermes' scaffold: activation note, skill body, then the user's
 *   instruction, agent/skill_commands.py) is stored as the instruction (or "/name"), skill and skill_head (600), so
 *   the cut never drops what the user typed (ADR-0096);
 * - assistant and synthetic user rows (post_llm_call): the rows of this turn after its user row in conversation_history
 *   (agent/turn_finalizer.py), each assistant text at most 1500 characters; a user row Hermes added itself (a
 *   verification nudge, conversation_compression.py _SYNTHETIC_USER_FLAGS) has synthetic true. When the turn's user
 *   row is gone (compacted), only the reply is kept, with compacted true;
 * - reply (post_llm_call): the turn's final answer, at most 3000 characters;
 * - tool (post_tool_call): tool, args as JSON (2000), status, error_type, error (300), exit_code (terminal), and change
 *   (lib/ChangeClassify.php). A tool's output is never kept.
 * A record of a delegated agent's turn (platform "subagent") has subagent true: it shares the run's spool, never as a
 * request of the user. Every text is cut to its cap first, then goes through Redact::secrets; a record whose
 * redaction fails is dropped, never kept unredacted.
 */
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/Redact.php';
require_once __DIR__ . '/ChangeClassify.php';

class HookRecords
{
    const CAPS = ['user' => 12000, 'assistant' => 1500, 'reply' => 3000, 'args' => 2000, 'error' => 300, 'skill_head' => 600];
    /** Hermes v0.22.2 agent/skill_commands.py markers, byte for byte. */
    const SKILL_PREFIX = '[IMPORTANT: The user has invoked the ';
    const SKILL_SINGLE = 'The full skill content is loaded below.]';
    const SKILL_INSTRUCTION = 'The user has provided the following instruction alongside the skill invocation: ';
    const SKILL_RUNTIME = "\n\n[Runtime note:";
    const SKILL_BUNDLE = ' skill bundle,';
    const SKILL_BUNDLE_INSTRUCTION = "\nUser instruction: ";
    const SKILL_BUNDLE_FIRST = "\n\n[Loaded as part of the ";

    /** The records one hook event adds (unnumbered; lib/HookSpool.php numbers and stores them). */
    public static function forEvent($event, array $payload)
    {
        $extra = isset($payload['extra']) && is_array($payload['extra']) ? $payload['extra'] : [];
        $turn = isset($extra['turn_id']) && is_scalar($extra['turn_id']) ? (string) $extra['turn_id'] : '';
        $out = [];
        if ($event === 'pre_llm_call') {
            $first = !empty($extra['is_first_turn']) && $extra['is_first_turn'] !== 'false';
            $text = self::text(isset($extra['user_message']) ? $extra['user_message'] : '');
            $skill = self::skillTurn($text);
            $r = $skill === null ? ['kind' => 'user', 'text' => $text] : ['kind' => 'user'] + $skill;
            if (strlen($r['text']) > self::CAPS['user']) {
                $r['cut'] = strlen($r['text']);
            }
            $out[] = $r + ['first_turn' => $first];
        } elseif ($event === 'post_llm_call') {
            $out = self::turnRows($extra);
        } elseif ($event === 'post_tool_call') {
            $out[] = self::tool($payload, $extra);
        }
        $sub = isset($extra['platform']) && $extra['platform'] === 'subagent';
        $kept = [];
        foreach ($out as $r) {
            $r = self::redact($r);
            if ($r !== null) {
                $kept[] = ['turn_id' => $turn] + $r + ($sub ? ['subagent' => true] : []);
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
        foreach (['text' => $r['kind'], 'args' => 'args', 'error' => 'error', 'skill_head' => 'skill_head'] as $field => $cap) {
            if (!isset($r[$field])) {
                continue;
            }
            try {
                $capped = $cap === 'user' ? self::cutBytes((string) $r[$field], self::CAPS[$cap]) : self::cut((string) $r[$field], self::CAPS[$cap]);
                $r[$field] = Redact::secrets($capped);
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
    /** At most $max bytes, ending at a whole UTF-8 character when the text is valid UTF-8. */
    public static function cutBytes($s, $max)
    {
        $s = (string) $s;
        if (strlen($s) <= $max) {
            return $s;
        }
        $out = substr($s, 0, max(0, (int) $max));
        if (preg_match('//u', $s) !== 1) {
            return $out; // not UTF-8: bytes as they are
        }
        for ($i = 0; $i < 4 && $out !== '' && preg_match('//u', $out) !== 1; $i++) {
            $out = substr($out, 0, -1); // drop the bytes of a character cut in two
        }
        return $out;
    }

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

    /**
     * A skill invocation's ['text' => the user's instruction, or "/name" for a bare one, 'skill' => name, 'skill_head'
     * => the start of the skill body], or null when $text is not Hermes' scaffold. A PHP port of Hermes'
     * extract_user_instruction_from_skill_message: a bundle's instruction is its first marker, a single skill's the last
     * (the body may quote the marker).
     */
    public static function skillTurn($text)
    {
        if (strncmp((string) $text, self::SKILL_PREFIX, strlen(self::SKILL_PREFIX)) !== 0) {
            return null;
        }
        $name = preg_match('/^' . preg_quote(self::SKILL_PREFIX, '/') . '"([^"\n]{1,120})"/', $text, $m) ? $m[1] : 'skill';
        $instruction = null;
        if (strpos($text, self::SKILL_BUNDLE) !== false) {
            $instruction = self::between($text, self::SKILL_BUNDLE_INSTRUCTION, self::SKILL_BUNDLE_FIRST, false);
        } elseif (strpos($text, self::SKILL_SINGLE) !== false) {
            $instruction = self::between($text, self::SKILL_INSTRUCTION, self::SKILL_RUNTIME, true);
        }
        $body = strpos($text, "\n\n") === false ? '' : trim(substr($text, strpos($text, "\n\n") + 2));
        if ($instruction !== null) { // the head is the body, without the instruction at its end
            $at = strrpos($body, self::SKILL_INSTRUCTION);
            $body = $at === false ? $body : trim(substr($body, 0, $at));
        }
        return ['text' => $instruction === null ? '/' . $name : $instruction, 'skill' => $name, 'skill_head' => self::cut($body, self::CAPS['skill_head'])];
    }

    /** The trimmed text after $marker (its last occurrence when $last) up to $stop; null when absent or empty. */
    private static function between($text, $marker, $stop, $last)
    {
        $at = $last ? strrpos($text, $marker) : strpos($text, $marker);
        if ($at === false) {
            return null;
        }
        $rest = substr($text, $at + strlen($marker));
        $end = strpos($rest, $stop);
        $out = trim($end === false ? $rest : substr($rest, 0, $end));
        return $out === '' ? null : $out;
    }
}
