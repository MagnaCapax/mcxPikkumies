<?php
/**
 * ADR-0076: the user's local notes for a tool or a tool category (local/tools/<path>/DOCTRINE.md). A tool's notes end its
 * generated SKILL.md, a category's follow the shipped tool doctrine in SOUL.md; orphans, symlinks, oversize files and an
 * open local/ are skipped and named; tools.disabled hides them; a note written after the last start by the user waits for
 * the next one; when SOUL.md would pass its cap, local notes go first.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/pikkumies-configure/ConfigureNotes.php';

if (!function_exists('notesTree')) {
    /** A pikkumies tree with tool units [category/name, ...] and a 0700 local/; returns the pikkumies directory. */
    function notesTree(array $units)
    {
        $root = testTempDir('pk-notes') . '/pikkumies';
        foreach ($units as $path) {
            $dir = "$root/tools/$path";
            $name = basename($path);
            mkdir($dir, 0700, true);
            file_put_contents("$dir/skill.md", "---\nname: $name\ndescription: fixture $name\n---\nShipped body of $name.\n");
            file_put_contents("$dir/README.md", "# $name\n");
            file_put_contents("$dir/USAGE.md", "# $name\n");
        }
        mkdir("$root/local", 0700);
        return $root;
    }

    /** Writes local/tools/<path>/DOCTRINE.md by hand (directories 0700). */
    function notesWrite($root, $path, $text)
    {
        $dir = rtrim("$root/local/tools/$path", '/');
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/DOCTRINE.md", $text);
    }

    /** generate as a start does: returns [result, SKILL.md of $unit or null, SOUL.md]. */
    function notesStart($root, $unit, array $disabled = [], $depth = null)
    {
        return testWithEnv(['PIKKUMIES_DEPTH' => $depth], function () use ($root, $unit, $disabled) {
            $r = Generate::run($disabled, "$root/tools", "$root/local/generated/skills", "$root/tools/INDEX.md");
            $skill = "$root/local/generated/skills/$unit/SKILL.md";
            $config = Config::defaults();
            $config['tools']['disabled'] = $disabled;
            HermesConfig::$noticesDir = "$root/local/notices";
            try {
                $soul = HermesConfig::renderSoul([], $config, true, "$root/tools");
            } finally {
                HermesConfig::$noticesDir = null;
            }
            return [$r, is_file($skill) ? file_get_contents($skill) : null, $soul];
        });
    }
}

