<?php
/**
 * pikkumies-configure: show, explain and change Pikkumies' own user settings (ADR-0032). `set` writes
 * only agent.name, privacy.perfLogging, privacy.telemetry and sessions.retentionDays to
 * local/config.php; everything else, the terms above all, is the person's.
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Config.php";
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once __DIR__ . '/ConfigureSettings.php';

$usage = <<<'U'
usage: php pikkumies-configure.php <verb> [arguments] [--human]
  show                 the current settings
  get <key>            one setting
  set <key> <value>    agent.name, privacy.perfLogging, privacy.telemetry (on|off), sessions.retentionDays (0 = forever)
  review               every setting with what it means and any warning
U;

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
        return ConfigureSettings::set($args[0], $args[1]);
    },
    'review' => function () {
        return ConfigureSettings::review(Config::load(), Config::localDir() . '/hermes/state.db');
    },
], $usage);
