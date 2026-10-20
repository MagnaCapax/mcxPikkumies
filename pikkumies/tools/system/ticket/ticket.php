<?php
/**
 * ticket: one Pulsed Media support ticket, opened with the Pulsed Media AI key (ADR-0089, ADR-0015 tool unit). draft
 * saves exactly what would be posted and shows it; bundle does the same for a ticket with the diagnostics attached (the
 * system facts, process list and logs, the diagnosis and searches, the transcript; ADR-0100); send posts a draft only
 * after the user says yes in the chat (hooks/guard/ticket-send, the orchestrator's ask policy, ADR-0073), except a
 * ticket the agent bundled by itself while ticket.auto is on, which goes out alone within the caps. Nothing is sent by
 * draft, bundle or list, and nothing is ever sent twice: a sent draft becomes <id>.sent.json.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Config', 'Provider', 'Cli', 'Http', 'Host', 'Redact', 'ConfigureTiers'] as $c) {
    require_once "$lib/$c.php";
}
require_once __DIR__ . '/TicketDraft.php';
require_once __DIR__ . '/TicketSend.php';
require_once __DIR__ . '/TicketAuto.php';

// Test seams: a test sets these before including the file, so the real local/, PMSS marker, machine and endpoint are never used.
$ticketConfig = isset($ticketConfig) ? $ticketConfig : Config::load();
$ticketStateDir = isset($ticketStateDir) ? $ticketStateDir : null;
$ticketEndpoint = isset($ticketEndpoint) ? $ticketEndpoint : TicketSend::ENDPOINT;
$ticketPmssDir = isset($ticketPmssDir) ? $ticketPmssDir : '/etc/seedbox/config';
$ticketNow = isset($ticketNow) ? $ticketNow : time();
// ticket bundle reads the machine through these (TicketCollect); each loads what it needs only when bundle runs.
$ticketRun = isset($ticketRun) ? $ticketRun : function (array $argv) use ($lib) {
    require_once "$lib/ToolProc.php";
    return ToolProc::run($argv);
};
$ticketProfile = isset($ticketProfile) ? $ticketProfile : function () use ($lib) {
    require_once "$lib/Detect.php";
    return Detect::cached();
};
$ticketRoot = isset($ticketRoot) ? $ticketRoot : function () use ($lib) {
    require_once "$lib/Detect.php";
    return Detect::rootAccess(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
};
$ticketTranscript = isset($ticketTranscript) ? $ticketTranscript : function () use ($ticketStateDir) {
    return FeedbackSend::transcript(TicketDraft::dir($ticketStateDir));
};
$ticketMetricsDir = isset($ticketMetricsDir) ? $ticketMetricsDir : Config::localDir() . '/state/memory-metrics';

$usage = <<<'U'
usage: php ticket.php draft --subject S (--text T | --text-file F) [--email E] [--priority Low|Medium|High]
                            [--service S | --no-service] [file ...]
     | php ticket.php bundle --subject S --diagnosis-file F [--review-file F] [--by agent|user] [--log P,P]
                            [--no-conversation] [--email E] [--priority P] [--service S | --no-service]
     | send <id> | list
  draft   save the ticket (common password and key forms removed) as a draft and show what would be sent; nothing
          leaves this machine. --email is needed once and remembered; files: at most 3 the user named, each at most
          1 MiB, txt, log, png, jpg, jpeg or pdf
  bundle  save a ticket with the diagnostics attached: system facts, process list and logs; the diagnosis, review and
          searches; the transcript. --by agent (the default) needs the searches and a ten-round review
  send    open the ticket <id> at Pulsed Media with the Pulsed Media AI key; Pikkumies asks you first, except for an
          agent bundle while ticket.auto is on
  list    the drafts kept here, sent ones marked
U;

/** The service a ticket names: --service, nothing with --no-service, else this Unix user on a Pulsed Media seedbox. */
$ticketService = function (array $opt) use ($ticketPmssDir) {
    $service = isset($opt['service']) && is_string($opt['service']) ? $opt['service'] : '';
    if (!isset($opt['service']) && empty($opt['no-service']) && Host::family($ticketPmssDir) === 'pmss') {
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $service = is_array($pw) ? (string) $pw['name'] : (string) getenv('USER');
    }
    return $service;
};

