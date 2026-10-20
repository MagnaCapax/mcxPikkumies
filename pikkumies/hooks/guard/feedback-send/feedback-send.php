<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): the approval gate for /feedback (ADR-0056).
 * `php <this Pikkumies' tools/system/feedback/feedback.php> send <id>` alone in the terminal, the path absolute (or ~/)
 * and canonical, gets an approve built from the draft file itself (FeedbackPrompt: where it goes and what goes first). The
 * orchestrator's ask policy never passes it on to Hermes, which runs with approvals off: the call is blocked until the
 * review finds the user's own yes in the chat, and blocked outright where no yes can be checked (ADR-0073). Under --yolo
 * (HERMES_YOLO_MODE) or at depth 2 or more it is blocked here and the person runs it in their own terminal (ADR-0056
 * amendment 2026-10-19). The other forms of a feedback send it can see are blocked. The check itself is lib/SendGuard.php,
 * shared with guard/ticket-send (ADR-0089). A safety prompt that catches the agent's own and casual sends, not a security
 * boundary (AGENTS.md); the payload is sealed to Pulsed Media either way. Fails closed (hook.md).
 */
require_once dirname(__DIR__, 3) . '/lib/SendGuard.php';

SendGuard::main('feedback', 'tools/system/feedback/feedback.php', function ($id) {
    require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackDraft.php';
    require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackPrompt.php';
    list($json, $draft) = FeedbackDraft::load($id);
    // The session id and title are in the draft record the agent shows and in the file.
    return FeedbackPrompt::message(FeedbackDraft::summary($draft, strlen($json)), FeedbackDraft::path($id), getenv('HOME'));
});
