---
name: secrets
description: "Encrypted store for passwords and API keys that cannot be an SSH key: status, use a secret by its handle without seeing it, list handles, set, rotate, remove; only the user unlocks it, in their own terminal."
---

# Secrets

Prefer an SSH key: set one up wherever a login can be a key. This store is for the passwords and API keys that cannot.

- **You never see, type or ask for a value or the passphrase.** Never ask the user to paste either into the chat, never put a value on a command line (`set` reads it from the terminal or stdin), never read `local/secrets/vault.enc` or the unlocked key file. If the user pastes one anyway, do not pipe it into `set` yourself (your command line is logged); tell them to change it, since it is now in the conversation, and to `set` the new one in their own terminal.
- **Locked** (an error starting `locked:`): the passphrase is never stored and never given to you, so you cannot unlock. Tell the user to run `php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php unlock` in their own terminal (8 hours by default, `--ttl 30m` to `--ttl 30d`), then try again. Never try `unlock` or `passphrase-change` yourself: they wait for a passphrase at a terminal that you do not have.
- **A new secret**: give the user the exact line to run in their own terminal, `php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php set <handle> --kind password|apikey|token`; it asks for the vault passphrase and then the value, neither shown. A value a command makes (a generated key) may be piped in from a plain (non-pty) terminal call: `openssl rand -hex 32 | php .../secrets.php set <handle>`; in a terminal you opened with a pty it would wait for a passphrase you do not have. If it refuses because the cached key differs or the passphrase does not open the store, tell the user exactly that and to check `status`; the store may have been replaced.
- **Using a secret**: `use <handle> --env NAME -- php ~/pikkumies/pikkumies/tools/<category>/<tool>/<tool>.php <verb> ...` runs that Pikkumies tool with the value in `$NAME`. You see the handle and the tool's output with the value (and its base64, hex, URL and JSON forms) replaced by `[REDACTED]`. Another program needs `--any-command` and the user typing the vault passphrase at their own terminal; do not offer it as a way round a refusal. A services entry names a secret as `secret:<handle>`; tools read it themselves.
- `list` shows handles, kinds and dates, never values. `status` shows whether the store is locked and until when.
- `rotate <handle>` replaces a value; the previous store stays as `vault.enc.prev`. `remove <handle> --with-data` deletes one: only when the user asked for that secret to go.
- A forgotten passphrase cannot be recovered: the secrets are entered again into a new store. Say so plainly; never suggest a way round the encryption.
