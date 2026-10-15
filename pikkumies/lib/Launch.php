<?php
/**
 * Launch: argv -> [subcommand, hermes passthrough args] for bin/pikkumies (ADR-0022).
 *
 * The user-facing command is `pikkumies`. No arguments, or `run` with no further words, starts a
 * plain interactive session. Anything else that is not one of the known subcommands is free text:
 * it is sent to Hermes as `chat --query="<text>"`, which seeds an interactive session on a real TTY and
 * answers-and-exits otherwise (Hermes' own behaviour, not ours). `run [hermes args]` is the
 * documented Hermes passthrough and keeps all its words. Any other subcommand wins only when every
 * word after it is a flag (`pikkumies detect --refresh`): a prompt such as "update my plex" stays a
 * prompt instead of starting a self-update. `pikkumies -- <text>` always forces a prompt.
 */
class Launch
{
    /** Subcommands bin/pikkumies dispatches on its own. */
    const SUBCOMMANDS = ['install', 'update', 'post-update', 'detect', 'doctor', 'generate', 'run', 'cron-tick', 'docs-fill'];
    const HELP = ['help', '--help', '-h'];

    /**
     * [cmd, passArgs]: cmd is one of SUBCOMMANDS or 'help'. passArgs is only meaningful for 'run':
     * [] for a plain interactive session, or ['chat', '--query=text'] to seed one prompt. $argv is the
     * script's own argv (index 0 is the script path, same shape PHP gives bin/pikkumies).
     */
    public static function route(array $argv)
    {
        if (!isset($argv[1])) {
            return ['run', []];
        }
        if (in_array($argv[1], self::HELP, true)) {
            return ['help', []];
        }
        if ($argv[1] === '--') {
            return ['run', self::promptArgs(array_slice($argv, 2))];
        }
        $rest = array_slice($argv, 2);
        // `run [hermes args]` is a documented passthrough (README, ADR-0009) and keeps every word.
        if ($argv[1] === 'run' || (in_array($argv[1], self::SUBCOMMANDS, true) && self::allFlags($rest))) {
            return [$argv[1], $rest];
        }
        return ['run', self::promptArgs(array_slice($argv, 1))];
    }

    /** True when every word is a flag (starts with "-"); an empty list counts as all flags. */
    public static function allFlags(array $words)
    {
        foreach ($words as $w) {
            if ($w === '' || $w[0] !== '-') {
                return false;
            }
        }
        return true;
    }

    /**
     * $words joined with a single space -> [] (nothing to say) or ['chat', '--query=text']. One token,
     * so a prompt that starts with "-v" or "-m" is never read by Hermes' parser as one of its flags.
     */
    public static function promptArgs(array $words)
    {
        $text = trim(implode(' ', $words));
        return $text === '' ? [] : ['chat', '--query=' . $text];
    }
}
