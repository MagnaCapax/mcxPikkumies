<?php
/**
 * LocalNotes: the user's own rules for one tool or one tool category (ADR-0076), one note per line in
 * local/tools/<path>/DOCTRINE.md, where <path> is a shipped tool unit (torrent-clients/rtorrent) or category
 * (torrent-clients, email/imap). A tool's notes are appended to its generated SKILL.md; a category's go into SOUL.md
 * after the shipped tool doctrine. Never tracked, never updated; written by `pikkumies-configure note-add` or by hand.
 *
 * Read like the local hooks (ADR-0054): only while local/ is this account's and closed to others; every name on the
 * path plain, nothing on it a symlink or another account's; the file at most HermesConfig::DOCTRINE_MAX bytes. A note
 * file shows only as recorded at the last start by the user (local/state/local-changes.json, key tools/<path>), so
 * one written or edited mid-session waits for the next start. A file whose path has no shipped tool or category is
 * an orphan: reported, never shown. A note never changes or replaces shipped text; it is added after it.
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/LocalHooks.php';
require_once __DIR__ . '/LocalHookCheck.php';
require_once __DIR__ . '/HermesConfig.php';

class LocalNotes
{
    const FILE = 'DOCTRINE.md';
    const NOTE_MAX = 2000; // bytes of one note (one line); the whole file stays under HermesConfig::DOCTRINE_MAX
    /** Shown under the "Local notes" heading in SKILL.md and SOUL.md (ADR-0076). */
    const INTRO = 'Written on this machine at the user\'s request; they never override safety.md or the shipped doctrine.';

    /**
     * ['notes' => [path => ['kind' => 'unit'|'category', 'key' => 'tools/<path>', 'text' => ..., 'hash' => ...]],
     *  'problems' => [id => why], 'off' => '' or why no note is read]. $root is the pikkumies directory.
     */
    public static function scan($root = null, $toolsDir = null)
    {
        $root = $root ?: Config::root();
        $base = "$root/local/tools";
        $out = ['notes' => [], 'problems' => [], 'off' => ''];
        if (!file_exists($base) && !is_link($base)) {
            return $out;
        }
        $out['off'] = LocalHooks::closed($root, 'tools');
        if ($out['off'] !== '') {
            return $out;
        }
        $shipped = Tree::scan($toolsDir ?: "$root/tools", 'skill.md');
        $units = [];
        foreach ($shipped['units'] as $u) {
            $units[ltrim($u['category'] . '/' . $u['name'], '/')] = true;
        }
        self::walk($base, '', 1, $shipped['categories'], $units, LocalHooks::uid(), $out);
        ksort($out['notes']);
        ksort($out['problems']);
        return $out;
    }

    /**
     * The notes to show: ['units' => [unit path => text], 'categories' => [category path => text]], each text cleaned
     * by shown(). $recorded: the record (LocalHooks::record); null counts every usable note as recorded (a start by the
     * user, which records them). A category's notes show while it is on and has an enabled unit below it, as the
     * shipped DOCTRINE.md (Units::doctrines); a tool's notes go with its skill.
     */
    public static function visible(array $scan, $recorded, array $disabled, $toolsDir)
    {
        $out = ['units' => [], 'categories' => []];
        $units = null;
        foreach ($scan['notes'] as $path => $n) {
            if ($recorded !== null && (!isset($recorded[$n['key']]) || $recorded[$n['key']] !== $n['hash'])) {
                continue;
            }
            $text = self::shown($n['text']);
            if ($text === '') {
                continue;
            }
            if ($n['kind'] === 'unit') {
                $out['units'][$path] = $text;
                continue;
            }
            // Shown while an enabled unit lies below it, as the shipped DOCTRINE.md (Units::doctrines); a disabled category has none.
            $units = $units !== null ? $units : Tree::units($toolsDir, 'skill.md');
            foreach ($units as $u) {
                if (Tree::within($u['category'], $path) && !Units::isDisabled($u['category'], $disabled)) {
                    $out['categories'][$path] = $text;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Note text as it goes to the agent: valid UTF-8 without control or bidi characters (Cli::cleanString), one note per
     * line, blank lines dropped; a line that would read as a heading, a rule, a quote, a table or code is escaped with a
     * backslash, so a note cannot pose as a section of SOUL.md or of the skill.
     */
    public static function shown($text)
    {
        $lines = [];
        foreach (self::entries(Cli::cleanString((string) $text)) as $line) {
            $lines[] = preg_match('/^\s*([#>=<|`~]|-{3,}|\*{3,}|_{3,})/', $line) ? '\\' . ltrim($line) : $line;
        }
        return implode("\n", $lines);
    }

    /** The notes of a file: its non-empty lines, right-trimmed, numbered from 1 by `notes` and `note-remove`. */
    public static function entries($text)
    {
        return array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r", '', (string) $text))), function ($l) {
            return trim($l) !== '';
        }));
    }

    /** "\n## Local notes\n\n..." for a SKILL.md, or '' for no text. */
    public static function skillSection($text)
    {
        return $text === '' ? '' : "\n## Local notes\n\n" . self::INTRO . "\n\n" . $text . "\n";
    }

    /** $depth is the depth of $dir's entries below local/tools (1 = directly in it). */
    private static function walk($base, $rel, $depth, array $categories, array $units, $uid, array &$out)
    {
        $dir = $rel === '' ? $base : "$base/$rel";
        $entries = @scandir($dir);
        if ($entries === false) {
            $out['problems'][self::id($rel)] = 'the directory cannot be read';
            return;
        }
        foreach ($entries as $e) {
            $p = "$dir/$e";
            $r = $rel === '' ? $e : "$rel/$e";
            if ($e === '.' || $e === '..') {
                continue;
            }
            if ($e === self::FILE) {
                self::file($p, $rel, $categories, $units, $uid, $out);
            } elseif ($e[0] !== '.' && (is_dir($p) || is_link($p))) {
                if (!preg_match(LocalHookCheck::NAME, $e)) {
                    $out['problems'][self::id($r)] = 'a directory name has a character outside A-Z a-z 0-9 . _ -';
                } elseif (is_link($p)) {
                    $out['problems'][self::id($r)] = 'a symlinked directory is not followed';
                } elseif (@fileowner($p) !== $uid) {
                    $out['problems'][self::id($r)] = 'the directory is not this account\'s';
                } elseif ($depth > Tree::MAX_DEPTH) {
                    $out['problems'][self::id($r)] = 'deeper than any shipped tool (' . Tree::MAX_DEPTH . ' levels)';
                } else {
                    self::walk($base, $r, $depth + 1, $categories, $units, $uid, $out);
                }
            }
        }
    }

    private static function file($p, $rel, array $categories, array $units, $uid, array &$out)
    {
        $id = self::id($rel);
        $kind = isset($units[$rel]) ? 'unit' : (isset($categories[$rel]) ? 'category' : '');
        $size = is_link($p) ? 0 : (int) @filesize($p);
        if (is_link($p) || !is_file($p)) {
            $why = self::FILE . ' is a symlink or not a plain file';
        } elseif (@fileowner($p) !== $uid) {
            $why = self::FILE . ' is not this account\'s';
        } elseif ($kind === '') {
            $why = 'no shipped tool or category ' . ($rel === '' ? 'here; a preference for every tool belongs in the built-in memory' : "tools/$rel; a note sits beside an existing one (tools/INDEX.md lists them)");
        } elseif ($size > HermesConfig::DOCTRINE_MAX) {
            $why = self::FILE . " is $size bytes, over " . HermesConfig::DOCTRINE_MAX . '; remove old notes with pikkumies-configure note-remove';
        } else {
            $text = (string) @file_get_contents($p, false, null, 0, HermesConfig::DOCTRINE_MAX);
            $out['notes'][$rel] = ['kind' => $kind, 'key' => "tools/$rel", 'text' => $text, 'hash' => sha1($text)];
            return;
        }
        $out['problems'][$id] = $why;
    }

    /** A problem's id, shown on one line: local/tools/<path> in cleaned form. */
    private static function id($rel)
    {
        return LocalHookCheck::cleanId(rtrim("local/tools/$rel", '/'));
    }
}
