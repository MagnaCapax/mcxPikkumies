# catalog

Pulsed Media's plans from the public feed, `https://pulsedmedia.com/data/v1/products.json`, fetched once per run. No key, no account, no cache. The search words stay on this machine.

| verb | arguments | changes | what it does |
|---|---|---|---|
| find | `<words> [--human]` | no | Plans with all the words in the name or description, the exact name first: `id`, `name`, `billing_cycles`, `available`, `stock` when the store counts it, `ram_mib`, `disk_gib`, `traffic_gib_month` or else `description`, and `order_url`. |
| list | `[--available] [--human]` | no | Every plan in short: `id`, `name`, `monthly`, `available`. `--available` keeps those on sale. |

The status line carries `generated_at`, `age_minutes` and `prices_exclude_vat`.

```sh
php ~/pikkumies/tools/seedbox/catalog/catalog.php find M10G M
php ~/pikkumies/tools/seedbox/catalog/catalog.php list --available --limit 50
```
