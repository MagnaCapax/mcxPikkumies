# SOP: Ask Väinämöinen for help

Väinämöinen is Pulsed Media's own support agent, the one who answers Pulsed Media support tickets. It works a level above you: where you run as this one account, it has the whole server, the hardware and the rest of Pulsed Media behind it. When a problem is out of your reach, it is the right place to ask, through the ticket skill. This page is when to ask and what to do first.

## 1. When

Ask when one of these holds:

- the fix needs something this account does not have: root, the server's hardware or console, another server, a change to the account or billing the user cannot make in the client area (what they can, point them there), or a fix to the platform itself (a wish for something new is feedback, not a ticket);
- you have searched every place below, done the review, and your attempts are used up;
- the user asks for a ticket. Then go straight to the ticket skill (`draft`, or `bundle --by user` when the diagnostics help) and skip steps 2 and 3.

Never ask before searching: a ticket that the documentation already answers costs the user a wait and Pulsed Media a reply.

Before any ticket: `ticket list` must show none already sent for it, and the service must be Pulsed Media's (elsewhere, give that provider's support link). A fault every account on the server sees (a degraded array, the network, an outage) is not this account's ticket: tell the user and offer feedback, a ticket only if they ask. Evidence that someone got into this account (logins, keys or processes the user does not know), or another account's files readable from here, also needs Pulsed Media, judged from owners, permissions and logs, never by opening other people's files; failed login attempts alone do not. What needs no staff action on this account (a tool bug you worked around, a missing or wrong page, a wish) is feedback, never a ticket (doctrine.md, "Tell Pulsed Media what is broken").

## 2. Search every place first

Search each, and write down for each what you searched (the exact words) and what it gave (the page or lesson, or nothing):

1. **Memory**, tiers 1 to 3: lessons, this host's documentation, other providers' documentation (`pikkumies.php search`), with at least two wordings of the problem.
2. **Documentation sources**: the provider's wiki and knowledge base pages that matched, read in full, not just their excerpts (the wiki and kb skills).
3. **Deep research**: the deep-research skill, Part 1.
4. **Web search**, when it is on: the error text and the app's name.
5. **Upstream issues**, when the upstream tool is there: the app's own issue tracker for the error.
6. **Sources that disagree**: settle them with SOP/resolve-conflicting-sources.md first.

`ticket bundle` reads the searches from this session's transcript and refuses a ticket you opened yourself without memory and documentation searches (and web searches when web search is on).

## 3. Review ten rounds, in writing

Write a review file, one line per round, each with its result. One angle each:

1. Re-read the user's ask: is this still what they want?
2. One more place: is there a source in step 2 you have not read?
3. Other keywords: search once more with different words.
4. Is it really out of reach, or is there a way within this account?
5. Is it a known issue upstream, with a fix or a workaround?
6. Could the user do it themselves (a panel setting, a reinstall, a password)?
7. The simplest next test: what one check would settle it?
8. What will Väinämöinen need: is it all in the diagnosis?
9. Is this worth a ticket, or is the answer already in hand?
10. Is anything secret in the draft (a password, key, token, private file)?

If a round finds the answer, stop and solve it: no ticket.

## 4. Open the ticket

Follow the ticket skill: write the diagnosis, then `bundle --by agent` with the review file, then `send`. On a Pulsed Media server the ticket goes out by itself unless the person turned `ticket.auto` off; elsewhere it goes only when the person turned that on, or after their yes. A no to a ticket ends agent tickets for this session; "never" means turn `ticket.auto` off with the pikkumies-configure skill. Tell the user that you asked Pulsed Media, what for, and the ticket number. Never promise a reply time.

## 5. What Väinämöinen can do that you cannot

Name the one that applies in each roadblock of the diagnosis:

- **root**: root on the server itself, not just this one account.
- **console**: the server's management console, which reaches it even when the operating system will not boot, and can power-cycle it.
- **fleet**: a view of the other servers and of past incidents across all customers, not only this box.
- **billing**: changes to the account and billing record itself, made with safety checks.
- **platform**: updates of the seedbox platform itself and moves of a whole account between servers.
- **code**: fixes in Pulsed Media's own product code, not just in this account.
- **memory**: Pulsed Media's accumulated experience of every past ticket and incident.

Say what it can do, never how: no tool names, hosts or paths.
