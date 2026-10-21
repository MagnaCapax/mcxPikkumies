# secrets

The encrypted store for passwords and API keys ([ADR-0085](../../../../docs/adr/0085-encrypted-secret-store.md)). File `~/pikkumies/local/secrets/vault.enc`; the unlocked key waits in `$XDG_RUNTIME_DIR/pikkumies/` (or `/run/user/<uid>/pikkumies/`), else `/dev/shm/pikkumies-<uid>/`, never under the home.

| verb | arguments | changes | what it does |
|---|---|---|---|
| status | | no | `sodium`, `vault`, `vault_exists`, `prev_exists`, `locked`, `expires_at`, `created_at` and `created_on` (the terminal the store was made at), `kdf` (`alg`, `ops`, `mem_mib`); while unlocked also `expires_in_s`, `cache`, `handles` (how many) and `fingerprint` (a short keyed hash of the key: a different one means a different passphrase or store). Never a value. |
| unlock | `[--ttl 8h]` | yes | Terminal only: asks for the vault passphrase on `/dev/tty` (the first time: a new one, twice, at least 10 characters with a note below 12, and creates the store, recording when and on which terminal), derives the key with Argon2id13, checks that it opens the store, and caches the key (never the passphrase) for the TTL: `60s` to `30d`, default `8h`; over a day the record and stderr carry a `warning`. Refuses without a terminal. Prints the key `fingerprint`. `verified` when the cached key reads back. |
| lock | | yes | Wipes and removes the cached key now. `removed` (how many), `verified` when no key is left. |
| list | | no | One record per secret: `handle`, `kind`, `added_at`, `rotated_at` (UTC), `host` and `path` (its pin, or null). Never a value. Needs the store unlocked. |
| set | `<handle> [--kind password\|apikey\|token] [--host H[/path/]]` | yes | Adds a secret: the value is typed at the terminal (not shown) or read from stdin (one trailing newline dropped), at most 8192 bytes, never an argument. Refuses a handle that exists (use `rotate`). With a terminal it first asks for the vault passphrase on `/dev/tty`, whose key must open the store and equal any cached key (so it also works while locked); without one it uses the cached key. `passphrase_checked` says which. `--host H` pins it to the one host a tool may send it to (any path there, the weaker form); `--host H/path/` to that host and URLs under that path prefix only, on a `/` boundary (the form for a shared front with per-user paths). The host is normalised (lower case, no trailing dot), the path must be plain (no %-encoding, no empty, `.` or `..` part); a bad pin refuses before the value is read. It is stored with the value inside the store; `services remote` refuses to send a pinned secret anywhere it does not allow, and an unpinned one at all ([ADR-0105](../../../../docs/adr/0105-third-party-seedbox-stats-through-the-clients-own-rpc.md)). `verified` when the store reopens with the value. |
| rotate | `<handle> [--host H[/path/]]` | yes | A new value for an existing handle, read and confirmed the same way as `set`; the store before the change is kept as `vault.enc.prev` (still encrypted). `--host` sets a new pin (host and path together) with the new value; without it the old pin stays. No other verb changes a pin. |
| remove | `<handle> --with-data` | deletes | Deletes the secret (confirmed like `set`); without `--with-data` nothing is removed. The store before the change stays as `vault.enc.prev` until the next rotate or remove. |
| passphrase-change | | yes | Terminal (`/dev/tty`) only: the current passphrase, then a new one twice; the store and `vault.enc.prev` are encrypted again under a new salt, and the cached key is replaced (8 h). |
| use | `<handle> --env NAME [--any-command] -- <command...>` | yes | Runs the command with the value in `$NAME`, set only for the child, and with `PHPRC`, `PHP_INI_SCAN_DIR`, `LD_*`, `DYLD_*`, `BASH_ENV`, `NODE_OPTIONS` and the like removed from its environment; returns one record: `exit_code`, `stdout` (last 8000 bytes), `stderr` (last 3000), `stdout_cut`, `stderr_cut`, with every exact occurrence of the value and of its base64, base64url, hex, URL- and JSON-encoded forms replaced by `[REDACTED]`, before and after control and zero-width characters are cleaned out, and before any cut. Without `--any-command` the command must be `php <script>` whose real path is a `.php` file under this install's `tools/`; with it, any program, after the exact command is shown on the terminal (control characters written as visible escapes) and the user types the vault passphrase there (refused without a terminal). `NAME` is upper-case letters, digits and `_`, never `PATH`, `HOME`, `LD_*`, `PHPRC` or the like. |

```sh
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php status
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php unlock            # the user, in their own terminal
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php unlock --ttl 30m
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php set nas-login --kind password
openssl rand -hex 32 | php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php set webhook-token --kind token
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php list
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php use nas-login --env PASS -- php ~/pikkumies/pikkumies/tools/<category>/<tool>/<tool>.php <verb>
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php rotate nas-login
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php remove nas-login --with-data
php ~/pikkumies/pikkumies/tools/system/secrets/secrets.php lock
```

- Every verb but `status` and `lock` needs the PHP sodium extension and says so when it is missing.
- Every run first wipes expired key files in the cache directories, for any store.
- Other tools read a pin with `Secrets::pin('<handle>')` (`{host, path}`, null when unpinned) and check a request with `Secrets::pinAllows()` before sending the value.
- Other tools read a value with `Secrets::get('<handle>')` or `Secrets::get('secret:<handle>')` (`pikkumies/lib/Secrets.php`); locked, it throws a refusal that starts with "locked: run \`secrets unlock\` in your own terminal", with the exact command as the hint.
