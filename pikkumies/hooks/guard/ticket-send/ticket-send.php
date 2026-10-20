<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): the approval gate for /ticket (ADR-0089), the same
 * check as guard/feedback-send (lib/SendGuard.php). `php <this Pikkumies' tools/system/ticket/ticket.php> send <id>`
 * alone in the terminal, the path absolute (or ~/) and canonical, gets an approve built from the draft file itself
 * (TicketPrompt: where it goes, the account email, service, priority, files, then the text). The orchestrator's ask
 * policy runs it only when what the user asked for covers it and refuses it otherwise (ADR-0073, ADR-0101); at depth 2 or
 * more it is blocked here and reported as skipped; --yolo, the default run mode, changes nothing. The
 * other forms of a ticket send it can see are blocked. One exception (ADR-0100): a ticket the agent bundled by itself,
 * bundled while ticket.auto was on (scrubbed, "has not seen"), with this account's processes and files only, collected
 * without root, every file as bundle made it, and never with this hook running as root, runs without a question while
 * ticket.auto is on (unset: on on a Pulsed Media server, off elsewhere; read from local/config.php, never the
 * overlay); the tool's own caps bound those. A safety prompt, not a security boundary (AGENTS.md). Fails closed.
 */
require_once dirname(__DIR__, 3) . '/lib/SendGuard.php';

SendGuard::main('ticket', 'tools/system/ticket/ticket.php', function ($id) {
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketDraft.php';
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketPrompt.php';
    list(, $p) = TicketDraft::load($id);
    return TicketPrompt::message(TicketDraft::summary($p, strlen(TicketDraft::body($p))), TicketDraft::path($id), getenv('HOME'));
}, [], function ($id) {
    require_once dirname(__DIR__, 3) . '/lib/Redact.php';
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketDraft.php';
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketAuto.php';
    list(, $p) = TicketDraft::load($id);
    if (TicketAuto::looksLikeBundle($p)) {
        TicketAuto::checkFiles($p); // a bundle draft written by hand, or files under the bundle's names, is refused whatever the setting
    }
    return TicketAuto::goesAlone($p, Config::fileConfig());
});
