<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): the approval gate for /feedback (ADR-0056).
 * `php <this Pikkumies' tools/system/feedback/feedback.php> send <id>` alone in the terminal, the path absolute (or ~/)
 * and canonical, gets Hermes' approval prompt, built from the draft file itself. Blocked: a feedback path followed by
 * `send`, the sender's class names with the word send, and a feedback path with shell expansion and the word send, in a
 * terminal command, code, process input, a file written or patched, or a scheduled job. A safety prompt that catches the
 * agent's own and casual sends, not a security boundary: an agent steered by text it reads can still run the sender
 * some other way, as it could run curl (AGENTS.md); the payload is sealed to Pulsed Media either way.
 * Fails closed (hook.md): a crash or an unreadable payload blocks. A regex engine error is a block, never an allow.
 */
$stdin = (string) stream_get_contents(STDIN);
$payload = json_decode($stdin, true);
$block = function ($why) {
    echo json_encode(['action' => 'block', 'message' => "Pikkumies safety: $why Not run."]) . "\n";
    exit(0);
};
if (!is_array($payload)) {
    $block('the tool call could not be read to check it.');
}
$tool = isset($payload['tool_name']) ? (string) $payload['tool_name'] : '';
$input = isset($payload['tool_input']) && is_array($payload['tool_input']) ? $payload['tool_input'] : [];
$fields = ['terminal' => ['command'], 'execute_code' => ['code'], 'process_manage' => ['data'], 'write_file' => ['content'],
    'patch' => ['new_string', 'patch']];
if ($tool === 'cronjob_manage') {
    $text = (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} elseif (isset($fields[$tool])) {
    $parts = [];
    foreach ($fields[$tool] as $k) {
        $parts[] = isset($input[$k]) && is_scalar($input[$k]) ? (string) $input[$k] : '';
    }
    $text = implode("\n", $parts);
} else {
    exit(0); // a tool that runs nothing: no opinion
}
$bare = str_replace(['"', "'", '\\'], '', $text);
if (stripos($bare, 'feedback') === false) {
    exit(0); // not about feedback: no opinion
}
$hay = $text . "\n" . $bare;
$hit = function ($re) use ($hay, $block) {
    $r = preg_match($re, $hay);
    if ($r === false) {
        $block('a command too long or complex to check.');
    }
    return $r === 1;
};
// A path to the feedback tool or its directory: a token with "feedback" and a slash or .php in it.
$ref = '(?<!\S)(?=\S*feedback)(?=\S*(?:\/|\.php))';
$sendWord = '(?<![A-Za-z0-9_-])send(?![A-Za-z0-9_-])';
$own = realpath(dirname(__DIR__, 3) . '/tools/system/feedback/feedback.php');
$plain = '`php ' . ($own === false ? '<path>/feedback.php' : $own) . ' send <id>`';
if ($tool === 'terminal' && preg_match('/^\s*php[^\S\n]+(\S+)[^\S\n]+send[^\S\n]+([0-9a-f]{32})\s*$/D', $text, $m) === 1) {
    // ~/ is what the shell expands with HOME. The path must then be exactly this tool's canonical path: absolute, no
    // symlink, no `..`, so what the user approves is what runs whatever the terminal's directory.
    $path = $m[1];
    $home = rtrim((string) getenv('HOME'), '/');
    if (substr($path, 0, 2) === '~/' && $home !== '') {
        $path = $home . substr($path, 1);
    }
    if ($own === false || $path !== $own) {
        $block("run the feedback send on its own, exactly $plain, with nothing before or after it.");
    }
    $id = $m[2];
} else {
    $verb = $hit('/' . $ref . '\S+\s+[\[("\',]*(?:' . $sendWord . '|[^\s]*[$`])/i'); // the word after the tool path
    $classes = $hit('/feedback(?:send|draft)/i') && $hit('/' . $sendWord . '/i');
    $expansion = $hit('/' . $ref . '/i') && $hit('/[*?\[$`]/') && $hit('/' . $sendWord . '/i');
    if ($verb || $classes || $expansion) {
        $block("feedback is sent only by the plain command $plain in the terminal, on its own. A feedback path next to shell "
            . 'characters ($ * ? [ `) and the word send is blocked too; for a draft whose text has them, write the text to a file and use --text-file.');
    }
    exit(0); // draft or list, or a mention that runs no send: they never leave the machine
}
// Loaded only for a feedback send: every other tool call costs nothing, also in a tree without the feedback unit.
require_once dirname(__DIR__, 3) . '/lib/Config.php';
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackDraft.php';
try {
    list($json, $draft) = FeedbackDraft::load($id);
} catch (CliError $e) {
    $block($e->getMessage() . '; ' . $e->hint . '.');
}
$s = FeedbackDraft::summary($draft, strlen($json));
/** One line, quotes escaped: model-written text cannot add lines or close the quote it is shown in. */
$quote = function ($t) {
    return '"' . str_replace('"', '\\"', (string) preg_replace('/\s+/u', ' ', Cli::cleanString((string) $t))) . '"';
};
$conversation = $s['conversation']
    ? 'yes, session ' . $quote($s['session_id']) . ($s['session_title'] !== '' ? ' titled ' . $quote($s['session_title']) : '')
        . ', ' . $s['conversation_bytes'] . ' bytes with common password and key forms removed'
        . ($s['older_part_left_out'] ? ', the older part left out to fit' : '') . "; it also carries that session's model, provider and working directory"
    : 'no';
// At most ~400 bytes of text and 120 of title plus fixed parts: always under the guard's 4000-character rule.
$message = 'Pikkumies: sends feedback to Pulsed Media (pikkumies.mcx.fi), locked on this machine so only Pulsed Media can read it, '
    . "linked to your Pulsed Media account through your AI key. Draft $id, {$s['bytes']} bytes, with the Pikkumies, Hermes and PMSS "
    . "version numbers. Your conversation included: $conversation. Everything that would be sent is in " . FeedbackDraft::path($id)
    . ", read it before you answer. Command [$id]: " . trim($text) . '. The text, written by the agent from what you said, starts: '
    . $quote($s['text_start']);
echo json_encode(['action' => 'approve', 'rule_key' => 'pikkumies-send:' . $id, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
