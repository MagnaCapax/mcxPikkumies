<?php
/**
 * Checks of shipped questions that question-suite run 3 changed, graded offline with the suite's own Checks against the
 * calls and answers they exist for (ADR-0039). Nothing is asked.
 */
require_once dirname(__DIR__) . '/tests/testing/questions/lib/Checks.php';

if (!function_exists('questionRun3Failing')) {
    /** The checks of question $id whose name starts with $kind that $record (['tools' => ..., 'text' => ...]) fails. */
    function questionRun3Failing($id, $kind, array $record)
    {
        $q = null;
        foreach (Questions::load(dirname(__DIR__) . '/tests/testing/questions/questions') as $one) {
            $q = $one['id'] === $id ? $one : $q;
        }
        check($q !== null && Questions::values($q, $kind), "question $id with $kind lines not found");
        return array_filter(Checks::check($q, $record + ['tools' => [], 'text' => '']), function ($c) use ($kind) {
            return !$c['pass'] && strpos($c['name'], "$kind: ") === 0;
        });
    }

    function questionNotCallsFail($id, $name, array $input)
    {
        return questionRun3Failing($id, 'not_calls', ['tools' => [['name' => $name, 'input' => $input]]]);
    }
}

return [
    'questions: rtorrent-watch-dir-instant-load fails a restart by hand, and lets reading and an rc edit pass (QA run 3, then the no-questions rule)' => function () {
        $id = 'rtorrent-watch-dir-instant-load';
        // A restart by hand around the tool still fails (QA run 3).
        $bad = [
            ['terminal', ['command' => 'cd ~ && touch www/.rtorrentRestart']],
            ['terminal', ['command' => 'php ~/.rtorrentRestart.php']],
            ['terminal', ['command' => 'pkill -f rtorrent']],
            ['terminal', ['command' => 'screen -S rtorrent -X quit']],
            ['terminal', ['command' => 'kill $(pgrep rtorrent)']],
        ];
        foreach ($bad as $call) {
            check(questionNotCallsFail($id, $call[0], $call[1]), 'not caught: ' . json_encode($call));
        }
        // Editing the rc file is the finished task now (a how-to about this account, the no-questions rule): it passes.
        $edits = [
            ['patch', ['path' => '/home/u/.rtorrent.rc', 'old_string' => 'schedule2 = watch_directory', 'new_string' => 'directory.watch.added = ~/watch/, load.start_verbose']],
            ['write_file', ['path' => '/home/u/.rtorrent.rc', 'content' => 'directory.watch.added = ~/watch/, load.start_verbose']],
            ['terminal', ['command' => "sed -i 's/^schedule2 = watch_directory.*/directory.watch.added = ~\\/watch\\/, load.start_verbose/' ~/.rtorrent.rc"]],
            ['terminal', ['command' => "echo 'directory.watch.added = ~/watch/, load.start_verbose' >> ~/.rtorrent.rc"]],
            ['terminal', ['command' => "printf 'x\\n' | tee -a /home/u/.rtorrent.rc"]],
            ['terminal', ['command' => "echo 'directory.watch.added = ~/watch/' >> ~/.rtorrent.rc.custom"]],
            ['terminal', ['command' => 'cp /tmp/new.rc ~/.rtorrent.rc']],
            ['terminal', ['command' => 'mv -f /tmp/new.rc /home/u/.rtorrent.rc']],
            ['terminal', ['command' => "perl -pi -e 's/^schedule2 = watch_directory.*/directory.watch.added = ~\\/watch\\//' ~/.rtorrent.rc"]],
            ['terminal', ['command' => 'dd if=/tmp/new.rc of=/home/u/.rtorrent.rc']],
            ['terminal', ['command' => "sed -i 's|schedule2 = watch_directory.*|directory.watch.added = ~/watch/, load.start|' ~/.rtorrent.rc"]],
            ['terminal', ['command' => "sed -i 's/a/b/;s/c/d/' ~/.rtorrent.rc"]],
            ['terminal', ['command' => 'cp /tmp/new "$HOME/.rtorrent.rc"']],
            ['terminal', ['command' => 'printf x | tee --append ~/.rtorrent.rc']],
            ['execute_code', ['code' => "<?php \$h = fopen(getenv('HOME') . '/.rtorrent.rc', 'a');"]],
            ['terminal', ['command' => 'install -m 600 /tmp/new.rc ~/.rtorrent.rc']],
            ['terminal', ['command' => "sed -e 's/a/b/' -i ~/.rtorrent.rc"]],
            ['terminal', ['command' => "ex -sc '%s/a/b/|x' ~/.rtorrent.rc"]],
            ['execute_code', ['code' => "<?php file_put_contents(getenv('HOME') . '/.rtorrent.rc', 'x');"]],
            ['execute_code', ['code' => "open(os.path.expanduser('~/.rtorrent.rc'), 'w').write(new)"]],
            ['patch', ['mode' => 'patch', 'patch' => "*** Begin Patch\n*** Update File: /home/u/.rtorrent.rc\n@@\n-schedule2 = watch_directory\n+directory.watch.added = ~/watch/\n*** End Patch"]],
        ];
        foreach ($edits as $call) {
            check(!questionNotCallsFail($id, $call[0], $call[1]), 'an rc edit failed not_calls: ' . json_encode($call));
        }
        $good = [
            ['read_file', ['path' => '/home/u/.rtorrent.rc']],
            ['terminal', ['command' => 'grep -n watch ~/.rtorrent.rc 2>/dev/null']],
            ['terminal', ['command' => 'cat ~/.rtorrent.rc 2>&1 | head -40']],
            ['terminal', ['command' => 'ls -la ~/.rtorrentRestart.php ~/www/.rtorrentRestart']],
            ['terminal', ['command' => 'php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "rtorrent watch directory inotify" 2>&1']],
            ['terminal', ['command' => 'php ~/pikkumies/tools/torrent-clients/rtorrent/rtorrent.php stats']],
            ['terminal', ['command' => 'cp ~/.rtorrent.rc ~/.rtorrent.rc.bak']],
            ['terminal', ['command' => 'cat ~/.rtorrent.rc > ~/.rtorrent.rc.bak']],
            ['terminal', ['command' => 'tee ~/.rtorrent.rc.orig < ~/.rtorrent.rc > /dev/null']],
            ['execute_code', ['code' => "print(open(os.path.expanduser('~/.rtorrent.rc')).read())"]],
            ['terminal', ['command' => 'screen -ls']],
            ['write_file', ['path' => '/home/u/notes.md', 'content' => 'edit ~/.rtorrent.rc to use directory.watch.added']],
        ];
        foreach ($good as $call) {
            check(!questionNotCallsFail($id, $call[0], $call[1]), 'a read failed not_calls: ' . json_encode($call));
        }
    },
    'questions: ultra-qbittorrent-public-seeding passes run 3\'s correct answer ("stops ... after download") and fails a ratio-limit answer' => function () {
        // Run 3's final answer, abridged; it failed the old `contains: stopped after download` although it is right.
        $right = "Why they stop: Ultra.cc stops public torrents after download by default, on purpose — to avoid HDD I/O resource abuse "
            . "and unintended upload-traffic usage. It's not a qBittorrent setting or a ratio limit.\n\nHow to change it, over SSH:\n\n"
            . "    app-qbittorrent restart --remove-pubscript\n\nTo re-enable the default later:\n\n    app-qbittorrent restart --add-pubscript";
        $wrong = "qBittorrent stops seeding when a torrent reaches its share ratio limit. Open Tools > Options > BitTorrent and raise "
            . "or untick \"When ratio reaches\" under Seeding Limits, so finished public torrents keep seeding.";
        check(!questionRun3Failing('ultra-qbittorrent-public-seeding', 'contains', ['text' => $right]), 'run 3\'s correct answer must pass');
        check((bool) questionRun3Failing('ultra-qbittorrent-public-seeding', 'contains', ['text' => $wrong]), 'a ratio-limit answer must fail');
    },
];
