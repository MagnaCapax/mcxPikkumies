<?php
/**
 * The configure verbs note-add, notes and note-remove (ADR-0076): the user's own rules for one tool or one tool
 * category, one dated line each in local/tools/<path>/DOCTRINE.md (LocalNotes reads them). A note is added at the
 * user's request without a question: it is removed again with note-remove, which prints it, and every change is
 * logged with its text in local/state/note-changes.jsonl. A note that looks like it holds a password, key or token is
 * refused: notes reach the agent's instructions on every turn.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Cli.php";
require_once "$lib/Redact.php";
require_once "$lib/LocalNotes.php";

class ConfigureNotes
{
    const LOG = 'state/note-changes.jsonl';
    const APPLIES = 'from the next start of pikkumies by the user (pikkumies doctor lists notes waiting for it); nothing changes in this session';

    /**
     * [path, kind] of what the user named: a category path (torrent-clients, email/imap), a tool as category/name, or a
     * tool's name alone; "tools/" in front and "/" at the end are ignored. Else a CliError.
     */
    public static function target($name, $root = null)
    {
        $root = $root ?: Config::root();
        $name = trim(preg_replace('#^tools/#', '', trim((string) $name)), '/');
        $scan = Tree::scan("$root/tools", 'skill.md');
        if ($name !== '' && isset($scan['categories'][$name])) {
            return [$name, 'category'];
        }
        $byName = [];
        foreach ($scan['units'] as $u) {
            $path = ltrim($u['category'] . '/' . $u['name'], '/');
            if ($path === $name) {
                return [$path, 'unit'];
            }
            $byName[$u['name']][] = $path;
        }
        if (isset($byName[$name]) && count($byName[$name]) === 1) {
            return [$byName[$name][0], 'unit'];
        }
        throw new CliError("no shipped tool or tool category named \"$name\"",
            'name a category (torrent-clients, email/imap), a tool (rtorrent) or category/tool; ' . Config::shown("$root/tools/INDEX.md") . ' lists them. A preference for every tool goes to the built-in memory instead');
    }

    /** Adds one note (a line "- <date>: <text>") for $name. Returns the record of what was written. */
    public static function add($name, $text, $root = null)
    {
        $root = $root ?: Config::root();
        list($path, $kind) = self::target($name, $root);
        $flat = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $clean = trim(Cli::cleanString($flat));
        if ($clean === '') {
            throw new CliError('the note is empty', 'php pikkumies-configure.php note-add rtorrent --text "Restart it only through the rtorrent tool"');
        }
        if (strlen($clean) > LocalNotes::NOTE_MAX) {
            throw new CliError('the note is ' . strlen($clean) . ' bytes; one note is at most ' . LocalNotes::NOTE_MAX, 'one rule per note: split it into shorter notes');
        }
        // Redact::secrets() (cleanString's URL passkeys and user:password@, plus the option, config, header and token forms)
        // writes REDACTED for every secret it finds: one more REDACTED than the text had is a secret.
        if (substr_count(Redact::secrets($flat), 'REDACTED') > substr_count($flat, 'REDACTED')) {
            throw new CliError('the note looks like it holds a password, key or token, so it was not saved: notes go into the agent\'s instructions on every turn',
                'leave the secret out: say where it is kept (a key file, the app\'s own settings), never the value; a name followed by ":" or "=" also reads as one');
        }
        $line = '- ' . gmdate('Y-m-d') . ': ' . $clean;
        $count = self::change($root, $path, 'add', function (array $entries) use ($line) {
            return [array_merge($entries, [$line]), $line];
        });
        return ['path' => "tools/$path", 'kind' => $kind, 'n' => $count, 'note' => $line, 'applies' => self::APPLIES];
    }

    /** Removes note $n (from 1, as `notes` numbers them) for $name; the record carries the removed text. */
    public static function remove($name, $n, $root = null)
    {
        $root = $root ?: Config::root();
        list($path) = self::target($name, $root);
        if (!preg_match('/^[1-9][0-9]*$/', (string) $n)) {
            throw new CliError("note number \"$n\" is not a whole number from 1", 'php pikkumies-configure.php notes ' . $path . ' lists them with their numbers');
        }
        $removed = '';
        $left = self::change($root, $path, 'remove', function (array $entries) use ($n, $path, &$removed) {
            if ((int) $n > count($entries)) {
                throw new CliError("tools/$path has " . count($entries) . ' note' . (count($entries) === 1 ? '' : 's') . ", no note $n", 'php pikkumies-configure.php notes ' . $path);
            }
            $removed = $entries[(int) $n - 1];
            array_splice($entries, (int) $n - 1, 1);
            return [$entries, $removed];
        });
        return ['path' => "tools/$path", 'removed' => $removed, 'left' => $left, 'applies' => self::APPLIES,
            'undo' => 'php pikkumies-configure.php note-add ' . $path . ' --text=' . escapeshellarg((string) preg_replace('/^- [0-9]{4}-[0-9]{2}-[0-9]{2}: /', '', $removed)) . ' adds it back, dated today'];
    }

    /**
     * One record per note (path, n, note, state), for $name or for every note file; a file that is skipped is one record
     * with its problem. state: "in use" (as recorded at the last start), or "waiting for the next start".
     */
    public static function notes($name = null, $root = null)
    {
        $root = $root ?: Config::root();
        $only = $name === null ? null : self::target($name, $root)[0];
        $scan = LocalNotes::scan($root);
        if ($scan['off'] !== '') {
            throw new CliError('local notes are off: ' . $scan['off'], 'Pikkumies keeps ' . Config::shown("$root/local") . ' at 0700; `pikkumies` resets it at the next start');
        }
        $recorded = LocalHooks::record($root);
        $out = [];
        foreach ($scan['notes'] as $path => $note) {
            if ($only !== null && $path !== $only) {
                continue;
            }
            $state = isset($recorded[$note['key']]) && $recorded[$note['key']] === $note['hash'] ? 'in use' : 'waiting for the next start';
            foreach (LocalNotes::entries($note['text']) as $i => $entry) {
                $out[] = ['path' => "tools/$path", 'kind' => $note['kind'], 'n' => $i + 1, 'note' => $entry, 'state' => $state];
            }
        }
        foreach ($scan['problems'] as $id => $why) {
            if ($only === null || $id === LocalHookCheck::cleanId("local/tools/$only")) {
                $out[] = ['path' => $id, 'problem' => $why];
            }
        }
        return $out;
    }

    /**
     * Under a lock: reads the notes of tools/$path, applies $edit (entries -> [entries, the note to log]), writes the
     * file through a temporary file (0600; removed when no note is left), logs the change. Returns the number of notes left.
     */
    private static function change($root, $path, $verb, callable $edit)
    {
        $local = "$root/local";
        $off = LocalHooks::closed($root, '');
        if ($off !== '') {
            throw new CliError($off . ', so notes there would not be read; nothing was written', 'Pikkumies keeps it at 0700; `pikkumies` resets it at the next start');
        }
        $dir = "$local/tools";
        foreach (array_merge([''], explode('/', $path)) as $seg) {
            $dir .= $seg === '' ? '' : "/$seg";
            if (is_link($dir) || (file_exists($dir) && (!is_dir($dir) || @fileowner($dir) !== LocalHooks::uid()))) {
                throw new CliError(Config::shown($dir) . ' is a symlink or not this account\'s directory; nothing was written', 'move it aside, then add the note again');
            }
            if (!is_dir($dir) && $verb !== 'add') {
                throw new CliError("tools/$path has no notes", 'php pikkumies-configure.php notes lists every note');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700)) {
                throw new CliError('cannot create ' . Config::shown($dir), 'check the disk and the permissions of ' . Config::shown($local));
            }
        }
        @mkdir("$local/state", 0700, true);
        $lock = @fopen("$local/state/notes.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new CliError('cannot take the notes lock', 'check the disk and the permissions of ' . Config::shown("$local/state"));
        }
        try {
            $file = "$dir/" . LocalNotes::FILE;
            if (is_link($file) || (file_exists($file) && (!is_file($file) || @fileowner($file) !== LocalHooks::uid()))) {
                throw new CliError(Config::shown($file) . ' is a symlink or not this account\'s file; nothing was written', 'move it aside, then add the note again');
            }
            list($entries, $note) = $edit(LocalNotes::entries(is_file($file) ? (string) file_get_contents($file) : ''));
            $content = $entries ? implode("\n", $entries) . "\n" : '';
            if (strlen($content) > HermesConfig::DOCTRINE_MAX) {
                throw new CliError('the notes for tools/' . $path . ' would pass ' . HermesConfig::DOCTRINE_MAX . ' bytes; nothing was written', 'remove old notes with note-remove first');
            }
            if ($content === '') {
                @unlink($file);
                for ($d = $dir; $d !== "$local/tools" && @rmdir($d); $d = dirname($d)) {
                    // directories left empty go, up to local/tools
                }
            } else {
                $tmp = "$file.tmp-" . getmypid();
                if (@file_put_contents($tmp, $content) !== strlen($content) || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                    @unlink($tmp);
                    throw new CliError('cannot write ' . Config::shown($file) . '; the notes are unchanged', 'check the disk space');
                }
            }
            $log = Cli::json(['time' => gmdate('c'), 'verb' => $verb, 'path' => "tools/$path", 'note' => $note]) . "\n";
            if (@file_put_contents("$local/" . self::LOG, $log, FILE_APPEND) !== false) {
                @chmod("$local/" . self::LOG, 0600);
            }
            return count($entries);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
