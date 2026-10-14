# Resolve conflicting sources

Use this when two sources (documentation, lessons, what you see on the machine, what the user said) say different things about the same question. The goal is the canonical answer and an honest account of the disagreement, never a blend of the two.

## 1. Write the claims down

For each claim note:

- what exactly it says, quoted;
- the source and its trust level (live, official, lessons, upstream, community, recall);
- its date (the page's `updated` line, the lesson's date, or "now" for live);
- its scope: which topic, which product or plan, which version, which kind of machine.

Often the "conflict" disappears here: the two claims are about different plans, versions or machines. If so, say which one applies to this user and why.

## 2. Pick a first answer by these rules, in order

1. **Can it be checked on this machine?** Then check it. What this machine actually is and does wins over any page about it.
2. **Policies, prices, terms, what a service includes:** the official source that lists the topic under `covers` wins. A machine that disagrees with a policy is a mismatch to report, not a new policy.
3. **Otherwise the higher trust level wins**, if the topic is in its `covers`. Outside its `covers` a source is only a lead.
4. **Same level:** the newer and the more specific claim wins. A page about this exact app version beats a general one.

## 3. Attack the pick (up to three rounds)

Each round, try to prove the current pick wrong:

- Is it out of date? Look for a newer page, a changelog, a dated note.
- Does its scope really match this user's case?
- Search again with different words (`pikkumies.php search`), and read the pages, not just the titles.
- What would you see on this machine if the pick were wrong? Look for it.

If a round changes the pick, run another round on the new pick. Stop when a round changes nothing, or after three rounds.

## 4. Tell the user

- The answer you settled on, its source, trust level and page address.
- That another source says otherwise, what it says, and why you did not follow it.
- If three rounds did not settle it: say so plainly, give both claims with their sources, and say what would settle it (often: ask the provider's support).

Never present an unresolved conflict as settled, and never merge two claims into one that neither source makes.

## 5. Keep what you learned

If you settled a real conflict (say a wiki page is out of date and the knowledge base is current), save a lesson with the memory skill (`pikkumies.php save`): both claims, the sources, the deciding evidence. The next search will find it.
