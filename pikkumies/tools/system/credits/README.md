# credits

How many AI credits this account has left. The agent runs it when someone asks about credits, tokens left or the balance, so it never has to look at the key itself.

It asks the AI endpoint's `GET /balance` route with the account's own key and prints the balance, what running requests hold, the key's limits and the last 60 seconds of use. The key comes from `~/.virsilipasKey` (the Pulsed Media AI key, also shown in the Pulsed Media client area on the page "Virsilipas AI key" under "AI credits") or from `~/.pikkumiesKey` for your own provider. The key is never printed. A provider without that route gets a clear error instead of a number.
