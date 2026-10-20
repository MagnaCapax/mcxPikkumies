<?php
/**
 * mdraid: Linux software RAID (md) on a machine with root (ADR-0095): read the arrays and disks, build, recover,
 * scrub and tune. php mdraid.php <verb> [arguments] [--human]; verbs and examples in USAGE.md.
 */
require_once dirname(__DIR__, 3) . '/lib/Cli.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';
require_once __DIR__ . '/MdVerbs.php';

$usage = <<<'TXT'
php mdraid.php <verb> [arguments] [--human]
  status [--array mdN] [--profile P]   disks
  create --level 1|5|10|0 --disks <by-id or serial,...> --profile big-file|typical|small-file [--array mdN] [--plan] [--destroy <serial,...>] [--no-redundancy] [--accept-size-loss]
  add mdN <disk> [--destroy <serial>]   replace mdN <old> <new> [--destroy <serial>]   re-add mdN <member>
  assemble mdN [--writable]   check mdN   idle mdN   tune --profile P [--array mdN] [--plan]
TXT;
Cli::run($argv, MdVerbs::table(), $usage, MdVerbs::FLAGS, MdVerbs::OPTIONS);
