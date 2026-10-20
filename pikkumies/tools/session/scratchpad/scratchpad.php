<?php
/**
 * scratchpad: a per-session checkpoint note (ADR-0090, lib/Scratchpad.php). Tools carry no session id
 * of their own; --session names it explicitly, else the session local/state/scratchpad/current names
 * (written every turn by hooks/session/scratchpad-session/).
 */
$lib = dirname(__DIR__, 3) . '/lib';
require_once "$lib/Cli.php";
require_once "$lib/CliHuman.php";
require_once "$lib/Scratchpad.php";

$usage = <<<'U'
usage: php scratchpad.php <verb> [arguments] [--human]
  write  --text <task>  [--session ID]   set the pinned "## Task" head
  append --text <note>  [--session ID]   add one timestamped log line
  show                  [--session ID]   this session's pad as it stands
  clear                 [--session ID]   delete this session's pad outright
  list                                   every session's pad, newest first
U;

/** --text, with a clear usage error for every way a caller can get it wrong (missing, empty flag, stray arguments). */
function scratchpadText(array $args, array $opt, $usage)
{
    $text = isset($opt['text']) ? $opt['text'] : null;
    if ($args || $text === null || $text === true || trim((string) $text) === '') {
        throw new CliError('needs --text "<words>"', $usage);
    }
    return (string) $text;
}

/** --session, or null for "use the live session" (Scratchpad::resolveSession). */
function scratchpadSession(array $opt)
{
    if (!isset($opt['session'])) {
        return null;
    }
    if ($opt['session'] === true) {
        throw new CliError('--session needs a value', 'php scratchpad.php show --session <id>');
    }
    return (string) $opt['session'];
}

Cli::run($argv, [
    'write' => function ($args, $opt) use ($usage) {
        $text = scratchpadText($args, $opt, 'php scratchpad.php write --text "<what the task is, where it stands>"' . "\n" . $usage);
        return Scratchpad::write(Scratchpad::resolveSession(scratchpadSession($opt)), $text);
    },
    'append' => function ($args, $opt) use ($usage) {
        $text = scratchpadText($args, $opt, 'php scratchpad.php append --text "<one short checkpoint line>"' . "\n" . $usage);
        return Scratchpad::append(Scratchpad::resolveSession(scratchpadSession($opt)), $text);
    },
    'show' => function ($args, $opt) use ($usage) {
        if ($args) {
            throw new CliError('show takes no arguments', $usage);
        }
        return Scratchpad::show(Scratchpad::resolveSession(scratchpadSession($opt)));
    },
    'clear' => function ($args, $opt) use ($usage) {
        if ($args) {
            throw new CliError('clear takes no arguments', $usage);
        }
        return Scratchpad::clear(Scratchpad::resolveSession(scratchpadSession($opt)));
    },
    'list' => function ($args) use ($usage) {
        if ($args) {
            throw new CliError('list takes no arguments', $usage);
        }
        return Scratchpad::listAll();
    },
], $usage, [], ['text', 'session']);
