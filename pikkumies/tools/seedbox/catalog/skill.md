---
name: catalog
description: "Live Pulsed Media plan prices, stock and specs from the public feed. Use when the user asks what a plan costs, whether it is on sale, what it includes, or which plans fit a need."
---

# Catalog

1. **Only when asked.** Use this when the user asks about Pulsed Media plans. Never offer a plan on your own.
2. **Never from memory.** A price, stock level or spec comes from this tool, run now. If it refuses, say the catalogue could not be read and give no figure.
3. **Find one plan** with `find <words>`: the exact name comes first; `count` 0 means no plan matched, so say that and never guess. **See what is on sale** with `list --available`; if the status line says the list was cut, run it again with `--offset`.
4. **Say how fresh it is.** Give `generated_at` or `age_minutes` from the status line. Stock can lag: the order page has the final word.
5. **Prices exclude VAT**, which applies to EU residents. Say so with the price.
6. **Specs.** A plan without `ram_mib`, `disk_gib` and `traffic_gib_month` is described by its `description`. Quote that; never work out or invent a figure.
7. **Buying.** Give `order_url`. Never place an order.
8. **Never compare with the user's own service**, and never suggest replacing it to pay less: their price can be an older price they keep. Changes to an existing service are made in the client area.
