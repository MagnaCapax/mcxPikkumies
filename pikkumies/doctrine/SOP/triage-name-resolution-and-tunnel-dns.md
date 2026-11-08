# SOP: This machine cannot reach a site the others reach

Load when: one host cannot load sites that others on the same network reach, lookups fail, or a connection times out while the network "looks fine".

## 1. Separate the two failures

An uncached name lookup first, then a connection to the numeric address. Lookup fails and the connection works: the resolver. Both fail: the route.

## 2. Read what chooses where a query goes

Name the mechanism this machine uses to pick a resolver, and which link it names as the one that answers. The link that owns the default-route name domain answers every query — including the ones the user expects the local network to answer.

## 3. Look for a profile that claims the whole name space

A tunnel profile carrying a "the whole name space" search domain, or its equivalent, while its routed address range is narrow. Queries then go to a name server the tunnel cannot reach, and every uncached lookup fails while cached names keep working — which is what makes it look like a website problem.

## 4. Compare the name server's address against the routed range

Outside the range means the profile is misconfigured, not the resolver.

## 5. Check your own path before changing anything

If this session runs through the profile you are about to edit, the fix cuts your own access first. Say what reconnects the machine before you change it.

## 6. Fix at the profile, not at the resolver

Remove the name servers from the tunnel profile, or add the server's address to what the tunnel routes. Then re-read the resolver state.

## 7. Verify

An uncached lookup plus one request to the address that failed. Not a ping: the name is what was broken.

## 8. Prevention

The rule belongs in the mistakes list as `[copied-advice-unchecked]`: before letting a tunnel profile connect by itself, or importing one from another machine, read its name-server, search-domain and routed-range settings.
