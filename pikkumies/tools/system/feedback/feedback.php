<?php
/**
 * feedback: the user's feedback to Pulsed Media, with the conversation that explains it (ADR-0056, ADR-0015 tool unit).
 * draft saves exactly what would be sent and shows it; send seals that draft to Pulsed Media's public key and posts it,
 * only when the user's own request covers it (hooks/guard/feedback-send, the orchestrator's ask policy, ADR-0073, ADR-0101). Nothing is sent by draft or
 * list, and nothing is ever re-sent: a sent draft becomes <id>.sent.json.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Provider.php";
require_once "$lib/Cli.php";
require_once "$lib/Http.php";
require_once "$lib/ToolProc.php";
require_once "$lib/Redact.php";
require_once "$lib/Update.php";
require_once "$lib/HermesConfig.php";
require_once "$lib/HermesInstall.php";
require_once __DIR__ . '/FeedbackDraft.php';
require_once __DIR__ . '/FeedbackSend.php';

// Test seams: a test sets these before including the file, so the real local/ and the real endpoint are never used.
$feedbackConfig = isset($feedbackConfig) ? $feedbackConfig : Config::load();
$feedbackStateDir = isset($feedbackStateDir) ? $feedbackStateDir : null;
$feedbackEndpoint = isset($feedbackEndpoint) ? $feedbackEndpoint : FeedbackSend::ENDPOINT;
$feedbackTranscript = isset($feedbackTranscript) ? $feedbackTranscript : function () use ($feedbackStateDir) {
    return FeedbackSend::transcript($feedbackStateDir);
};
$feedbackPublicKey = dirname(__DIR__, 3) . '/config/feedback-public-key.txt';

$usage = <<<'U'
usage: php feedback.php draft (--text T | --text-file F) [--no-conversation] | send <id> | list
  draft  save the feedback and the current conversation (common password and key forms removed) as a draft, and
         show what would be sent; nothing leaves this machine. Read the draft before sending it
  send   lock the draft <id> so only Pulsed Media can read it and send it, when you asked for it
  list   the drafts kept here, sent ones marked
U;

Cli::run($argv, [
    'draft' => function ($args, $opt) use ($feedbackStateDir, $feedbackTranscript) {
        if (isset($opt['text-file'])) {
            $f = (string) $opt['text-file'];
            if (!is_file($f) || is_link($f) || filesize($f) > FeedbackDraft::TEXT_MAX) {
                throw new CliError('--text-file must be a plain file of at most ' . FeedbackDraft::TEXT_MAX . ' bytes', 'or pass --text');
            }
            $text = (string) file_get_contents($f);
        } elseif (isset($opt['text']) && is_string($opt['text'])) {
            $text = $opt['text'];
        } else {
            throw new CliError('no feedback text', 'pass --text "..." or --text-file <file> with the user\'s own words');
        }
        FeedbackSend::sweepExports($feedbackStateDir);
        $transcript = !empty($opt['no-conversation']) ? null : $feedbackTranscript();
        $session = $transcript === null ? ['', ''] : [(string) getenv('HERMES_SESSION_ID'), FeedbackSend::title($transcript)];
        $rec = FeedbackDraft::create($text, $transcript, FeedbackSend::versions(), time(), $feedbackStateDir, $session);
        return ['records' => [$rec], 'status' => ['next' => 'send ' . $rec['id'] . ' (sent only when the user asked for it)']];
    },
    'send' => function ($args) use ($feedbackConfig, $feedbackStateDir, $feedbackEndpoint, $feedbackPublicKey) {
        $id = isset($args[0]) ? (string) $args[0] : '';
        list($json) = FeedbackDraft::load($id, $feedbackStateDir);
        $receipt = FeedbackSend::send($json, FeedbackSend::pmKey($feedbackConfig), $feedbackPublicKey, $feedbackEndpoint);
        FeedbackDraft::markSent($id, $feedbackStateDir); // never sent twice, whatever runs the send again
        return [['id' => $id] + $receipt + ['note' => 'Received and stored privately for Pulsed Media to review. Quote the received id in a ticket to have it deleted.']];
    },
    'list' => function () use ($feedbackStateDir) {
        return FeedbackDraft::all($feedbackStateDir);
    },
], $usage, ['no-conversation'], ['text', 'text-file']);
