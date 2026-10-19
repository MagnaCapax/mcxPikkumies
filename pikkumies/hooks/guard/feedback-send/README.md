# feedback-send

The approval prompt for `/feedback` ([ADR-0056](../../../../docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md)). Before `feedback send <id>` runs, Hermes asks the user. The question opens with where it goes and what goes: the user's text, the conversation transcript (all of it, its newest 1.5 MB, or none) and system information, to Pulsed Media at pikkumies.mcx.fi, locked and linked to their account; then the size, the file holding all of it and how the text starts. It fits the part of Hermes' approval panel that is sure to be seen on a small terminal. The model cannot answer that prompt. Unattended runs (cron, single queries) are refused by Hermes.

With approvals turned off (`--yolo`) or in an agent started by another agent, Hermes would let the send through with nobody asked, so this hook blocks it and the message gives the command for the user to run in their own terminal. A `/yolo` typed inside a session is not visible to hooks.

Only the plain command `php <path>/tools/system/feedback/feedback.php send <id>`, on its own, with the absolute (or `~/`) path of this Pikkumies' own tool, is asked about. The other forms it can see are blocked: a send chained with other commands, run from code or a background process, written into a file or a scheduled job, built with shell expansion, or of a draft that is missing, changed or already sent.

This catches the agent's own and casual sends. It is a safety prompt, not a security boundary: an agent steered by text it reads (a web page, a torrent name) runs as the same user and can still run the sender some other way, as it could run curl. What it sends is sealed to Pulsed Media either way.
