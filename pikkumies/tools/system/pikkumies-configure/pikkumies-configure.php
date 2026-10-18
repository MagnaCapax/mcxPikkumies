<?php
/**
 * pikkumies-configure: show, explain and change Pikkumies' own settings (ADR-0032, ADR-0059). Every change is one of
 * three tiers (ConfigureTiers): the agent makes the free ones, Hermes' approval prompt asks the person about the others
 * (the config-change hook), and the person's own are refused here with how the person makes them.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . '/ConfigureSettings.php';

$usage = <<<'U'
usage: php pikkumies-configure.php <verb> [arguments] [--human]
  show                    the current settings
  get <key>               one setting
  set <key> <value>       one setting; review says which changes the agent may make, which ask the person, which are theirs
  skills disable <name>   hide a shipped skill; `skills enable <name>` shows it again
  review                  every setting with what it means, which change the agent may make, and any warning
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
], $usage);
