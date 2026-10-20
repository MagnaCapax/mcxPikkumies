<?php
/**
 * A feedback draft (ADR-0056): the exact JSON that will be sealed and sent, saved once under
 * local/state/feedback/<id>.json through lib/DraftStore.php (mode 0400, named by its own sha256). The approval hook and
 * the send verb both load it through load(), which refuses a file whose sha256 no longer starts with its id, or that
 * carries anything but the known fields, so a draft changed after the user approved it cannot go out under that
 * approval. A sent draft is renamed to <id>.sent.json and is never sent again.
 */
require_once dirname(__DIR__, 3) . '/lib/DraftStore.php';

class FeedbackDraft
{
    /** The user's text, at most this many bytes. */
    const TEXT_MAX = 20000;
    /** The whole payload stays under this; the transcript keeps its newest part to fit. The server takes 2,000,000 sealed. */
    const PAYLOAD_MAX = 1500000;
    /** The fields of a draft and their types: load() refuses anything else. */
    const FIELDS = ['v' => 'integer', 'created_at' => 'integer', 'session_id' => 'string', 'session_title' => 'string',
        'text' => 'string', 'transcript' => 'string|NULL', 'transcript_older_part_left_out' => 'boolean', 'versions' => 'array'];

    public static function dir($stateDir = null)
    {
        return $stateDir !== null ? $stateDir : Config::localDir() . '/state/feedback';
    }

    public static function path($id, $stateDir = null, $suffix = '.json')
    {
        return DraftStore::path(self::dir($stateDir), $id, 'feedback', $suffix);
    }

    /**
     * Builds the payload and saves it. $transcript is the conversation as exported, or null when the user left it out;
     * it is cut to the payload size first, then redacted, then fitted. $session is [id, title] of that conversation.
     * @return array the record the draft verb prints (id, sizes, session, transcript yes/no, the start of the text)
     */
    public static function create($text, $transcript, array $versions, $now, $stateDir = null, array $session = ['', ''])
    {
        $text = Redact::secrets(trim((string) $text));
        if ($text === '') {
            throw new CliError('the feedback text is empty', 'write what happened or what you want changed');
        }
        if (strlen($text) > self::TEXT_MAX) {
            throw new CliError('the feedback text is longer than ' . self::TEXT_MAX . ' bytes', 'shorten it; the conversation can carry the detail');
        }
        $older = false;
        if ($transcript !== null && strlen($transcript) > self::PAYLOAD_MAX) {
            $transcript = self::newest($transcript, self::PAYLOAD_MAX);
            $older = true;
        }
        $transcript = $transcript === null ? null : Redact::secrets($transcript);
        $payload = ['v' => 1, 'created_at' => (int) $now, 'session_id' => $transcript === null ? '' : (string) $session[0],
            'session_title' => $transcript === null ? '' : (string) $session[1], 'text' => $text, 'transcript' => $transcript,
            'transcript_older_part_left_out' => $older, 'versions' => array_map('strval', $versions)];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Too big: keep the newest part of the conversation (the end of a session is where the problem is). Measured on
        // the encoded JSON, where a newline or a quote takes two bytes.
        for ($i = 0; $transcript !== null && strlen($json) > self::PAYLOAD_MAX && $i < 8; $i++) {
            $transcript = self::newest($transcript, max(0, strlen($transcript) - (strlen($json) - self::PAYLOAD_MAX) - 1024));
            $payload['transcript'] = $transcript;
            $payload['transcript_older_part_left_out'] = true;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (strlen($json) > self::PAYLOAD_MAX) {
            throw new CliError('the feedback is too large to send', 'shorten the text or leave the conversation out');
        }
        list($id, $path) = DraftStore::save(self::dir($stateDir), $json);
        return ['id' => $id] + self::summary($payload, strlen($json)) + ['path' => $path];
    }

    /** The last $keep bytes of $s, from a line start, valid UTF-8. */
    private static function newest($s, $keep)
    {
        $cut = $keep <= 0 ? '' : substr($s, -$keep);
        $nl = strpos($cut, "\n");
        return (string) json_decode(json_encode($nl === false ? $cut : substr($cut, $nl + 1), JSON_INVALID_UTF8_IGNORE));
    }

    /** @return array [json, decoded payload] of a draft not yet sent whose content still matches its id. */
    public static function load($id, $stateDir = null)
    {
        list($json, $p) = DraftStore::load(self::dir($stateDir), $id, self::FIELDS, 'feedback');
        if (count(array_filter($p['versions'], 'is_string')) !== count($p['versions'])) {
            throw new CliError("draft $id is not a feedback draft", 'draft it again');
        }
        return [$json, $p];
    }

    /** After Pulsed Media took it: <id>.json becomes <id>.sent.json, which load() never sends. */
    public static function markSent($id, $stateDir = null)
    {
        return DraftStore::markSent(self::dir($stateDir), $id, 'feedback');
    }

    /** What the user sees before approving: sizes, the session, whether the conversation is in, and the start of the text. */
    public static function summary(array $p, $bytes)
    {
        $t = isset($p['transcript']) && is_string($p['transcript']) ? $p['transcript'] : null;
        $start = function_exists('mb_strcut') ? mb_strcut($p['text'], 0, 400, 'UTF-8') : substr($p['text'], 0, 400);
        return ['bytes' => (int) $bytes, 'text_bytes' => strlen($p['text']), 'conversation' => $t !== null,
            'conversation_bytes' => $t === null ? 0 : strlen($t), 'older_part_left_out' => !empty($p['transcript_older_part_left_out']),
            'session_id' => (string) $p['session_id'], 'session_title' => (string) $p['session_title'],
            'text_start' => $start . (strlen($start) < strlen($p['text']) ? '…' : '')];
    }

    /** @return array the drafts kept here, newest first: id, made (UTC), bytes, conversation yes/no, sent yes/no. */
    public static function all($stateDir = null)
    {
        return DraftStore::all(self::dir($stateDir), function ($id, $p, $f, $sent) {
            return ['id' => $id, 'made' => isset($p['created_at']) ? gmdate('Y-m-d H:i', (int) $p['created_at']) . 'Z' : '',
                'bytes' => (int) filesize($f), 'conversation' => isset($p['transcript']) && is_string($p['transcript']), 'sent' => $sent];
        });
    }
}
