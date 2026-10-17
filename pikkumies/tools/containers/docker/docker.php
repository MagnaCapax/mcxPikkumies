<?php
/**
 * docker: the user's own Docker daemon and its containers (tools/containers/README.md, README.md here, ADR-0034).
 * One unit, thirteen verbs. Every verb lives in a class file beside this one and is dispatched by name:
 *   DockerOps.php       stats, list, show, logs, start, stop, restart, remove, images, prune
 *   DockerDiagnose.php  diagnose
 *   DockerLaunch.php    launch-plan, launch
 * Each verb is a static method (DockerCli $docker, array $args, array $opt) returning records, as Cli::run expects
 * (the method names are in $dockerVerbs). A verb whose class or method is not there yet answers "not built yet"
 * (exit 1) and does nothing. Loading this file calls no docker command; the daemon is contacted only by a verb.
 */
require_once __DIR__ . '/DockerCli.php';
require_once __DIR__ . '/DockerRefs.php';
require_once __DIR__ . '/DockerExposure.php';
foreach (['DockerOps', 'DockerDiagnose', 'DockerLaunch'] as $dockerPart) {
    if (is_file(__DIR__ . "/$dockerPart.php")) {
        require_once __DIR__ . "/$dockerPart.php";
    }
}

$usage = <<<'TXT'
usage: php docker.php <verb> [arguments] [--human]
  stats [--sizes]                         engine facts, space used, per-container use when it can be measured
  list [--all] [--label k=v]              containers with state, ports and who can reach each port
  show <container>                        one container's settings (env names only, never values)
  logs <container> [--lines N] [--since T]
  start|stop|restart <container>...       stop/restart wait 30 s; stopping an auto-remove container needs --expect 1
  remove <container>... --expect <n>      stopped containers only; asks the user
  images [--prune-plan [--all]]           images; --prune-plan shows what prune would remove and its --expect
  prune --expect <n> [--all]              removes what images --prune-plan listed; asks the user
  diagnose [<container> | --compose <file> | --changes]
  launch-plan --image I --name N [--count K] [--port P] [--volume SRC:DST] [--env-file F] [--public] [--accept-shared-exposure]
  launch ...                              the exact line launch-plan printed
Several ports or volumes: comma-separated. Containers: a name or 4+ hex digits of the ID, from `list`.
Output: JSON Lines; --human for people. See USAGE.md.
TXT;

/** verb => [class, static method]. */
$dockerVerbs = [
    'stats' => ['DockerOps', 'stats'],
    'list' => ['DockerOps', 'listContainers'],
    'show' => ['DockerOps', 'show'],
    'logs' => ['DockerOps', 'logs'],
    'start' => ['DockerOps', 'start'],
    'stop' => ['DockerOps', 'stop'],
    'restart' => ['DockerOps', 'restart'],
    'remove' => ['DockerOps', 'remove'],
    'images' => ['DockerOps', 'images'],
    'prune' => ['DockerOps', 'prune'],
    'diagnose' => ['DockerDiagnose', 'diagnose'],
    'launch-plan' => ['DockerLaunch', 'launchPlan'],
    'launch' => ['DockerLaunch', 'launch'],
];

// Test seams: a test may set $dockerSeams before including this file (see DockerCli). Nothing is read from the environment.
$dockerContext = isset($dockerSeams) && is_array($dockerSeams) ? $dockerSeams : [];
$dockerCli = null;
$dockerHandlers = [];
foreach ($dockerVerbs as $dockerVerb => $dockerTarget) {
    $dockerHandlers[$dockerVerb] = function ($args, $opt) use ($dockerVerb, $dockerTarget, $dockerContext, &$dockerCli) {
        if (!method_exists($dockerTarget[0], $dockerTarget[1])) {
            throw new CliError("$dockerVerb is not built yet; nothing was done", 'this pikkumies version does not have it; plain `docker` commands still work');
        }
        $dockerCli = $dockerCli !== null ? $dockerCli : new DockerCli($dockerContext);
        return call_user_func($dockerTarget, $dockerCli, $args, $opt);
    };
}

Cli::run($argv, $dockerHandlers, $usage,
    ['all', 'sizes', 'prune-plan', 'changes', 'public', 'accept-shared-exposure'],
    ['label', 'lines', 'since', 'expect', 'compose', 'image', 'name', 'count', 'port', 'volume', 'env-file', 'host-port-base']);
