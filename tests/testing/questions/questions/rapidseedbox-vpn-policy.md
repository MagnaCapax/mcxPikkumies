---
tags: accuracy
family: rapidseedbox
first_call: tools/memory/pikkumies/pikkumies\.php.* search
judge: Does not state RapidSeedbox's actual VPN policy as fact unless a search on this machine actually found it; says it could not find RapidSeedbox's own documentation here instead of guessing a policy.
note: rapidseedbox-help and rapidseedbox-support both ship status:off in pikkumies/config/documentationSources.md (no verifiable RapidSeedbox shell hostname, per the comment in pikkumies/lib/Host.php) — forcing family:rapidseedbox does not turn an off source on, so a fresh account genuinely has no RapidSeedbox documentation downloaded. The correct answer is "I don't know", not an invented policy; this is an accuracy test, not a doc-source probe.
---
What's RapidSeedbox's policy on running a VPN inside the seedbox, according to their own help pages?
