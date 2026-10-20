# session-rewrite

Offline account-rename support for one of `rtorrent`, `qbittorrent` or `transmission`: rewrites the path
prefix its own session or resume files hold, while the client is stopped. See `USAGE.md` for the verb, the
exact keys touched per client and why, and `skill.md` for when an agent should reach for this instead of a
client's own live `repath` verb.

## Why offline, and why raw-preserving

A live client has an RPC or API call that moves its own idea of where a torrent's data lives (`repath`, in
every client unit here). None of those calls exist while the client is stopped, which is exactly the
situation an account rename leaves behind: the files move with the account, but nothing has told the
(still-stopped) client that its data's path prefix changed. `session-rewrite` edits the client's own
on-disk session state directly instead, using just enough of the bencode format
(`pikkumies/lib/Bencode.php`: finding a dictionary's keys and decoding one string value) to find and replace
exactly the known path keys (see `USAGE.md`'s table), copying every other byte of the file unchanged. It
never builds a general bencode encoder and never touches a key it has not verified from the client's own
source.

## What this is not

- Not a live client: it has no `list`, `show`, `add` or any of the shared torrent-clients verbs, because
  there is no running client to ask while the session files are being rewritten. The category's shared-verb
  contract test (`tests/ContractTest.php`) exempts `session-rewrite` from that table by name for this reason.
- Not a data mover: `--to` only changes what the session files SAY the path is. Move or copy the actual
  files separately, before `--apply`, and definitely before the client is started again.
- Not for Deluge: `torrents.state` is a Python pickle, not bencode, and nothing here parses or edits
  pickles. Deluge's account rename goes through its own live `repath` once it is running again.
