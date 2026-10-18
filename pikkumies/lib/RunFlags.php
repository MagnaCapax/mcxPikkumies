<?php
/**
 * RunFlags: the launcher's own per-run flags, read only at the start of the command line (ADR-0052).
 *
 * `pikkumies -m <model> [text]`, `--model <model>`, `--model=<model>`, `--reasoning <level>` and
 * `--reasoning=<level>` become Hermes chat's own `-m` and `--reasoning` for this one session; nothing is
 * written to any config. A value is required: an empty one, or one starting with "-", is an error. A
 * repeated flag keeps the last value. Only these: any other leading word, "-v" included, is prompt text
 * exactly as before (ADR-0022), and flags that switch off safety layers are never passed (ADR-0052).
 */
class RunFlags
{
    /** flag => what it sets; the "=" forms are read from these too. */
    const FLAGS = ['-m' => 'model', '--model' => 'model', '--reasoning' => 'reasoning'];

    /** True when $word starts the launcher's flags. */
    public static function isFlag($word)
    {
        return isset(self::FLAGS[$word]) || preg_match('/^--(model|reasoning)=/', (string) $word) === 1;
    }

    /**
     * Leading flags -> [hermes chat args, the words after them, error]. The args are ['-m', model] and/or
     * ['--reasoning', level], in that order; the error is '' or one line for the person.
     */
    public static function peel(array $words)
    {
        $set = [];
        $i = 0;
        while (isset($words[$i])) {
            $w = (string) $words[$i];
            if (preg_match('/^(--model|--reasoning)=(.*)$/s', $w, $m)) {
                list($flag, $value) = [$m[1], $m[2]];
                $i++;
            } elseif (isset(self::FLAGS[$w])) {
                list($flag, $value) = [$w, isset($words[$i + 1]) ? (string) $words[$i + 1] : ''];
                $i += 2;
            } else {
                break;
            }
            if (trim($value) === '' || $value[0] === '-') {
                $example = self::FLAGS[$flag] === 'model' ? 'a model name, for example: pikkumies ' . $flag . ' <model> check my disk space'
                    : 'a reasoning level, for example: pikkumies ' . $flag . ' high check my disk space';
                return [[], [], "$flag needs $example"];
            }
            $set[self::FLAGS[$flag]] = $value;
        }
        $args = [];
        if (isset($set['model'])) {
            array_push($args, '-m', $set['model']);
        }
        if (isset($set['reasoning'])) {
            array_push($args, '--reasoning', $set['reasoning']);
        }
        return [$args, array_slice($words, $i), ''];
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
