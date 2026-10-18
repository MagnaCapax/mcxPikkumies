---
tags: source:rtorrent-wiki, docs, app
family: generic
first_call: tools/memory/pikkumies/pikkumies\.php.* search
contains: directory.watch.added
source: TORRENT Watch directories · rakshasa/rtorrent Wiki · GitHub — https://github.com/rakshasa/rtorrent/wiki/TORRENT-Watch-directories
note: directory.watch.added is the inotify-based rtorrent command named only on this wiki page; the schedule2 polling loop is the older fallback, not this answer.
---
My rtorrent only picks up new torrents dropped in my watch folder every 10 seconds or so, on a schedule. Is there a way to make it load the file the instant it appears, instead of waiting for the next scheduled scan?
