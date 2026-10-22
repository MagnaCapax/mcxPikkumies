<?php
/**
 * RunFlags: the launcher's own per-run flags, read only at the start of the command line (ADR-0052).
 *
 * `pikkumies -m <model> [text]`, `--model <model>`, `--reasoning <level>` and `--resume <session id>` become Hermes chat's
 * own `-m`, `--reasoning` and `--resume` for this one session; nothing is written to any config. `--prompt-file <file>`
 * (also spelled `--query-file`, Hermes' own name) becomes `--query-file=<absolute path>`: Hermes reads the prompt from the
 * file, or from stdin for "-", so a prompt longer than one command-line argument may be (128 KiB) still reaches it. The
 * path is made absolute because Hermes changes directory for a resumed session before it reads the file. Every flag also
 * takes the "=" form. A value is required: an empty one, or one starting with "-" (except the prompt file's "-"), is an
 * error, and so is a prompt file that does not exist or words after one. A repeated flag keeps the last value. Two
 * switches take no value and never reach Hermes; they shape what a one-shot question prints (ADR-0109): `--no-progress`
 * turns the progress lines off, `--stats` prints the run's figures after the answer. Only these: any other leading word,
 * "-v" included, is prompt text exactly as before (ADR-0022), and flags that switch off safety layers are never passed
 * (ADR-0052).
 */
class RunFlags
{
    /** flag => what it sets; the "=" forms are read from these too. */
    const FLAGS = ['-m' => 'model', '--model' => 'model', '--reasoning' => 'reasoning', '--resume' => 'resume', '--prompt-file' => 'queryFile', '--query-file' => 'queryFile'];
    /** switch => what it turns on, for a one-shot question's output (ADR-0109). */
    const SWITCHES = ['--no-progress' => 'noProgress', '--stats' => 'stats'];
    /** What each kind of value is, for the error line. */
    const NEEDS = ['model' => 'a model name, for example: pikkumies %s <model> check my disk space', 'reasoning' => 'a reasoning level, for example: pikkumies %s high check my disk space',
        'resume' => 'a session id, for example: pikkumies %s <session id> what next', 'queryFile' => 'a file, or - for stdin, for example: pikkumies %s prompt.txt'];

    /** True when $word starts the launcher's flags. */
    public static function isFlag($word)
    {
        return isset(self::FLAGS[$word]) || isset(self::SWITCHES[$word]) || preg_match('/^--(model|reasoning|resume|prompt-file|query-file)=/', (string) $word) === 1;
    }

    /**
     * Leading flags -> [hermes chat args, the words after them, error, switches]. The args are, each only when given and in
     * this order: ['-m', model], ['--reasoning', level], ['--resume', id], ['--query-file=<absolute path or ->']; the error
     * is '' or one line for the person. switches: ['noProgress' => bool, 'stats' => bool]; never Hermes arguments.
     */
    public static function peel(array $words)
    {
        $set = [];
        $switches = ['noProgress' => false, 'stats' => false];
        $i = 0;
        while (isset($words[$i])) {
            $w = (string) $words[$i];
            if (isset(self::SWITCHES[$w])) {
                $switches[self::SWITCHES[$w]] = true;
                $i++;
                continue;
            }
            if (preg_match('/^(--[a-z-]+)=(.*)$/s', $w, $m) && isset(self::FLAGS[$m[1]])) {
                list($flag, $value) = [$m[1], $m[2]];
                $i++;
            } elseif (isset(self::FLAGS[$w])) {
                list($flag, $value) = [$w, isset($words[$i + 1]) ? (string) $words[$i + 1] : ''];
                $i += 2;
            } else {
                break;
            }
            $kind = self::FLAGS[$flag];
            if (trim($value) === '' || ($value[0] === '-' && !($kind === 'queryFile' && $value === '-'))) {
                return [[], [], "$flag needs " . sprintf(self::NEEDS[$kind], $flag), $switches];
            }
            if ($kind === 'queryFile' && $value !== '-') {
                // Absolute as given (a /dev/fd path from <(...) has no realpath), relative against this directory.
                $path = $value[0] === '/' ? $value : realpath($value);
                if ($path === false || !file_exists($path)) {
                    return [[], [], "$flag: no such file: $value", $switches];
                }
                $value = $path;
            }
            $set[$kind] = $value;
        }
        $rest = array_slice($words, $i);
        if (isset($set['queryFile']) && trim(implode(' ', isset($rest[0]) && $rest[0] === '--' ? array_slice($rest, 1) : $rest)) !== '') {
            return [[], [], 'give the prompt in the file or as words, not both (Hermes takes one of them)', $switches];
        }
        $args = [];
        foreach (['model' => '-m', 'reasoning' => '--reasoning', 'resume' => '--resume'] as $kind => $flag) {
            if (isset($set[$kind])) {
                array_push($args, $flag, $set[$kind]);
            }
        }
        if (isset($set['queryFile'])) {
            $args[] = '--query-file=' . $set['queryFile'];
        }
        return [$args, $rest, '', $switches];
    }

    /** The model a `chat` run was given with -m, --model or --model=, '' when none (the last one wins, as in Hermes). */
    public static function model(array $passArgs)
    {
        $model = '';
        if (!isset($passArgs[0]) || $passArgs[0] !== 'chat') {
            return '';
        }
        for ($i = 1; $i < count($passArgs); $i++) {
            $a = (string) $passArgs[$i];
            if (($a === '-m' || $a === '--model') && isset($passArgs[$i + 1])) {
                $model = (string) $passArgs[++$i];
            } elseif (strncmp($a, '--model=', 8) === 0) {
                $model = substr($a, 8);
            }
        }
        return $model;
    }
}
