# ADR-0085: An encrypted secret store for passwords and API keys; only the user unlocks it

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Accepted
- Amended by [ADR-0107](0107-third-party-seedbox-stats-through-the-clients-own-rpc.md): `set` and `rotate` take `--host <host>`, a pin stored with the value; `Secrets::host()` returns it, and a tool that sends the secret over the network refuses any other host.

## Context
The request of 2026-10-20, verbatim: "prefer SSH keys, and for passwords where needed or API Keys we need a proper encrypted store ... not easily decrypted if files lost etc. but is not inconvenient neither".

Until now a secret Pikkumies keeps is a plaintext file behind Unix permissions: `lib/SecretFile.php` and the backup store write `local/secrets/<name>` (directory 0700, file 0600) and check the owner and that it is not a symlink on every read ([ADR-0019](0019-hosts-and-credentials.md), [ADR-0049](0049-backup-remote-over-ssh.md)). That keeps other accounts out, but a lost disk image, a leaked backup or a careless copy of the home carries every secret in the clear, which is the property the request names. The services list being added alongside this store needs a place for the logins that cannot be an SSH key, so the gap would grow.

Facts this rests on:
- PHP's sodium extension is already used (`/feedback` seals with `sodium_crypto_box_seal`, [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md)); `sodium_crypto_pwhash` (Argon2id13) and `sodium_crypto_secretbox` (XSalsa20-Poly1305) are in PHP 7.2+.
- Every surveyed store (pass, age/sops, KeePassXC, Bitwarden CLI, Vault, systemd-creds, restic/borg password files) protects files at rest and none protects a secret from root or from a process that may use it while it is unlocked; systemd-creds' TPM mode needs privileges a shared account does not have.
- A terminal is no proof that a person is there: the agent can open one (Hermes `pty=true`, [ADR-0053](0053-email-category.md)).

## Options Considered
- **Keep plaintext files behind permissions.** No work, and exactly what the request says is not enough.
- **pass/age/KeePassXC/Bitwarden/Vault.** New runtime dependencies (GnuPG, a Go or Node.js program, a server), and the same use-time exposure; Vault is a service of its own for one account.
- **Key held off the machine (a forwarded ssh-agent, a remote service).** The only design that also resists root on the server, but it works only while the user's machine is connected and adds a party; a later step if unattended use against root is ever needed.
- **One file encrypted with libsodium, a key derived from a passphrase with Argon2id, the derived key cached in memory-backed storage for a TTL, and tools that use a secret by handle without printing it.** No new dependency, an unlock once per working day, ciphertext only at rest.

