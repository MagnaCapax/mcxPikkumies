# ticket

Opens a Pulsed Media support ticket from inside Pikkumies, with your Pulsed Media AI key, so you do not need to log in to the client area for a question about your service.

You type `/ticket` and say what is wrong. The agent writes a subject and a message in your words, and shows you the draft: where it goes, the account email, the subject, how the message starts, the service, the priority and any files you named to attach, with their sizes. Common password and key forms are removed from the subject, the message and attached text and log files before the draft is saved; that catches the usual forms, not every secret, so read the draft before sending it. Images and PDFs are sent as they are. Nothing else is attached: no conversation, no system information.

The ticket needs the email of your Pulsed Media account (the owner's, or a user's who may open tickets) next to the key. The agent asks for it once and remembers it in `~/pikkumies/local/state/ticket/email`. On a Pulsed Media seedbox the ticket names this account's service; elsewhere you can name the service or leave it out.

When you send it, Pikkumies asks you first: where it goes, from which email, for which service, with which files, and how the text starts. You get the ticket number back, and your account gets the usual new-ticket email. A sent draft is kept, marked sent, and never sent twice. If sending fails, the draft stays and the error says whether trying again later can help. When the key or the email is not accepted, the answer is the same whichever it was, so check both.

With approvals turned off (`pikkumies run --yolo`), or in an agent started by another agent, the agent cannot send a ticket: nobody would be asked. It gives you the command to run in your own terminal instead.

The question before sending catches the agent's own and casual sends. It is a safety prompt, not a security boundary: an agent steered by text it reads could still post a ticket some other way, as it could run curl.

A ticket opened this way carries no authority for account changes: for a cancellation, refund, transfer, or a change of the account email or owner, support asks you to log in to the client area. It needs a Pulsed Media AI key (`~/.virsilipasKey`). Pulsed Media limits tickets opened this way per account and per hour; when a limit is reached, the tool says to try again later. Decision and options turned down: [ADR-0089](../../../../docs/adr/0089-support-tickets-with-the-ai-key.md).
