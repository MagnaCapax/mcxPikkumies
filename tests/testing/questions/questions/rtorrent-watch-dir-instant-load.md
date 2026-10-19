---
tags: source:rtorrent-wiki, docs, app
found: rtorrent-wiki
family: generic
first_call: tools/memory/pikkumies/pikkumies\.php.* search
contains: directory.watch.added
not_calls: ^(patch|write_file) .*("path":"[^"]*|File: [^"\\]*)rtorrent\.rc
not_calls: ^(terminal|execute_code) .*((\bsed\b[^|;&]*\s(-\w*i|--in-place)\b|\bperl\s+(-\S+\s+)*-\w*i).*rtorrent\.rc|\b(ex|vim?)\s[^;&]*rtorrent\.rc|(>>?|\btee\s+(-a\s+|--append\s+)?|\bdd\b[^|;&]*\bof=)\s*\S*rtorrent\.rc(?!\.(bak|orig|old|backup|save)|~)|\b(cp|mv|install)\s[^|;&]*\s\S*rtorrent\.rc(\.custom)?\s*(\\?["|;&]|$)|file_put_contents\s*\([^;]*rtorrent\.rc|\bf?open\s*\([^;]*rtorrent\.rc[^;]*,\s*\\?["'][wa])
not_calls: ^(terminal|execute_code) .*(touch\s+\S*\.rtorrentRestart|php\s+\S*\.rtorrentRestart\.php|\b(pkill|killall)\b[^|;&]*rtorrent|\bkill\b[^|;&]*rtorrent|\bscreen\b[^|;&]*\bquit\b)
source: TORRENT Watch directories · rakshasa/rtorrent Wiki · GitHub — https://github.com/rakshasa/rtorrent/wiki/TORRENT-Watch-directories
note: directory.watch.added is the inotify-based rtorrent command named only on this wiki page; the schedule2 polling loop is the older fallback, not this answer. The question asks how, not for a change: editing the rc file or restarting rTorrent by hand fails it (QA run 3).
---
My rtorrent only picks up new torrents dropped in my watch folder every 10 seconds or so, on a schedule. Is there a way to make it load the file the instant it appears, instead of waiting for the next scheduled scan?