Cli::run($argv, [
    'draft' => function ($args, $opt) use ($ticketStateDir, $ticketService) {
        if (isset($opt['text-file'])) {
            $f = (string) $opt['text-file'];
            if (!is_file($f) || is_link($f) || filesize($f) > 4 * TicketDraft::MESSAGE_MAX) {
                throw new CliError('--text-file must be a plain file of at most ' . (4 * TicketDraft::MESSAGE_MAX) . ' bytes', 'or pass --text');
            }
            $text = (string) file_get_contents($f);
        } elseif (isset($opt['text']) && is_string($opt['text'])) {
            $text = $opt['text'];
        } else {
            throw new CliError('no ticket message', 'pass --text "..." or --text-file <file> with what the user wants support to know');
        }
        if (!isset($opt['subject']) || !is_string($opt['subject'])) {
            throw new CliError('no subject', 'pass --subject "..." : one short line');
        }
        $rec = TicketDraft::create($opt['subject'], $text, isset($opt['email']) ? (string) $opt['email'] : null,
            isset($opt['priority']) ? (string) $opt['priority'] : 'Medium', $ticketService($opt), $args, time(), $ticketStateDir);
        return ['records' => [$rec], 'status' => ['next' => 'send ' . $rec['id'] . ' (Pikkumies asks you before anything is sent)']];
    },
    'bundle' => function ($args, $opt) use ($lib, $ticketConfig, $ticketStateDir, $ticketService, $ticketRun, $ticketProfile, $ticketRoot, $ticketTranscript, $ticketMetricsDir, $ticketNow) {
        require_once __DIR__ . '/TicketBundleRun.php';
        $rec = TicketBundleRun::run($opt, [
            'config' => $ticketConfig, 'fileConfig' => Config::fileConfig(), 'stateDir' => $ticketStateDir, 'service' => $ticketService($opt),
            'run' => $ticketRun, 'profile' => $ticketProfile, 'root' => $ticketRoot, 'transcript' => $ticketTranscript,
            'metricsDir' => $ticketMetricsDir, 'now' => $ticketNow, 'lib' => $lib,
        ]);
        $alone = $rec['goes_out'] === 'by itself';
        return ['records' => [$rec], 'status' => ['next' => 'send ' . $rec['id'] . ($alone ? ' (goes out by itself: ticket.auto is on)' : ' (Pikkumies asks you before anything is sent)')]];
    },
    'send' => function ($args) use ($ticketConfig, $ticketStateDir, $ticketEndpoint, $ticketNow) {
        $id = isset($args[0]) ? (string) $args[0] : '';
        $dir = TicketDraft::dir($ticketStateDir);
        TicketDraft::load($id, $ticketStateDir); // missing, changed or already sent: refused before anything else
        $lock = DraftStore::lock($dir, $id, 'ticket'); // two sends of one draft never overlap
        list(, $p) = TicketDraft::load($id, $ticketStateDir); // again under the lock: another send may have just finished
        if (TicketAuto::looksLikeBundle($p)) {
            TicketAuto::checkFiles($p); // a bundle draft written by hand, or files under the bundle's names, is never sent
        }
        $body = TicketDraft::body($p);
        $key = TicketSend::key($ticketConfig);
        // A ticket the agent bundled that goes out without the user's yes: one lock over the caps, the request and its record.
        $alone = TicketAuto::goesAlone($p, Config::fileConfig());
        $answer = !$alone ? TicketSend::send($body, $key, $ticketEndpoint) : TicketAuto::locked($dir, function () use ($dir, $id, $p, $body, $key, $ticketEndpoint, $ticketNow) {
            TicketAuto::checkCaps(TicketAuto::records($dir, $ticketNow - 86400), $p['bundle']['signature']);
            // The attempt is on record before it is made: a crash or a kill mid-send still counts toward the cap (-2).
            $attempt = bin2hex(random_bytes(8));
            $line = ['id' => $id, 'attempt' => $attempt, 'ts' => (int) $ticketNow, 'status' => -2, 'ticket' => '', 'signature' => $p['bundle']['signature']];
            if (!TicketAuto::record($dir, $line)) {
                throw new CliError('the automatic-ticket record could not be written, so the ticket was not sent', 'check the disk and the permissions of ~/pikkumies/local/state/ticket; the draft is kept');
            }
            $code = -1;
            $answer = ['ticket' => ''];
            try {
                $answer = TicketSend::send($body, $key, $ticketEndpoint, $code);
            } finally {
                TicketAuto::record($dir, ['status' => $code, 'ticket' => $answer['ticket']] + $line);
            }
            return $answer;
        });
        DraftStore::markSent($dir, $id, 'ticket'); // never sent twice
        fclose($lock);
        return [['id' => $id, 'ticket' => $answer['ticket'], 'auto' => $alone, 'note' => $answer['ticket'] !== ''
            ? 'Ticket opened; the account gets the usual new-ticket email.'
            : 'Pulsed Media took it (HTTP 201) but gave no ticket number; the account gets the usual new-ticket email.']];
    },
    'list' => function () use ($ticketStateDir) {
        return TicketDraft::all($ticketStateDir);
    },
], $usage, ['no-service', 'no-conversation'], ['subject', 'text', 'text-file', 'email', 'priority', 'service', 'diagnosis-file', 'review-file', 'by', 'log']);
