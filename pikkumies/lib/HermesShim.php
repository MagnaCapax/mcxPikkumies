<?php
/**
 * HermesShim: what the `hermes` entry beside `pikkumies` in ~/.local/bin accepts (bin/hermes, ADR-0022 amendment 2026-11-23).
 *
 * Hermes prints the way to continue a finished session in its own words, `hermes --resume <id>` and `hermes -c "<title>"`,
 * with `--tui` after the name when the session ran in the terminal UI. No Hermes command is on PATH on purpose (ADR-0033),
 * so the entry answers exactly those four forms and hands them to the launcher, which sets up Hermes. Everything else
 * is refused with one line and starts nothing: the entry is never a way to reach Hermes, and never a question to the model.
 */
class HermesShim
{
    /**
     * [launcher words, ''] for one of the four printed forms, [[], one line for the person] for anything else. The id or
     * title is one word that is not empty and does not start with "-", so it can never be read as an option.
     */
    public static function route(array $words)
    {
        $tui = isset($words[0]) && $words[0] === '--tui';
        $rest = array_slice($words, $tui ? 1 : 0);
        if (count($rest) === 2 && self::valueOk($rest[1], $rest[0] === '--resume')) {
            if ($rest[0] === '--resume') {
                return [$tui ? ['run', '--tui', '--resume', $rest[1]] : ['--resume', $rest[1]], ''];
            }
            if ($rest[0] === '-c') {
                return [$tui ? ['run', '--tui', '-c', $rest[1]] : ['run', 'chat', '-c', $rest[1]], ''];
            }
        }
        return [[], 'hermes: this entry only continues a session, as Hermes prints it at the end of one. Use `pikkumies --resume <session id>`, or `pikkumies run <Hermes arguments>` for Hermes itself.'];
    }

    /** One non-empty word, not an option; a session id also has no whitespace. */
    private static function valueOk($value, $isId)
    {
        $value = (string) $value;
        return trim($value) !== '' && $value[0] !== '-' && strpos($value, "\0") === false && (!$isId || preg_match('/\s/', $value) !== 1);
    }
}
