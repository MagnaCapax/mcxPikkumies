<?php
/**
 * services: the person's other machines and what Pikkumies may do there (README.md here, ADR-0084). The list, keys and
 * pinned host keys live in local/; another box is reached only through a key its owner limited to serve.php there.
 */
$servicesLib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'ToolProc', 'HomePath', 'SshLists', 'Host', 'Http', 'SecretFile', 'SecretsCache', 'Secrets', 'Services'] as $servicesClass) {
    require_once "$servicesLib/$servicesClass.php";
}
foreach (['ServeGate', 'ServicesKeys', 'ServicesSetup', 'ServicesList', 'ServicesRemote'] as $servicesClass) {
    require_once __DIR__ . "/$servicesClass.php";
}

$usage = <<<'TXT'
usage: php services.php <verb> [arguments] [--human]
  add <nick> --host H --user U [--port N] [--provider P] [--tags a,b] [--transport pikkumies|tunnel|shell] [--home /home/u] [--notes "..."] [--clients JSON]
      [--account-auth secret:<handle>]
  list [--tag T]                      show <nick>              remove <nick>
  edit <nick> [the add options] [--add-tag t] [--remove-tag t] [--allow-delete|--no-allow-delete]
  setup <nick> [--fingerprint SHA256:...] [--allow-delete] [--allow-shell]
                                      the login comes only from PIKKUMIES_REMOTE_PASSWORD='...' before the command
  pubkey <nick> [--allow-delete]      this box's key for that service and the accept command for the other box
  accept '<public key line>' --label L [--allow-delete] [--install]   (run on the box being reached)
  test <nick> [--fingerprint SHA256:...]
  remote <nick>|--all|--tag T <category/tool> <verb> [args] [-- tool options] [--intent "<user's words>"]
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing is read from the environment.
if (isset($servicesLocalDir)) {
    Services::$localDir = $servicesLocalDir;
}
$servicesMemoryDir = isset($servicesMemoryDir) ? $servicesMemoryDir : Config::root() . '/memory';

Cli::run($argv, [
    'add' => function ($args, $opt) {
        return ServicesList::add($args, $opt);
    },
    'list' => function ($args, $opt) {
        return ServicesList::listing($opt);
    },
    'show' => function ($args, $opt) use ($servicesMemoryDir) {
        return ServicesList::show($args, $servicesMemoryDir);
    },
    'edit' => function ($args, $opt) {
        return ServicesList::edit($args, $opt);
    },
    'remove' => function ($args, $opt) {
        return ServicesList::remove($args);
    },
    'setup' => function ($args, $opt) {
        return ServicesSetup::run($args, $opt, dirname(__DIR__, 2) . '/backup/remote/askpass.php');
    },
    'pubkey' => function ($args, $opt) {
        return ServicesKeys::pubkey($args, $opt);
    },
    'accept' => function ($args, $opt) {
        return ServicesKeys::accept($args, $opt, HomePath::home());
    },
    'test' => function ($args, $opt) {
        return ServicesKeys::test($args, $opt);
    },
    'remote' => function ($args, $opt) {
        return ServicesRemote::run($args, $opt);
    },
], $usage, ['allow-shell', 'install', 'all', 'allow-delete', 'no-allow-delete'], array_merge(ServicesList::VALUED, ['fingerprint', 'label', 'intent']));
