# mcxPikkumies

A lightweight sysadmin helper agent that lives on the server it looks after. Its first home is the PMSS seedbox platform, but it is built general enough to run on other systems with your own API and keys.

**Status:** design stage. No code yet.

## What it is meant to be

- **It runs as you.** By default it works under the user's own account, inside that user's slice of the server. Root access is one more capability layer on top of that. When the agent detects sudo or root deterministically, it can do full server administration. When it doesn't, it manages only that slice. A config option turns the root layer off permanently.
- **It knows where to look.** Configurable knowledge sources (wikis, knowledge bases and similar), basic memory, and a small set of basic doctrines: verify before claiming, cite the source, ask before anything destructive.
- **It grows by packs.** Skills and tools come as packs: downloadable, or configured locally. On install, an agent-mode routine reads the system (metrics, users, running software) and picks the packs that fit.
- **Your model, your keys.** It talks to any OpenAI-compatible endpoint. A deployment chooses its own provider and keys.
- **It runs anywhere.** On the server itself, locally on your own machine, or in a remote mode that manages a seedbox over the network, PMSS or any other.
- **It is not only for sysadmin work.** General investigation and research are in scope too.

## The name

*Pikkumies*, the little man from the sea, comes from Runo II of the Kalevala. A great oak has grown until it "päivän peitti paistamasta" (hid the sun from shining), and nobody can fell it. Väinämöinen calls for someone of the water folk to fell it, and a man no taller than a thumb rises from the sea: "Olen mie mokoma miesi, uros pieni, veen väkeä. Tulin tammen taittamahan" (I am such a man, a little hero of the water folk; I came to fell the oak). Väinämöinen doubts him. The little man then grows into a giant and fells the tree in three strokes.

That is the job description: small by default, sent for when there is work, and as big as the task needs.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

"OpenAI-compatible" describes an API format. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
