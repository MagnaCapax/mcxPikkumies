# Ask Primary locally

When the user asks another local program to put a question into the ongoing Primary conversation, pipe the question into `pikkumies ask` as the account owner. Read its exit code. Retry status 6 after Primary becomes idle; do not retry a status 3 approval prompt automatically.

See [the local integration guide](README.md) for the command and exit codes.
