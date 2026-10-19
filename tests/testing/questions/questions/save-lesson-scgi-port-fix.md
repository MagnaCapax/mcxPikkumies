---
tags: memory, save
calls: tools/memory/pikkumies/pikkumies\.php.* save.*--sources
judge: The save includes a related link or says --no-related and why, and does not claim the fix is saved before the save call actually ran.
---
That worked — rutorrent was broken because scgi_port in config.php didn't match rtorrent's actual port. Remember that for next time.
