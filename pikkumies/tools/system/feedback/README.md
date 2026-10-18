# feedback

Sends Pulsed Media feedback from inside Pikkumies: what you say, plus the conversation you were having with the agent, so the problem can be seen as it happened.

You type `/feedback` and say what went wrong or what you want. The agent writes a draft and shows you its size, which session is attached and how the text starts. Common password and key forms are removed from the text and the conversation before the draft is saved; that catches the usual forms, not every secret, so read the draft before sending it. Besides your text and the conversation, the draft carries the Pikkumies, Hermes and PMSS version numbers, and the conversation carries the session's model, provider and working directory. When you send it, Pikkumies asks you first, showing the same. The message is locked on your machine with Pulsed Media's public key, so the server that receives it cannot read it, and only Pulsed Media can; it is linked to your Pulsed Media account through your AI key. A sent draft is kept, marked sent, and never sent twice. If sending fails, the draft stays and the error says whether trying again later can help.

The question before sending catches the agent's own and casual sends. It is a safety prompt, not a security boundary: an agent steered by text it reads could still run the sender some other way, as it could run curl. What it sends is sealed to Pulsed Media either way.

It needs a Pulsed Media AI key (`~/.virsilipasKey`) and PHP with sodium. Hermes' own `/debug` is different: it uploads to a public paste site. For anything private, use `/feedback`, or `/debug local` to see the report without uploading it.
