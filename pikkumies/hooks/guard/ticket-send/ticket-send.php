<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): the approval gate for /ticket (ADR-0089), the same
 * check as guard/feedback-send (lib/SendGuard.php). `php <this Pikkumies' tools/system/ticket/ticket.php> send <id>`
 * alone in the terminal, the path absolute (or ~/) and canonical, gets an approve built from the draft file itself
 * (TicketPrompt: where it goes, the account email, service, priority, files, then the text). The orchestrator's ask
 * policy blocks it until the review finds the user's own yes in the chat, and outright where no yes can be checked
 * (ADR-0073); under --yolo or at depth 2 or more it is blocked here and the person runs it in their own terminal. The
 * other forms of a ticket send it can see are blocked. A safety prompt, not a security boundary (AGENTS.md). Fails closed.
 */
require_once dirname(__DIR__, 3) . '/lib/SendGuard.php';

SendGuard::main('ticket', 'tools/system/ticket/ticket.php', function ($id) {
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketDraft.php';
    require_once dirname(__DIR__, 3) . '/tools/system/ticket/TicketPrompt.php';
    list(, $p) = TicketDraft::load($id);
    return TicketPrompt::message(TicketDraft::summary($p, strlen(TicketDraft::body($p))), TicketDraft::path($id), getenv('HOME'));
});
