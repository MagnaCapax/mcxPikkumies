# ticket-send

Holds `/ticket` for the user's own yes ([ADR-0089](../../../../docs/adr/0089-support-tickets-with-the-ai-key.md), [ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)). Before `ticket send <id>` runs, the orchestrator's ask policy checks it: the question says that a support ticket goes to Pulsed Media at pulsedmedia.com, opened with the user's AI key, from which account email, for which service, at which priority, with which files and their sizes; then the file holding all of it, and the subject and how the message starts. Only the user's own explicit yes in the chat clears it; unattended runs (cron, single queries) have no evidence of the user's words, so the send stays blocked there too.

With approvals turned off (`--yolo`) or in an agent started by another agent, nobody would be asked, so this hook blocks the send and the message gives the command for the user to run in their own terminal. A `/yolo` typed inside a session is not visible to hooks.

Only the plain command `php <path>/tools/system/ticket/ticket.php send <id>`, on its own, with the absolute (or `~/`) path of this Pikkumies' own tool, is asked about. The other forms it can see are blocked: a send chained with other commands, run from code or a background process, written into a file or a scheduled job, built with shell expansion, or of a draft that is missing, changed or already sent.

It is the same check as [feedback-send](../feedback-send/README.md) (`lib/SendGuard.php`). It catches the agent's own and casual sends. It is a safety prompt, not a security boundary: an agent steered by text it reads runs as the same user and can still run the sender some other way, as it could run curl.
