# secrets

An encrypted place for the passwords and API keys that cannot be an SSH key: a NAS login, a web panel, an API token. SSH keys stay the first choice wherever a login can be a key ([ADR-0049](../../../../docs/adr/0049-backup-remote-over-ssh.md) sets them up for remote backups). Decision and reasons: [ADR-0085](../../../../docs/adr/0085-encrypted-secret-store.md).

## How you use it

1. In your own terminal, run `php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php unlock`. The first time it asks for a new passphrase twice and creates the store. Choose one you will remember: 12 or more characters, a few random words work well (10 is the minimum; below 12 you get a note). Nothing keeps it. Note the `fingerprint` it prints.
2. Add a secret: `php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php set nas-login`. It first asks for the vault passphrase, then for the value, neither shown. The agent can tell you the exact line to run; it never asks you to paste a value into the chat.
3. The agent uses it by its handle (`nas-login`): the tool that needs it gets the value, and the agent sees only the handle and that tool's output with the value taken out.

The store stays unlocked for 8 hours (`unlock --ttl 30m` to `--ttl 30d`), until `lock`, or until the machine restarts. Strictly, the key file stays until it has expired and the next secrets command (or a tool's next read of the store) after that removes it; nothing runs in the background to delete it on the minute. When the store is locked, the agent says so and asks you to unlock again. **The agent cannot unlock it**: passphrases are read only from your terminal (`/dev/tty`), never from a pipe, never stored, never on a command line, and never given to the agent. (A terminal is no proof a person is there, since the agent can open one itself, Hermes `pty=true` ([ADR-0053](../../../../docs/adr/0053-email-category.md)); what it lacks is the passphrase.)

## Why `set` asks for the passphrase

The agent runs as your account, so it could create a store of its own before you do, or swap `vault.enc` and the cached key for ones it made. A `set` or `rotate` that trusted the cached key would then put your next secret where the agent can read it, even after `lock`. So at a terminal, `set`, `rotate` and `remove` ask for your passphrase on `/dev/tty`, check that its key opens the store and equals the cached key, and refuse otherwise; they work this way even while the store is locked. If your passphrase does not open the store, it is not the one you made: `status` shows when and on which terminal it was created (`created_at`, `created_on`) and, while unlocked, the key `fingerprint`. Move that `vault.enc` away and run `unlock` to make your own. Without a terminal (a script, or the agent piping a value a command generated) the cached key is used and the record says `passphrase_checked: false`.

## What it protects, and what it does not

| Threat | Protected? |
|---|---|
| The files are lost or copied: a backup, a stolen disk image, a careless copy of the home | Yes. `vault.enc` is ciphertext; opening it needs the passphrase, and Argon2id makes each guess cost memory and time. The unlocked key is never under the home, so a backup or a copy of the home never holds it. |
| Someone reads `vault.enc` and changes it | A changed file, header included, is refused; no secret is read from it. |
| Root on this machine | No. Root can read the unlocked key or the memory of a running tool. Nothing on a shared server can stop that. |
| The agent, steered by text it reads, while the store is unlocked | Narrowed, not closed. It sees handles, not values; `use` takes the value's raw, base64, base64url, hex, URL and JSON forms out of what the agent sees, also when control or zero-width characters are mixed into them. But a process running as you can read the unlocked key file, and a tool given a secret can misuse it. Lock the store (`lock`) when you do not need it unlocked. |
| `use` running only Pikkumies tools | A guard for the agent's own mistakes, not a wall. The rule is "a `.php` file under this install's `tools/`", and `tools/` lives in your home, owned by you, so the agent can put its own file there. The child gets the value in one variable, set only for the child, and loses `PHPRC`, `PHP_INI_SCAN_DIR`, `LD_*` and similar variables the caller had. Any other program needs `--any-command` and your passphrase typed at your terminal, after the exact command is shown with any control characters written out visibly. |
| The agent making or swapping the store | Caught at your next `set` or `rotate` at a terminal (above). |
| A long TTL | Weakens the lost-files protection while the key stays cached: a copy of `vault.enc` taken together with the cached key opens it. `unlock` warns above one day. |
| Memory of a running Pikkumies process | Best effort only. Values and keys are wiped after use where PHP allows, but PHP keeps copies it does not let code clear (shared strings, the decoded list of secrets), so the same account or root reading process memory can still find them. |
| A weak passphrase | The interactive Argon2id preset (64 MiB, 2 passes) slows guessing from a copied `vault.enc` but cannot save a short or common passphrase. Use 12 or more characters. |

## Recovery

- **If you forget the passphrase**, there is no way back in, by design. Move `vault.enc` away, run `unlock` to make a new store and enter the secrets again (change them at their source if you are unsure who has them).
- **Backups:** `vault.enc` in a backup stays encrypted; restoring it needs the passphrase it had then. `vault.enc.prev` is the store before the last `rotate` or `remove`, encrypted the same way; to undo one, put it back as `vault.enc`. Delete it yourself when a removed secret must be gone at once.
- **A changed or damaged file:** `unlock` and every read refuse it; restore `vault.enc.prev` or a backup copy.

## Files

- `~/pikkumies/local/secrets/vault.enc` (0600 in a 0700 directory): `{version, kdf: {alg: argon2id13, ops, mem, salt}, created: {at, tty}, nonce, ct}`, with `ct` from libsodium `crypto_secretbox` (XSalsa20-Poly1305) under a key from `crypto_pwhash` at the interactive limits (64 MiB, 2 passes). The `kdf` and `created` blocks are repeated inside the ciphertext and must match. Every write uses a new random nonce and is atomic.
- The unlocked key: `$XDG_RUNTIME_DIR/pikkumies/` (or `/run/user/<uid>/pikkumies/` when the variable is unset, so the agent finds a key you unlocked over SSH), used only when that directory is yours and closed to others; else `/dev/shm/pikkumies-<uid>/`, made 0700 and used only after a check that it is a real directory (not a symlink), yours, mode 0700. Anything else there is refused, since `/dev/shm` is shared by every account. The key file is 0600, written to a new name and renamed into place. Both places are memory-backed and cleared at restart.
- Tools read a secret with `Secrets::get()` in `pikkumies/lib/Secrets.php`; a services entry names one as `secret:<handle>`.
- A secret may carry a pin (`set` or `rotate` with `--host H`, or `--host H/path/` for a host and path prefix), stored with its value inside `ct`; `Secrets::pin()` reads it. A tool that sends the secret over the network (services `remote` to a box without Pikkumies) refuses any host, or path outside the prefix, the pin does not allow, so an edited services entry cannot redirect it. A host-only pin leaves other paths on that host open; on a shared front with per-user paths, pin the prefix. The pin changes only together with a new value; it scopes Pikkumies' own tools and is not a wall against same-account code while the store is unlocked (the threat table above) ([ADR-0105](../../../../docs/adr/0105-third-party-seedbox-stats-through-the-clients-own-rpc.md)).
