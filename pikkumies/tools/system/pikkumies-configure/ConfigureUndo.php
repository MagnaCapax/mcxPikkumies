<?php
/**
 * ConfigureUndo: `pikkumies configure undo`, the person's own shell command (ADR-0059). It sets back the setting the
 * last configure change replaced, read from the change log, and shows `key old -> new (tier)`. The agent can write that
 * log too, so a forged line must not make an asked change nobody saw: an undo in the asked tier (it loosens something,
 * costs credits or deletes history) is made only after the person answers y on a terminal, and refused without one. An
 * undo in the free tier (it tightens, or is harmless either way: a change the agent could make itself) is made without
 * asking (ADR-0059 amendment 2026-10-19). The person's own changes are refused (Config::set refuses them for every
 * caller anyway), and so is an undo started from inside an agent (PIKKUMIES_DEPTH 1 or more) or under a
 * PIKKUMIES_CONFIG overlay. The agent itself goes back with `set <key> <old>`.
 */
require_once __DIR__ . '/ConfigureChange.php';
require_once dirname(__DIR__, 3) . '/lib/NestedAgent.php';

class ConfigureUndo
{
    /** The exit code: 0 changed, 1 refused or declined. $read() returns a line (false at the end); $write($text) prints. */
    public static function run($tty, callable $read, callable $write, $file = null, $log = null)
    {
        $say = function ($text) use ($write) {
            call_user_func($write, $text . "\n");
        };
        if (NestedAgent::depth() >= 1) {
            $say('`pikkumies configure undo` is yours to run in your own shell, not from inside an agent; nothing was changed.');
            return 1;
        }
        if (($overlay = Config::overlayPath()) !== '') {
            $say("Not changed: a PIKKUMIES_CONFIG overlay ($overlay) is in effect, so the current values are not the ones in local/config.php. Run it without PIKKUMIES_CONFIG.");
            return 1;
        }
        $json = function ($v) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
        try {
            $config = Config::fileConfig($file);
            list($key, $value) = ConfigureChange::undoTarget($config, $log ?: ConfigureChange::logFile($file));
            $plan = ConfigureChange::plan($config, $key, $value);
            $say("undo: $key " . $json($plan['old']) . ' -> ' . $json($value) . " ({$plan['tier']})" . ($plan['why'] !== '' ? ": {$plan['why']}" : ''));
            if ($plan['tier'] === ConfigureTiers::PERSON) {
                $say('Not changed: this one is yours to change by hand: ' . ConfigureChange::how($key) . '.');
                return 1;
            }
            if ($plan['tier'] !== ConfigureTiers::FREE) {
                if (!$tty) {
                    $say('Not changed: this change needs your answer, and there is no terminal to ask on. Run `pikkumies configure undo` in a terminal.');
                    return 1;
                }
                call_user_func($write, 'This is a change you are asked about, and the change log can be written by the agent too; apply it? [y/N] ');
                if (!preg_match('/^\s*y(es)?\s*$/i', (string) call_user_func($read))) {
                    $say('Not changed.');
                    return 1;
                }
            }
            $r = ConfigureChange::apply($config, $key, $value, $file, $log);
            $say("Set $key to " . $json($r['value']) . '; it takes effect when the next session starts.');
            return 0;
        } catch (CliError $x) {
            $say('Not changed: ' . $x->getMessage() . ($x->hint !== '' ? " ({$x->hint})" : ''));
            return 1;
        }
    }
}
