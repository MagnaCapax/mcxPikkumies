<?php
/**
 * ticket: one Pulsed Media support ticket, opened with the Pulsed Media AI key (ADR-0089, ADR-0015 tool unit). draft
 * saves exactly what would be posted and shows it; send posts that draft only after the user says yes in the chat
 * (hooks/guard/ticket-send, the orchestrator's ask policy, ADR-0073). Nothing is sent by draft or list, and nothing is
 * ever sent twice: a sent draft becomes <id>.sent.json.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Provider.php";
require_once "$lib/Cli.php";
require_once "$lib/Http.php";
require_once "$lib/Host.php";
require_once "$lib/Redact.php";
require_once __DIR__ . '/TicketDraft.php';
require_once __DIR__ . '/TicketSend.php';

// Test seams: a test sets these before including the file, so the real local/, PMSS marker and endpoint are never used.
$ticketConfig = isset($ticketConfig) ? $ticketConfig : Config::load();
$ticketStateDir = isset($ticketStateDir) ? $ticketStateDir : null;
$ticketEndpoint = isset($ticketEndpoint) ? $ticketEndpoint : TicketSend::ENDPOINT;
$ticketPmssDir = isset($ticketPmssDir) ? $ticketPmssDir : '/etc/seedbox/config';

$usage = <<<'U'
usage: php ticket.php draft --subject S (--text T | --text-file F) [--email E] [--priority Low|Medium|High]
                            [--service S | --no-service] [file ...] | send <id> | list
  draft  save the ticket (common password and key forms removed) as a draft and show what would be sent; nothing
         leaves this machine. --email is needed once and remembered; files: at most 3 the user named, each at most
         1 MiB, txt, log, png, jpg, jpeg or pdf
  send   open the ticket <id> at Pulsed Media with the Pulsed Media AI key; Pikkumies asks you first
  list   the drafts kept here, sent ones marked
U;

Cli::run($argv, [
    'draft' => function ($args, $opt) use ($ticketStateDir, $ticketPmssDir) {
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
        // On a Pulsed Media seedbox the account's own service is this Unix user (Host::family, as host detection reads it).
        $service = isset($opt['service']) && is_string($opt['service']) ? $opt['service'] : '';
        if (!isset($opt['service']) && empty($opt['no-service']) && Host::family($ticketPmssDir) === 'pmss') {
            $pw = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
            $service = is_array($pw) ? (string) $pw['name'] : (string) getenv('USER');
        }
        $rec = TicketDraft::create($opt['subject'], $text, isset($opt['email']) ? (string) $opt['email'] : null,
            isset($opt['priority']) ? (string) $opt['priority'] : 'Medium', $service, $args, time(), $ticketStateDir);
        return ['records' => [$rec], 'status' => ['next' => 'send ' . $rec['id'] . ' (Pikkumies asks you before anything is sent)']];
    },
    'send' => function ($args) use ($ticketConfig, $ticketStateDir, $ticketEndpoint) {
        $id = isset($args[0]) ? (string) $args[0] : '';
        TicketDraft::load($id, $ticketStateDir); // missing, changed or already sent: refused before anything else
        $lock = DraftStore::lock(TicketDraft::dir($ticketStateDir), $id, 'ticket'); // two sends of one draft never overlap
        list(, $p) = TicketDraft::load($id, $ticketStateDir); // again under the lock: another send may have just finished
        $answer = TicketSend::send(TicketDraft::body($p), TicketSend::key($ticketConfig), $ticketEndpoint);
        DraftStore::markSent(TicketDraft::dir($ticketStateDir), $id, 'ticket'); // never sent twice
        fclose($lock);
        return [['id' => $id, 'ticket' => $answer['ticket'], 'note' => $answer['ticket'] !== ''
            ? 'Ticket opened; the account gets the usual new-ticket email.'
            : 'Pulsed Media took it (HTTP 201) but gave no ticket number; the account gets the usual new-ticket email.']];
    },
    'list' => function () use ($ticketStateDir) {
        return TicketDraft::all($ticketStateDir);
    },
], $usage, ['no-service'], ['subject', 'text', 'text-file', 'email', 'priority', 'service']);
