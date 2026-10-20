<?php
/**
 * Hook unit (pre_tool_call, run by hooks/orchestrator.php, ADR-0030): the approval gate for /feedback (ADR-0056).
 * `php <this Pikkumies' tools/system/feedback/feedback.php> send <id>` alone in the terminal, the path absolute (or ~/)
 * and canonical, gets an approve built from the draft file itself (FeedbackPrompt: where it goes and what goes first). The
 * orchestrator's ask policy never passes it on to Hermes, which runs with approvals off: the call is blocked until the
 * review finds the user's own yes in the chat, and blocked outright where no yes can be checked (ADR-0073). --yolo, the
 * default run mode, changes nothing here; at depth 2 or more (an agent started by an agent) it is blocked and not sent
 * (lib/SendGuard.php). The other forms of a feedback send it can see are blocked, and so is a call of the exit rating
 * (FeedbackSurvey::, ADR-0097), which only bin/pikkumies runs. The check itself is lib/SendGuard.php, shared with
 * guard/ticket-send (ADR-0089). A safety prompt that catches the agent's own and casual sends, not a security
 * boundary (AGENTS.md); the payload is sealed to Pulsed Media either way. Fails closed (hook.md).
 */
require_once dirname(__DIR__, 3) . '/lib/SendGuard.php';

SendGuard::main('feedback', 'tools/system/feedback/feedback.php', function ($id) {
    require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackDraft.php';
    require_once dirname(__DIR__, 3) . '/tools/system/feedback/FeedbackPrompt.php';
    list($json, $draft) = FeedbackDraft::load($id);
    // The session id and title are in the draft record the agent shows and in the file.
    return FeedbackPrompt::message(FeedbackDraft::summary($draft, strlen($json)), FeedbackDraft::path($id), getenv('HOME'));
}, [
    // The exit rating (FeedbackSurvey, ADR-0097) is asked by bin/pikkumies on the terminal after Hermes exits, never by the agent.
    '/FeedbackSurvey\s*::|["\']FeedbackSurvey["\']/i' => 'the session rating is asked by Pikkumies itself on the terminal after the session closes, and only the person answers it; the agent never runs it.',
]);
