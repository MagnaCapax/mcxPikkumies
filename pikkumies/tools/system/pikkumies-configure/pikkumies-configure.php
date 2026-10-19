<?php
/**
 * pikkumies-configure: show, explain and change Pikkumies' own settings (ADR-0032, ADR-0059). Every change is one of
 * three tiers (ConfigureTiers): the agent makes the free ones, Hermes' approval prompt asks the person about the others
 * (the config-change hook), and the person's own are refused here with how the person makes them. note-add, notes and
 * note-remove keep the user's own rules for one tool or category (ConfigureNotes, ADR-0076).
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . '/ConfigureSettings.php';
require_once __DIR__ . '/ConfigureNotes.php';

$usage = <<<'U'
usage: php pikkumies-configure.php <verb> [arguments] [--human]
  show                    the current settings
  get <key>               one setting
  set <key> <value>       one setting; review says which changes the agent may make, which ask the person, which are theirs
  skills disable <name>   hide a shipped skill; `skills enable <name>` shows it again
  review                  every setting with what it means, which change the agent may make, and any warning
  note-add <tool or category> --text <note> | --text-file <file>
                          a rule of the user's for one tool or category, from the next start
  notes [<tool or category>]  the notes, numbered
  note-remove <tool or category> <n>  removes note n and prints it
U;

// All 23 settings in one answer (about 5 KB): the default page of 20 would hide the last ones, the person's own.
if (!preg_grep('/^--limit(=|$)/', $argv)) {
    array_splice($argv, 1, 0, ['--limit=0']);
}
Cli::run($argv, [
    'show' => function () {
        return ConfigureSettings::show(Config::load());
    },
    'get' => function ($args) {
        if (count($args) !== 1) {
            throw new CliError('get needs one key', 'php pikkumies-configure.php get privacy.telemetry');
        }
        return ConfigureSettings::get(Config::load(), $args[0]);
    },
    'set' => function ($args) {
        if (count($args) !== 2) {
            throw new CliError('set needs a key and one value (quote a name with spaces)', 'php pikkumies-configure.php set agent.name "Little Helper"');
        }
        return ConfigureSettings::set($args[0], $args[1], null, Config::fileConfig());
    },
    'skills' => function ($args) {
        if (count($args) !== 2 || !in_array($args[0], ['disable', 'enable'], true)) {
            throw new CliError('skills needs disable or enable and one skill name', 'php pikkumies-configure.php skills disable seedbox-apps');
        }
        return ConfigureChange::skills(Config::fileConfig(), $args[0], $args[1]);
    },
    'review' => function () {
        return ConfigureSettings::review(Config::load(), Config::localDir() . '/hermes/state.db');
    },
    'note-add' => function ($args, $opt) {
        $text = isset($opt['text']) ? $opt['text'] : null;
        $file = isset($opt['text-file']) ? $opt['text-file'] : null;
        if (count($args) !== 1 || ($text === null) === ($file === null) || $text === true || $file === true) {
            throw new CliError('note-add needs one tool or category and either --text or --text-file', 'php pikkumies-configure.php note-add rtorrent --text="Restart it only through the rtorrent tool"');
        }
        if ($file !== null) {
            $text = is_file($file) && is_readable($file) ? @file_get_contents($file, false, null, 0, 65537) : false;
            if ($text === false || strlen($text) > 65536) {
                throw new CliError($text === false ? 'cannot read ' . $file : $file . ' is over 64 KiB; one note is at most ' . LocalNotes::NOTE_MAX . ' bytes', 'give a readable file with one note, or the note itself with --text');
            }
        }
        return ConfigureNotes::add($args[0], $text);
    },
    'notes' => function ($args) {
        if (count($args) > 1) {
            throw new CliError('notes takes at most one tool or category', 'php pikkumies-configure.php notes rtorrent');
        }
        return ConfigureNotes::notes($args ? $args[0] : null);
    },
    'note-remove' => function ($args) {
        if (count($args) !== 2) {
            throw new CliError('note-remove needs a tool or category and the note\'s number', 'php pikkumies-configure.php notes rtorrent lists the numbers');
        }
        return ConfigureNotes::remove($args[0], $args[1]);
    },
], $usage, [], ['text', 'text-file']);