return [
    'local notes: a tool\'s notes end its SKILL.md, a category\'s follow the shipped tool doctrine in SOUL.md' => function () {
        $root = notesTree(['torrent-clients/rtorrent', 'email/imap/fetch']);
        try {
            file_put_contents("$root/tools/torrent-clients/DOCTRINE.md", "- shipped torrent rule\n");
            notesWrite($root, 'torrent-clients/rtorrent', "- 2026-10-19: Restart it only through the rtorrent tool\n");
            notesWrite($root, 'torrent-clients', "- 2026-10-19: Local torrent rule\n");
            notesWrite($root, 'email/imap', "- 2026-10-19: Local imap rule\n");
            list($r, $skill, $soul) = notesStart($root, 'torrent-clients/rtorrent');
            check($r['error'] === '' && $r['problems'] === [], json_encode($r));
            check(strpos($r['localNotice'], '3 local note files new or changed since the last start: tools/email/imap, tools/torrent-clients, tools/torrent-clients/rtorrent; they apply from this start') !== false, $r['localNotice']);
            $tail = "\n## Local notes\n\n" . LocalNotes::INTRO . "\n\n- 2026-10-19: Restart it only through the rtorrent tool\n";
            check(substr($skill, -strlen($tail)) === $tail, 'SKILL.md does not end with the note: ' . $skill);
            check(strpos($skill, 'Shipped body of rtorrent.') < strpos($skill, '## Local notes'), 'the shipped body must come first');
            $shipped = strpos($soul, "### torrent-clients\n\n- shipped torrent rule");
            $local = strpos($soul, "### Local notes\n\n" . LocalNotes::INTRO . "\n\n#### email/imap\n\n- 2026-10-19: Local imap rule\n\n#### torrent-clients\n\n- 2026-10-19: Local torrent rule\n");
            check($shipped !== false && $local !== false && $shipped < $local, 'SOUL.md: ' . substr($soul, (int) strpos($soul, '## Tool doctrine')));
            check(substr_count($soul, 'Restart it only through') === 0, 'a tool\'s note must not reach SOUL.md');
            // The record was written: the next start notes nothing new, and the output is the same.
            list($r2, $skill2, $soul2) = notesStart($root, 'torrent-clients/rtorrent');
            $doctrine = function ($soul) {
                return substr($soul, (int) strpos($soul, '## Tool doctrine'));
            };
            check($r2['localNotice'] === '' && $skill2 === $skill && $doctrine($soul2) === $doctrine($soul), 'an unchanged start changed something: ' . $r2['localNotice']);
            check(strpos($soul, 'Notice from local-changes: 3 local note files new or changed') !== false && strpos($soul2, 'Notice from local-changes') === false, 'the notice in SOUL.md');
        } finally {
            testRmTree(dirname($root));
        }
    },
    'local notes: an orphan, a symlink, an odd name and an oversize file are skipped and named, never shown' => function () {
        $root = notesTree(['torrent-clients/rtorrent']);
        $elsewhere = dirname($root) . '/elsewhere';
        try {
            notesWrite($root, 'no-such-category', "- orphan rule\n");
            notesWrite($root, '', "- a rule for everything\n");
            mkdir($elsewhere, 0700);
            file_put_contents("$elsewhere/DOCTRINE.md", "- linked rule\n");
            mkdir("$root/local/tools/torrent-clients", 0700, true);
            symlink($elsewhere, "$root/local/tools/torrent-clients/rtorrent");
            symlink("$elsewhere/DOCTRINE.md", "$root/local/tools/torrent-clients/DOCTRINE.md");
            mkdir("$root/local/tools/bad name", 0700);
            list($r, $skill, $soul) = notesStart($root, 'torrent-clients/rtorrent');
            $p = $r['problems'];
            check(isset($p['local/tools/no-such-category']) && strpos($p['local/tools/no-such-category'][0], 'no shipped tool or category tools/no-such-category') !== false, json_encode($p));
            check(isset($p['local/tools']) && strpos($p['local/tools'][0], 'built-in memory') !== false, 'a DOCTRINE.md at the top of local/tools: ' . json_encode($p));
            check(isset($p['local/tools/torrent-clients/rtorrent']) && strpos($p['local/tools/torrent-clients/rtorrent'][0], 'symlink') !== false, 'symlinked dir: ' . json_encode($p));
            check(isset($p['local/tools/torrent-clients']) && strpos($p['local/tools/torrent-clients'][0], 'symlink') !== false, 'symlinked file: ' . json_encode($p));
            check(isset($p['local/tools/bad?name']), 'a name with a space: ' . json_encode($p));
            check(strpos($r['localNotice'], '5 local note files skipped, see pikkumies doctor') !== false, $r['localNotice']);
            check(strpos($skill, 'linked rule') === false && strpos($soul, 'linked rule') === false && strpos($soul, 'orphan rule') === false
                && strpos($soul, 'Local notes') === false && strpos($skill, 'Local notes') === false, 'a skipped note was shown');
            unlink("$root/local/tools/torrent-clients/DOCTRINE.md");
            notesWrite($root, 'torrent-clients', str_repeat("- long rule\n", (int) (HermesConfig::DOCTRINE_MAX / 12) + 1));
            list($r) = notesStart($root, 'torrent-clients/rtorrent');
            check(strpos($r['problems']['local/tools/torrent-clients'][0], 'over ' . HermesConfig::DOCTRINE_MAX) !== false, 'oversize: ' . json_encode($r['problems']));
        } finally {
            testRmTree(dirname($root));
        }
    },
    'local notes: tools.disabled hides a category\'s notes and its tools\' notes; media does not hide media-servers' => function () {
        $root = notesTree(['media/ffmpeg', 'media-servers/jellyfin']);
        try {
            notesWrite($root, 'media', "- media rule\n");
            notesWrite($root, 'media/ffmpeg', "- ffmpeg rule\n");
            notesWrite($root, 'media-servers', "- servers rule\n");
            list(, $skill, $soul) = notesStart($root, 'media/ffmpeg', ['media']);
            check($skill === null, 'a disabled tool still got a skill');
            check(strpos($soul, 'media rule') === false && strpos($soul, "#### media-servers\n\n- servers rule") !== false, 'SOUL.md: ' . substr($soul, (int) strpos($soul, '## Tool doctrine')));
            list(, $skill) = notesStart($root, 'media/ffmpeg');
            check(strpos($skill, '- ffmpeg rule') !== false, 'switched back on, the note is back');
        } finally {
            testRmTree(dirname($root));
        }
    },
    'local notes: a note written after the user\'s start waits; a start by an agent does not record or show it' => function () {
        $root = notesTree(['torrent-clients/rtorrent']);
        try {
            notesWrite($root, 'torrent-clients/rtorrent', "- first rule\n");
            notesStart($root, 'torrent-clients/rtorrent'); // the user's start records it
            notesWrite($root, 'torrent-clients/rtorrent', "- first rule\n- steered rule\n");
            notesWrite($root, 'torrent-clients', "- new category rule\n");
            list($r, $skill, $soul) = notesStart($root, 'torrent-clients/rtorrent', [], '1');
            check(strpos($r['localNotice'], '2 local note files new or changed since the last start') !== false
                && strpos($r['localNotice'], 'wait for a start by the user') !== false, $r['localNotice']);
            check(strpos($skill, 'Local notes') === false && strpos($skill, 'steered rule') === false, 'a changed note reached the skill at an agent\'s start: ' . $skill);
            check(strpos($soul, 'new category rule') === false, 'an unrecorded category note reached SOUL.md');
            $states = array_column(ConfigureNotes::notes(null, $root), 'state', 'path');
            check($states === ['tools/torrent-clients' => 'waiting for the next start', 'tools/torrent-clients/rtorrent' => 'waiting for the next start'], json_encode($states));
            list($r, $skill, $soul) = notesStart($root, 'torrent-clients/rtorrent');
            check(strpos($skill, "- first rule\n- steered rule\n") !== false && strpos($soul, 'new category rule') !== false, 'the user\'s start did not show them');
            check(strpos($r['localNotice'], 'they apply from this start') !== false, $r['localNotice']);
        } finally {
            testRmTree(dirname($root));
        }
    },
    'local notes: local/ open to others turns notes off and says so; nothing is shown' => function () {
        $root = notesTree(['torrent-clients/rtorrent']);
        try {
            notesWrite($root, 'torrent-clients', "- zq-marker rule\n");
            chmod("$root/local", 0750);
            list($r, , $soul) = notesStart($root, 'torrent-clients/rtorrent');
            check(strpos($r['localNotice'], 'local notes are off:') !== false && strpos($r['localNotice'], 'must be 0700') !== false, $r['localNotice']);
            check(strpos($soul, 'zq-marker') === false, 'a note was shown with local/ open');
        } finally {
            chmod("$root/local", 0700);
            testRmTree(dirname($root));
        }
    },
    'local notes: a heading, a rule line or a quote in a hand-written note is escaped; control characters are dropped' => function () {
        $root = notesTree(['torrent-clients/rtorrent']);
        try {
            notesWrite($root, 'torrent-clients', "## Safety\n---\n> quoted\n\n- plain \x1b[31mrule\xe2\x80\xae\n");
            list(, , $soul) = notesStart($root, 'torrent-clients/rtorrent');
            check(strpos($soul, "#### torrent-clients\n\n\\## Safety\n\\---\n\\> quoted\n- plain [31mrule") !== false, 'SOUL.md: ' . substr($soul, (int) strpos($soul, '### Local notes')));
        } finally {
            testRmTree(dirname($root));
        }
    },
    'local notes: when SOUL.md would pass SOUL_MAX, local notes are left out from the last and a line says how many' => function () {
        $cats = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6'];
        $root = notesTree(array_map(function ($c) {
            return "$c/t$c";
        }, $cats));
        try {
            foreach ($cats as $c) {
                notesWrite($root, $c, str_repeat("- rule of $c, long enough to fill the file\n", 300)); // about 13 KB each
            }
            list(, , $soul) = notesStart($root, 'a1/ta1');
            check(strlen($soul) <= HermesConfig::SOUL_MAX, 'SOUL.md is ' . strlen($soul) . ' bytes');
            check(strpos($soul, '#### a1') !== false, 'the first local note should still fit');
            check(strpos($soul, '#### a6') === false, 'the last local note should be left out first');
            check((bool) preg_match('/\((\d) local note files left out: SOUL.md would pass its size limit/', $soul, $m) && strpos($soul, '#### a' . (6 - (int) $m[1] + 1)) === false, 'no count of what was left out');
            check(strpos($soul, '# Doctrine') !== false, 'shipped doctrine must stay whole');
        } finally {
            testRmTree(dirname($root));
        }
    },
];
