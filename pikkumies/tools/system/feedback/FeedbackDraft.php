<?php
/**
 * A feedback draft (ADR-0056): the exact JSON that will be sealed and sent, saved once under
 * local/state/feedback/<id>.json, mode 0400, where <id> is the first 32 hex digits of its sha256. The approval hook and
 * the send verb both load it through load(), which refuses a file whose sha256 no longer starts with its id, or that
 * carries anything but the known fields, so a draft changed after the user approved it cannot go out under that
 * approval. A sent draft is renamed to <id>.sent.json and is never sent again.
 */
class FeedbackDraft
{
    const ID_RE = '/^[0-9a-f]{32}$/D';
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
        if (!is_string($id) || !preg_match(self::ID_RE, $id)) {
            throw new CliError('a feedback id is 32 lowercase hex digits', 'run list to see your drafts');
        }
        return self::dir($stateDir) . "/$id$suffix";
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
        $id = substr(hash('sha256', $json), 0, 32);
        $dir = self::dir($stateDir);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CliError("could not create $dir", 'check the disk and the permissions of ~/pikkumies/local/state');
        }
        $path = self::path($id, $stateDir);
        if (!is_file($path)) {
            $tmp = "$dir/.$id." . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !chmod($tmp, 0400) || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new CliError('could not save the draft', 'check the free disk space');
            }
        }
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
        $path = self::path($id, $stateDir);
        if (is_file(self::path($id, $stateDir, '.sent.json'))) {
            throw new CliError("draft $id was already sent", 'it is never sent twice; draft a new one to add something');
        }
        if (is_link($path) || !is_file($path)) {
            throw new CliError("no draft $id", 'run list to see your drafts, or draft a new one');
        }
        $json = (string) file_get_contents($path);
        if (substr(hash('sha256', $json), 0, 32) !== $id) {
            throw new CliError("draft $id was changed after it was made", 'draft it again; a changed draft is never sent');
        }
        $p = json_decode($json, true);
        $ok = is_array($p) && array_keys($p) === array_keys(self::FIELDS) && $p['v'] === 1;
        foreach ($ok ? self::FIELDS : [] as $k => $types) {
            $ok = $ok && in_array(gettype($p[$k]), explode('|', $types), true)
                && (!is_array($p[$k]) || count(array_filter($p[$k], 'is_string')) === count($p[$k]));
        }
        if (!$ok) {
            throw new CliError("draft $id is not a feedback draft", 'draft it again');
        }
        return [$json, $p];
    }

    /** After Pulsed Media took it: <id>.json becomes <id>.sent.json, which load() never sends. */
    public static function markSent($id, $stateDir = null)
    {
        return @rename(self::path($id, $stateDir), self::path($id, $stateDir, '.sent.json'));
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
        $rows = [];
        foreach (glob(self::dir($stateDir) . '/*.json') ?: [] as $f) {
            $id = preg_replace('/\.sent$/', '', basename($f, '.json'));
            if (!preg_match(self::ID_RE, $id)) {
                continue;
            }
            $p = json_decode((string) file_get_contents($f), true);
            $rows[] = ['id' => $id, 'made' => is_array($p) && isset($p['created_at']) ? gmdate('Y-m-d H:i', (int) $p['created_at']) . 'Z' : '',
                'bytes' => (int) filesize($f), 'conversation' => is_array($p) && isset($p['transcript']) && is_string($p['transcript']),
                'sent' => substr($f, -10) === '.sent.json'];
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['made'], $a['made']);
        });
        return $rows;
    }
}
