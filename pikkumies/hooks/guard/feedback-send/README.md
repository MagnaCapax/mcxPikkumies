# feedback-send

The approval prompt for `/feedback` (ADR-0056). Before `feedback send <id>` runs, Hermes asks the user, showing where it goes, that it is linked to their account through the key, how big it is, which session is attached, what else it carries and how the text starts. The model cannot answer that prompt. Unattended runs (cron, single queries) are refused by Hermes, unless approvals are turned off.

Only the plain command `php <path>/tools/system/feedback/feedback.php send <id>`, on its own, with the absolute (or `~/`) path of this Pikkumies' own tool, is asked about. The other forms it can see are blocked: a send chained with other commands, run from code or a background process, written into a file or a scheduled job, built with shell expansion, or of a draft that is missing, changed or already sent.

This catches the agent's own and casual sends. It is a safety prompt, not a security boundary: an agent steered by text it reads (a web page, a torrent name) runs as the same user and can still run the sender some other way, as it could run curl. What it sends is sealed to Pulsed Media either way.