## Decision
- Unit `tools/system/secrets/` (verbs status, unlock, lock, list, set, rotate, remove, passphrase-change, use) and `lib/Secrets.php` + `lib/SecretsCache.php`. Other tools read a value with `Secrets::get($handle)`; a services entry names it as `secret:<handle>`. Locked, `get` throws `locked: run \`secrets unlock\` in your own terminal` with the exact command.
- `local/secrets/vault.enc`: `{version: 1, kdf: {alg: argon2id13, ops, mem, salt}, nonce, ct}`; `ct` is `crypto_secretbox` of `{kdf, secrets: {handle: {value, kind, added_at, rotated_at}}}` under a key from `crypto_pwhash` at `OPSLIMIT_INTERACTIVE`/`MEMLIMIT_INTERACTIVE` (64 MiB, so it fits a shared account's memory). The header's KDF block is repeated inside the ciphertext and must match, so a changed header is refused like a changed ciphertext. A store with KDF limits outside 1-16 passes and 8 MiB-1 GiB is refused before any work. Every write: new random nonce, atomic temp file and rename, 0600 in 0700. `rotate` and `remove` keep the store before the change as `vault.enc.prev`; `passphrase-change` re-encrypts it too.
- The passphrase is never stored. It is read only from the controlling terminal, `/dev/tty` (`unlock`, `passphrase-change`, and `set`/`rotate`/`remove` when a terminal exists, see the addendum), at least 10 characters, with a note below 12. So the agent cannot unlock: skill.md and the README say so, and the agent tells the user to run `unlock` in their own terminal. A forgotten passphrase means entering the secrets again.
- The unlocked key (never the passphrase) is cached for a TTL, default 8 h, `--ttl` 60 s to 30 d, with a printed warning above one day. Where: `$XDG_RUNTIME_DIR/pikkumies/`, or `/run/user/<uid>/pikkumies/` when the variable is unset, only when that base is a directory this uid owns closed to group and others; else `/dev/shm/pikkumies-<uid>/`, created 0700 and used only after lstat proves it is a real directory (not a symlink) of this uid with mode 0700, otherwise refused. Never under the home, so no backup or copy of the home holds it. The key file is written to a fresh name opened with `fopen('x')` (O_EXCL), 0600, then renamed; a read trusts only a regular 0600 file of this uid; an expired one is wiped by the next read or the next secrets command (the addendum). `lock` wipes it.
- Values come from the terminal (not echoed) or stdin, never argv; `list` and `status` never print a value.
- `use <handle> --env NAME -- <command...>`: by default only a Pikkumies tool (`php <script>` whose realpath is a `.php` file under this install's `tools/`, run with the running PHP). Any other command needs `--any-command` and the vault passphrase typed at the terminal; without a terminal it is refused. A typed "yes" was rejected as the confirmation, because an agent that opened a terminal could type it; the passphrase it does not have. `NAME` cannot be `PATH`, `HOME`, `LD_*`, `PHPRC` and similar. The output reaches the agent with every exact occurrence of the value and of its base64, base64url, hex, URL- and JSON-encoded forms replaced by `[REDACTED]`.
- No sodium (`extension_loaded`), no store: every verb that needs it refuses with the package to install.

## Consequences
- A lost or copied `vault.enc`, or a backup holding it, is ciphertext; guessing costs 64 MiB and two Argon2id passes per try.
- The user unlocks once per working day (or per TTL) in a terminal; the agent works with handles in between, and unattended jobs work only while the store is unlocked.
- Honest boundary: root on the server can read the cached key or a running tool's memory. While unlocked, a process running as the user, including a prompt-injected agent, can read the cached key file and open the store; command scoping and redaction narrow what the agent's own tool calls see, they do not close that. Redaction catches exact forms of the value alone, not the value inside a longer encoded string (a base64 `user:password` pair) or split across output.
- `vault.enc.prev` keeps a removed secret until the next rotate or remove; the user deletes it when a secret must be gone at once.
- Follow-ups: tools that keep plaintext secret files (`SecretFile` users, the backup store) can move to the store; a `pikkumies secrets` launcher shortcut would shorten the unlock command.

## Addendum 2026-10-20: security review fixes
- Context: a review of this unit before release (findings F1-F11) found one medium gap: `set` and `rotate` trusted whichever cached key matched the store's salt, so the agent, running as the same account, could create the store first in a terminal it opened, or swap `vault.enc` and the cached key, and the user's next secret would land under a key the agent knows, readable even after `lock`.
- F1: at a terminal, `set`, `rotate` and `remove` ask for the passphrase on `/dev/tty`; its key must open the store and equal any cached key, else nothing is stored (they also work while locked this way). Without a terminal they use the cached key and report `passphrase_checked: false`. A new store needs the new passphrase twice on `/dev/tty`. The store records `created: {at, tty}` in its header and, authenticated, inside the ciphertext; `status` shows it, and `unlock` and `status` show a key `fingerprint` (a keyed BLAKE2b hash of the key, 8 bytes).
- F2: the command shown before the `--any-command` passphrase prompt goes to `/dev/tty` with control, C1, zero-width and bidi characters written as visible escapes.
- F3: every secrets command first wipes expired key files of any store; the README says the key stays until it has expired and the next secrets command after that removes it. No background reaper was added.
- F5: `use` redacts before and after the cleaning Cli applies (also the cleaned form of the value), stdout line by line before it is cut to its tail; stderr cut at ToolProc's limit loses its last bytes, so half a value never shows.
- F6: a `use` child gets the value only in its own environment and loses `PHPRC`, `PHP_INI_SCAN_DIR`, `LD_*`, `DYLD_*`, `BASH_ENV`, `NODE_OPTIONS` and similar variables. The tools allow-list is cooperative, since `tools/` lives in the user's home; the README says so.
- F10, F11: wiping memory is best effort in PHP, and the interactive Argon2id preset cannot save a weak passphrase; both are listed in the README's limits, with a 12+ character suggestion and no new hard rule.
- Not changed: F4 (a co-tenant can pre-create `/dev/shm/pikkumies-<uid>` and block the fallback; it fails closed, and the runtime directory is tried first).

## References
- [ADR-0019](0019-hosts-and-credentials.md) (credentials are never printed; secrets under `local/secrets/`), [ADR-0049](0049-backup-remote-over-ssh.md) (scoped SSH keys first; one-time logins), [ADR-0053](0053-email-category.md) (a terminal is no proof of a person), [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md) (sodium already used), [ADR-0020](0020-deletes-need-the-users-approval.md) (`remove --with-data` goes through the delete guard).
- libsodium documentation: password hashing (https://doc.libsodium.org/password_hashing/default_phf), secret-key authenticated encryption (https://doc.libsodium.org/secret-key_cryptography/secretbox); PHP manual `sodium_crypto_pwhash`, `sodium_crypto_secretbox`.
- XDG Base Directory Specification, `$XDG_RUNTIME_DIR` (https://specifications.freedesktop.org/basedir-spec/latest/).
